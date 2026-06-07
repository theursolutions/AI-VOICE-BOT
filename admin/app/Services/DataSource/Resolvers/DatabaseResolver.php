<?php

namespace App\Services\DataSource\Resolvers;

use App\Models\DataSource;
use App\Services\Conversation\PythonClient;
use App\Services\DataSource\ResolverInterface;
use App\Services\DataSource\ResolverResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tier A — live SQL via the customer's database.
 *
 * Pipeline:
 *   1. Ask the unified Python LLM to translate the user query to SQL
 *      using the introspected schema saved on the DataSource.
 *   2. Strip code fences / commentary, reject anything that isn't a
 *      single SELECT.
 *   3. Inject a LIMIT clause if one isn't already there.
 *   4. Run against a per-source connection with a query timeout.
 *   5. Audit-log every query (user query, generated SQL, row count,
 *      duration). Updates queries_run counter on success.
 */
class DatabaseResolver implements ResolverInterface
{
    public function __construct(private PythonClient $python) {}

    public function type(): string
    {
        return DataSource::TYPE_DATABASE;
    }

    public function resolve(string $userQuery, DataSource $source, array $context = []): ResolverResult
    {
        $cfg = $source->config ?? [];

        // Required connection fields
        foreach (['host', 'name', 'user'] as $key) {
            if (empty($cfg[$key])) {
                return ResolverResult::error($source->id, $source->type, "Missing config: {$key}");
            }
        }
        $schema = $cfg['schema'] ?? null;
        if (empty($schema)) {
            return ResolverResult::error($source->id, $source->type,
                'No schema captured — re-save the data source to introspect.');
        }

        $maxRows = (int) ($cfg['max_rows']    ?? 100);
        $timeout = (int) ($cfg['timeout_sec'] ?? 8);

        // 1) Ask the LLM for SQL via the unified Python service.
        try {
            $sql = $this->generateSql($userQuery, $schema);
        } catch (Throwable $e) {
            Log::warning('DatabaseResolver: LLM SQL gen failed', [
                'source_id' => $source->id, 'error' => $e->getMessage(),
            ]);
            return ResolverResult::error($source->id, $source->type, 'LLM error: ' . $e->getMessage());
        }

        // 2) Strict safety — only single SELECT, no semicolons, no DML keywords.
        $validationError = $this->validateSql($sql);
        if ($validationError) {
            Log::warning('DatabaseResolver: rejected SQL', [
                'source_id' => $source->id, 'sql' => $sql, 'reason' => $validationError,
            ]);
            return ResolverResult::error($source->id, $source->type, $validationError);
        }

        // 3) Force a LIMIT clause so the LLM can't accidentally pull the world.
        $sql = $this->ensureLimit($sql, $maxRows);

        // 4) Per-source connection. Decrypt password transparently.
        $password = self::decryptPassword($cfg['password'] ?? null);
        $connection = "ds_db_{$source->id}";
        config(["database.connections.{$connection}" => [
            'driver'    => $cfg['type'] ?? 'mysql',
            'host'      => $cfg['host'],
            'port'      => $cfg['port'] ?? 3306,
            'database'  => $cfg['name'],
            'username'  => $cfg['user'],
            'password'  => $password,
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => false,
            'options'   => [
                \PDO::ATTR_TIMEOUT => $timeout,
            ],
        ]]);
        DB::purge($connection);

        $start = microtime(true);
        $rows = null;
        $repairAttempted = false;
        try {
            $rows = DB::connection($connection)->select($sql);
        } catch (Throwable $e) {
            // Self-correction loop: hand the DB error back to the LLM
            // once and let it pick a different column / table. Bounded
            // at one retry to keep cost + latency predictable.
            Log::info('DatabaseResolver: query failed, attempting repair', [
                'source_id' => $source->id, 'sql' => $sql, 'error' => $e->getMessage(),
            ]);
            try {
                $repaired = $this->repairSql($userQuery, $schema, $sql, $e->getMessage());
                $repairAttempted = true;
                $repairErr = $this->validateSql($repaired);
                if ($repairErr) {
                    Log::warning('DatabaseResolver: repaired SQL rejected', [
                        'source_id' => $source->id, 'sql' => $repaired, 'reason' => $repairErr,
                    ]);
                    return ResolverResult::error($source->id, $source->type, 'Query failed: ' . $e->getMessage());
                }
                $repaired = $this->ensureLimit($repaired, $maxRows);
                $rows = DB::connection($connection)->select($repaired);
                $sql  = $repaired;   // remember the working version in audit log
                Log::info('DatabaseResolver: query repaired ok', [
                    'source_id' => $source->id, 'sql' => $sql,
                ]);
            } catch (Throwable $e2) {
                Log::warning('DatabaseResolver: repair attempt also failed', [
                    'source_id' => $source->id, 'error' => $e2->getMessage(),
                ]);
                return ResolverResult::error($source->id, $source->type,
                    'Query failed: ' . $e->getMessage());
            }
        }
        $durationMs = (int) ((microtime(true) - $start) * 1000);

        // 5) Audit log + counter bump.
        Log::info('DatabaseResolver: query ok', [
            'source_id'    => $source->id,
            'project_id'   => $source->project_id,
            'user_query'   => substr($userQuery, 0, 200),
            'generated_sql'=> $sql,
            'rows'         => count($rows),
            'duration_ms'  => $durationMs,
        ]);
        try {
            $source->config = array_merge($cfg, [
                'queries_run'   => ((int) ($cfg['queries_run'] ?? 0)) + 1,
                'last_query_at' => time(),
                'last_query_ms' => $durationMs,
            ]);
            $source->save();
        } catch (Throwable $e) {
            // Don't fail the user's turn just because we couldn't bump a counter.
        }

        return ResolverResult::records(
            $source->id,
            $source->type,
            array_map(static fn ($r) => (array) $r, $rows),
            [
                'sql'              => $sql,
                'duration_ms'      => $durationMs,
                'repair_attempted' => $repairAttempted,
            ],
        );
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        foreach (['host', 'name', 'user'] as $f) {
            if (empty($config[$f])) $errors[$f] = "{$f} is required";
        }
        return $errors;
    }

