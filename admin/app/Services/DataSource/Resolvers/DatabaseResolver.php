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

        // Apply admin-defined table/column ACL BEFORE the LLM sees the
        // schema. If the agent never sees a column it can't generate
        // SQL referencing it — privacy enforcement is deterministic,
        // not "trust the LLM to respect a rule in the prompt".
        $schema = app(\App\Services\DataSource\SchemaAclFilter::class)->filter($schema, $cfg);
        if (empty($schema)) {
            return ResolverResult::error($source->id, $source->type,
                'No tables are allow-listed for AI access on this data source.');
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
     * LLM options for control-plane (text-to-SQL) calls. Routes them to the
     * configured reasoning provider (e.g. groq) when set, so SQL generation
     * uses a capable model even when chat runs on a small local model.
     */
    public function llmOptions(): array
    {
        // temperature=0 → deterministic SQL (fewer wrong-column repairs, which
        // each cost a whole extra round-trip). max_tokens bounded since even a
        // complex multi-join SELECT is short — caps generation time.
        $opts = ['respond_with' => 'text', 'temperature' => 0, 'max_tokens' => 700];
        $provider = (string) config('services.python.reasoning_provider', '');
        if ($provider !== '') {
            $opts['provider'] = $provider;
        }
        $model = (string) config('services.python.reasoning_model', '');
        if ($model !== '') {
            $opts['model'] = $model;
        }
        return $opts;
    }

    /**
     * Public text-to-SQL builder reused by resolvers that execute the query
     * themselves (e.g. DataSnapshotResolver against DuckDB). Generates +
     * validates + caps a single read-only SELECT. Throws on invalid output.
     */
    public function buildSql(string $userQuery, array $schema, int $maxRows = 100): string
    {
        $sql = $this->generateSql($userQuery, $schema);
        $err = $this->validateSql($sql);
        if ($err) {
            throw new \RuntimeException($err);
        }
        return $this->ensureLimit($sql, $maxRows);
    }

    /**
     * One-shot repair for a SELECT that failed at execution: hand the DB
     * error back to the LLM, re-validate + cap. Throws if still unusable.
     */
    public function repairAndValidate(string $userQuery, array $schema, string $brokenSql, string $dbError, int $maxRows = 100): string
    {
        $fixed = $this->repairSql($userQuery, $schema, $brokenSql, $dbError);
        $err = $this->validateSql($fixed);
        if ($err) {
            throw new \RuntimeException('Query failed: ' . $dbError);
        }
        return $this->ensureLimit($fixed, $maxRows);
    }

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
                $this->llmOptions()
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
        if (strlen($schemaText) > 6000) {
            $schemaText = substr($schemaText, 0, 6000) . "\n... (schema truncated)";
        }

        // Infer likely foreign-key links (orders.customer_id → customers.id)
        // so the model writes correct JOINs for cross-table questions.
        $relHints = $this->relationshipHints($schema);
        $relBlock = $relHints !== '' ? "\n# Likely relationships (use for JOINs)\n{$relHints}\n" : '';

        $prompt = <<<PROMPT
You are an expert SQL analyst. Translate the question into ONE read-only
SQL query against the schema below. The schema may contain MULTIPLE related
tables — JOIN them whenever the answer needs data that spans tables.

# Hard rules
1. Output ONE SQL statement only — no commentary, no markdown fences.
2. Read-only ONLY: start with SELECT or WITH. Never INSERT, UPDATE, DELETE,
   DROP, ALTER, GRANT, TRUNCATE, CREATE, REPLACE, RENAME, or CALL.
3. No multiple statements (no semicolons mid-query).
4. Use ONLY tables and columns that appear in the schema. Never invent names.
5. If the question genuinely can't be answered with this schema, output the
   literal text: NO_QUERY

# Use the right tool for the question
- JOIN (INNER / LEFT) across the tables above to combine related data.
- Aggregates (COUNT, SUM, AVG, MIN, MAX) with GROUP BY / HAVING for totals,
  averages, "per <thing>", "how many ... by ...".
- Subqueries and CTEs (WITH ...) and window functions (ROW_NUMBER, RANK,
  SUM() OVER ...) for "top N per group", ranking, running totals, "the most
  recent X for each Y".
- ORDER BY + LIMIT for "top / highest / most / latest N".
- When joining, qualify columns with the table or an alias to avoid ambiguity.
- Prefer narrow SELECT lists over SELECT *. Preserve exact table names
  (including prefixes like crm_, tbl_, wp_); backtick-quote if needed.

# Few-shot examples
Q: how many leads do we have?
Schema: crm_leads(id, name, status)
SQL: SELECT COUNT(*) AS lead_count FROM crm_leads

Q: total revenue per customer, top 5
Schema: customers(id, name); orders(id, customer_id, total)
SQL: SELECT c.id, c.name, SUM(o.total) AS revenue FROM customers c JOIN orders o ON o.customer_id = c.id GROUP BY c.id, c.name ORDER BY revenue DESC LIMIT 5

Q: products never ordered
Schema: products(id, name); order_items(id, product_id, qty)
SQL: SELECT p.id, p.name FROM products p LEFT JOIN order_items oi ON oi.product_id = p.id WHERE oi.id IS NULL

Q: most recent order for each customer
Schema: customers(id, name); orders(id, customer_id, created_at, total)
SQL: WITH ranked AS (SELECT o.*, ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY created_at DESC) AS rn FROM orders o) SELECT c.name, r.id, r.total, r.created_at FROM ranked r JOIN customers c ON c.id = r.customer_id WHERE r.rn = 1