    public function needsSync(): bool { return false; }
    public function sync(DataSource $source): void {}

    /**
     * Two-step LLM pipeline:
     *
     *   Step 1: send all TABLE NAMES + the question to the LLM and ask
     *           which tables are relevant. Small prompt (just names).
     *   Step 2: send the FULL schemas of the picked tables to the LLM
     *           and ask for the SQL. Tight prompt regardless of how
     *           large the overall schema is.
     *
     * If the schema is small (≤15 tables) we skip step 1 and send the
     * lot — saves a roundtrip when there's no risk of blowing the
     * token budget. Keyword scoring is kept as a last-ditch fallback
     * when the LLM picker fails entirely.
     */
    private function generateSql(string $userQuery, array $schema): string
    {
        // Fast path: small schemas go straight to SQL generation.
        if (count($schema) <= 15) {
            return $this->generateSqlForTables($userQuery, $schema);
        }

        // Step 1 — let the LLM pick which tables to inspect. We pass
        // the whole schema (names + column previews) so the picker
        // can resolve naming conventions like "crm_leads".
        $picked = $this->llmPickTables($userQuery, $schema);

        // Filter the schema to the picked tables (case-insensitive match
        // so `Lead` vs `lead` doesn't tank the lookup).
        $byLower = [];
        foreach ($schema as $t => $cols) {
            $byLower[strtolower($t)] = ['name' => $t, 'cols' => $cols];
        }
        $selected = [];
        foreach ($picked as $name) {
            $key = strtolower((string) $name);
            if (isset($byLower[$key])) {
                $selected[$byLower[$key]['name']] = $byLower[$key]['cols'];
            }
        }

        // Fallback 1: LLM picker returned junk → keyword scoring.
        if (empty($selected)) {
            Log::info('DatabaseResolver: LLM picker returned no usable tables, falling back to keyword scoring', [
                'picked' => $picked,
            ]);
            $selected = $this->pickRelevantTables($userQuery, $schema, 12);
        }

        // Fallback 2: still nothing → first 12 alphabetical (defensive).
        if (empty($selected)) {
            $names = array_keys($schema);
            sort($names);
            $selected = array_intersect_key($schema, array_flip(array_slice($names, 0, 12)));
        }

        return $this->generateSqlForTables($userQuery, $selected);
    }

    /**
     * Ask the LLM "which of these tables can answer the question?"
     *
     * We send TABLE NAMES + a column PREVIEW (first ~6 column names,
     * stripped of types) so the model can semantically map vague
     * user terms onto prefixed table names — e.g. "leads" → "crm_leads",
     * "users" → "wp_users", "orders" → "tbl_orders_v2". Names alone
     * aren't enough when conventions like crm_*, tbl_*, prefix_* hide
     * the real entity.
     */
    private function llmPickTables(string $userQuery, array $schema): array
    {
        $blocks = [];
        foreach ($schema as $table => $columns) {
            $cols = is_array($columns) ? $columns : [];
            // Strip types/keys for the preview — model only needs names.
            $previewCols = array_slice(array_map(static function ($c) {
                $parts = explode(' ', (string) $c);
                return $parts[0] ?? '';
            }, $cols), 0, 6);
            $hint = empty($previewCols) ? '' : '  cols: ' . implode(', ', $previewCols);
            $blocks[] = "- {$table}\n{$hint}";
        }
        $tableBlock = implode("\n", $blocks);

        // Hard cap to keep the picker call under Groq's TPM. Schemas
        // with 100+ tables get truncated alphabetically.
        if (strlen($tableBlock) > 6000) {
            $tableBlock = substr($tableBlock, 0, 6000) . "\n... (table list truncated)";
        }

        $prompt = <<<PROMPT
You are an expert text-to-SQL agent picking which database tables can
answer a user's question.

Real-world DB schemas use naming prefixes and conventions. ALWAYS map
the user's plain-English entities onto the actual table names using
both the table name AND the preview columns:

  user says "leads"     → match crm_leads, leads, tbl_lead, sales_leads
  user says "users"     → match wp_users, users, app_user, account
  user says "orders"    → match tbl_orders_v2, sale_order, purchase
  user says "products"  → match catalog_product, products, items, sku
  user says "customers" → match crm_contact, contacts, client, buyer
  user says "tickets"   → match support_ticket, helpdesk_case, issue

# Tables in this database (with column previews)
{$tableBlock}

# Question
{$userQuery}

# Task
Return a JSON object on a single line:
{"tables": ["actual_table_name_1", "actual_table_name_2", ...]}

Rules:
- Use the EXACT table names from the list above (preserve any prefix).
- Include every table that could plausibly be needed for the question —
  a couple is fine; better to include a related lookup table than
  miss one.
- Match semantically, NOT by literal substring. If the question asks
  about "leads" and the only matching table is "crm_leads", pick that.
- If the schema clearly can't answer this question, return {"tables": []}.
- Output ONLY the JSON object. No prose, no markdown fences.
PROMPT;

        try {
            $resp = $this->python->llm(
                [['role' => 'system', 'content' => $prompt]],
                ['respond_with' => 'text']
            );
        } catch (Throwable $e) {
            Log::warning('DatabaseResolver: table-picker call failed', ['error' => $e->getMessage()]);
            return [];
        }

        $text = trim((string) ($resp['text'] ?? ''));
        if ($text === '') return [];
        if (!preg_match('/\{.*\}/s', $text, $m)) return [];
        $decoded = json_decode($m[0], true);
        if (!is_array($decoded) || !isset($decoded['tables']) || !is_array($decoded['tables'])) {
            return [];
        }
        return array_slice(array_values($decoded['tables']), 0, 15);
    }