Q: how many wibbles do we have?      (no matching table)
Schema: crm_leads(...), orders(...)
SQL: NO_QUERY
{$relBlock}
# Schema (selected tables)
{$schemaText}

# Question
{$userQuery}

SQL:
PROMPT;

        $resp = $this->python->llm(
            [['role' => 'system', 'content' => $prompt]],
            $this->llmOptions()
        );

        $text = trim((string) ($resp['text'] ?? ''));
        return $this->stripFences($text);
    }

    /**
     * Heuristic foreign-key detection: a column like `customer_id` whose
     * stem ("customer") matches another table (customers / customer) is a
     * likely join key. Gives the SQL model concrete relationships to JOIN
     * on instead of guessing. Best-effort, capped.
     */
    private function relationshipHints(array $schema): string
    {
        // Map normalized base names → real table name (strip common prefixes,
        // index both singular and plural).
        $byKey = [];
        foreach (array_keys($schema) as $t) {
            $base = strtolower(preg_replace('/^(crm_|tbl_|wp_|app_|sales_|catalog_|tbl)/', '', (string) $t));
            foreach ([$base, rtrim($base, 's'), $base . 's'] as $k) {
                if ($k !== '' && !isset($byKey[$k])) $byKey[$k] = $t;
            }
        }

        $hints = [];
        foreach ($schema as $table => $columns) {
            foreach ((is_array($columns) ? $columns : []) as $c) {
                $col = strtolower(strtok((string) $c, ' '));   // drop type/keys
                if (preg_match('/^(.+?)_id$/', $col, $m)) {
                    $ref = $m[1];
                    $target = $byKey[$ref] ?? $byKey[$ref . 's'] ?? $byKey[rtrim($ref, 's')] ?? null;
                    if ($target && $target !== $table) {
                        $hints[$table . '.' . $col] = "{$table}.{$col} -> {$target}.id";
                    }
                }
            }
        }
        return implode("\n", array_slice(array_values($hints), 0, 25));
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
            $this->llmOptions()
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
        // Allow read-only SELECT or a CTE (WITH ... SELECT) — needed for
        // complex queries (ranking, "top N per group", running totals).
        if (!preg_match('/^\s*(select|with)\b/i', $trim)) {
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
        // 1) Drop markdown code fences.
        $text = preg_replace('/```sql|```/i', '', $text) ?? $text;
        // 2) Drop a leading chat-role token some small/local models echo
        //    into the completion (e.g. "assistant\nSELECT ...").
        $text = preg_replace('/^\s*(assistant|system|user)\s*[:\n]+/i', '', $text) ?? $text;
        // 3) Trim stray wrapping backticks / whitespace.
        $text = trim($text, " \t\r\n`");
        // 4) Anchor to the first real SQL keyword — discards any leading
        //    prose ("Here is the query:") or stray tokens before it.
        if (preg_match('/\b(SELECT|WITH)\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, (int) $m[0][1]);
        }
        // 5) Cut trailing commentary after the first statement terminator
        //    (small models love to append "- explanation:" after the ';').
        $semi = strpos($text, ';');
        if ($semi !== false) {
            $text = substr($text, 0, $semi);
        }
        return trim($text);
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