    /**
     * Final SQL generation step — strict prompt with the selected
     * tables' full schemas.
     */
    private function generateSqlForTables(string $userQuery, array $schema): string
    {
        $schemaLines = [];
        foreach ($schema as $table => $columns) {
            $cols = is_array($columns) ? implode(', ', $columns) : (string) $columns;
            $schemaLines[] = "- {$table} ({$cols})";
        }
        $schemaText = implode("\n", $schemaLines);
        if (strlen($schemaText) > 3500) {
            $schemaText = substr($schemaText, 0, 3500) . "\n... (schema truncated)";
        }

        $prompt = <<<PROMPT
You translate a natural-language question into a SINGLE read-only SQL
query against the schema below.

# Hard rules

1. Output ONE SQL statement only — no commentary, no markdown fences.
2. SELECT statements ONLY. Never INSERT, UPDATE, DELETE, DROP, ALTER,
   GRANT, TRUNCATE, CREATE, REPLACE, RENAME, or CALL.
3. No multiple statements (no semicolons mid-query).
4. Use ONLY tables and columns that appear in the schema below. Do
   NOT invent column names — if you're unsure a column exists, prefer
   a column you DID see in the schema or use COUNT(*) / SELECT 1.
5. If the question genuinely can't be answered with this schema,
   output the literal text: NO_QUERY
6. Prefer narrow SELECT lists over `SELECT *` so the response is small.
7. Use the table names EXACTLY as they appear (preserve prefixes like
   `crm_`, `tbl_`, `wp_`). Quote with backticks if the name needs it.
8. For "how many X" use COUNT(*), not SELECT *.
9. For "list / show me" use a reasonable SELECT of identifying columns
   only — id + name + maybe one or two more.

# Few-shot examples

Q: how many leads do we have?
Schema: crm_leads(id, name, email, status, created_at)
SQL: SELECT COUNT(*) AS lead_count FROM crm_leads

Q: list 5 most recent orders
Schema: tbl_orders_v2(id, customer_id, total, created_at)
SQL: SELECT id, customer_id, total, created_at FROM tbl_orders_v2 ORDER BY created_at DESC LIMIT 5

Q: top 3 products by price
Schema: catalog_product(sku, name, price, stock)
SQL: SELECT sku, name, price FROM catalog_product ORDER BY price DESC LIMIT 3

Q: customers in New York
Schema: crm_contact(id, full_name, city, email)
SQL: SELECT id, full_name, email FROM crm_contact WHERE city = 'New York'

Q: how many wibbles do we have?      (no matching table)
Schema: crm_leads(...), tbl_orders(...)
SQL: NO_QUERY

# Schema (selected tables)
{$schemaText}

# Question
{$userQuery}

SQL:
PROMPT;

        $resp = $this->python->llm(
            [['role' => 'system', 'content' => $prompt]],
            ['respond_with' => 'text']
        );

        $text = trim((string) ($resp['text'] ?? ''));
        return $this->stripFences($text);
    }

    /**
     * Self-correction: when the first SQL execution fails on the DB
     * (column not found, syntax error, etc.), give the LLM ONE chance
     * to fix it using the actual error message. Caps at one retry to
     * keep cost + latency bounded.
     */
    private function repairSql(string $userQuery, array $schema, string $brokenSql, string $dbError): string
    {
        $schemaLines = [];
        foreach ($schema as $table => $columns) {
            $cols = is_array($columns) ? implode(', ', $columns) : (string) $columns;
            $schemaLines[] = "- {$table} ({$cols})";
        }
        $schemaText = implode("\n", $schemaLines);
        if (strlen($schemaText) > 3500) {
            $schemaText = substr($schemaText, 0, 3500) . "\n... (schema truncated)";
        }

        $prompt = <<<PROMPT
The SQL you generated for the question below FAILED when run on the
database. Fix it using ONLY columns that appear in the schema.

# Original question
{$userQuery}

# Broken SQL
{$brokenSql}

# Database error
{$dbError}

# Schema
{$schemaText}

# Rules
- SELECT-only. No DDL or DML.
- One statement, no semicolons.
- Use only the columns/tables in the schema. If the error is "unknown
  column 'foo'", pick the closest existing column instead.
- If the question cannot be answered from this schema, output NO_QUERY.
- Output ONLY the corrected SQL. No commentary, no markdown.

Corrected SQL:
PROMPT;

        $resp = $this->python->llm(
            [['role' => 'system', 'content' => $prompt]],
            ['respond_with' => 'text']
        );
        return $this->stripFences(trim((string) ($resp['text'] ?? '')));
    }

    /**
     * Naive keyword-overlap scorer. For each table, count how many
     * query words appear in the table name or column names. Returns
     * the top-K tables (preserving original column lists).
     */
    private function pickRelevantTables(string $userQuery, array $schema, int $limit): array
    {
        if (count($schema) <= $limit) return $schema;

        // Tokenise the query: lowercase alnum words, length ≥ 3,
        // minus common English stopwords that don't help match tables.
        $stop = ['the','a','an','of','to','in','on','for','and','or','is','are','was','were',
                 'be','been','being','have','has','had','do','does','did','will','would','can',
                 'could','should','may','might','what','which','who','whom','this','that','these',
                 'those','my','our','their','your','his','her','its','any','all','show','list',
                 'me','us','give','find','get','tell','many','much','count','from','with','about','please'];
        preg_match_all('/[a-z0-9]{3,}/i', strtolower($userQuery), $m);
        $words = array_values(array_diff(array_unique($m[0] ?? []), $stop));

        // Expand each word with simple stem variants so plural/singular
        // mismatches don't cause zero overlap:
        //   "leads"     → ["leads", "lead"]
        //   "categories"→ ["categories", "category", "categori"]
        //   "user"      → ["user", "users"]
        // Match if ANY variant appears as a substring of the table or
        // column names (case-insensitive).
        $variants = [];
        foreach ($words as $w) {
            $set = [$w];
            if (str_ends_with($w, 'ies') && strlen($w) > 4) {
                $set[] = substr($w, 0, -3) . 'y';                 // categories → category
                $set[] = substr($w, 0, -3);                       // → categori
            } elseif (str_ends_with($w, 'es') && strlen($w) > 4) {
                $set[] = substr($w, 0, -2);                       // boxes → box
                $set[] = substr($w, 0, -1);                       // → boxe
            } elseif (str_ends_with($w, 's') && strlen($w) > 3) {
                $set[] = substr($w, 0, -1);                       // leads → lead
            } else {
                $set[] = $w . 's';                                // user → users
            }
            $variants[$w] = array_values(array_unique($set));
        }

        // Score each table — one point per query word matched (any variant counts).
        $scores = [];
        foreach ($schema as $table => $columns) {
            $haystack = strtolower($table . ' ' . (is_array($columns) ? implode(' ', $columns) : (string) $columns));
            $score = 0;
            foreach ($variants as $forms) {
                foreach ($forms as $v) {
                    if (str_contains($haystack, $v)) { $score++; break; }
                }
            }
            $scores[$table] = $score;
        }
        arsort($scores);

        $top = array_slice($scores, 0, $limit, true);

        // Fallback: if NO table scored above zero (query has no overlap
        // with any name), just return the first K alphabetical tables.
        if (max($top) === 0) {
            $names = array_keys($schema);
            sort($names);
            $top = array_fill_keys(array_slice($names, 0, $limit), 0);
        }

        return array_intersect_key($schema, $top);
    }

    /** Returns an error string if SQL is unsafe, null if OK. */
    private function validateSql(string $sql): ?string
    {
        $trim = trim($sql, " \t\r\n;");
        if ($trim === '' || strtoupper($trim) === 'NO_QUERY') {
            return 'No SQL could be generated for that question';
        }
        // Reject multi-statement payloads.
        if (substr_count($sql, ';') > 1 || (substr_count($sql, ';') === 1 && !str_ends_with(rtrim($sql), ';'))) {
            return 'Multiple SQL statements not allowed';
        }
        if (!preg_match('/^\s*select\b/i', $trim)) {
            return 'Generated SQL was not a SELECT';
        }
        // Forbidden keywords (case-insensitive word boundary).
        $forbidden = ['INSERT','UPDATE','DELETE','DROP','ALTER','TRUNCATE','GRANT','REVOKE','CREATE','REPLACE','RENAME','CALL'];
        foreach ($forbidden as $kw) {
            if (preg_match("/\b{$kw}\b/i", $trim)) {
                return "Forbidden SQL keyword: {$kw}";
            }
        }
        return null;
    }

    /** Add a LIMIT clause if the SELECT doesn't already cap rows. */
    private function ensureLimit(string $sql, int $maxRows): string
    {
        $sql = rtrim($sql, " \t\r\n;");
        if (!preg_match('/\blimit\s+\d+/i', $sql)) {
            $sql .= " LIMIT {$maxRows}";
        }
        return $sql;
    }

    private function stripFences(string $text): string
    {
        return trim(preg_replace('/```sql|```/i', '', $text) ?? $text);
    }

    /** Same fallback pattern as WebhookResolver: legacy plaintext rows still work. */
    public static function decryptPassword(?string $stored): string
    {
        if ($stored === null || $stored === '') return '';
        try {
            return Crypt::decryptString($stored);
        } catch (Throwable $e) {
            return $stored;  // legacy unencrypted row
        }
    }
}
