<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDataSource;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Project;
use App\Services\Conversation\PythonClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Workspace-scoped (browser-facing) controller that renders Blade views
 * for managing data_sources. Mirrors the JSON logic in
 * Api\DataSourceController, but with redirect-flash UX.
 */
class DataSourceWebController extends Controller
{
    public function __construct(
        private PythonClient $python,
    ) {}

    /**
     * List all data sources for the active workspace.
     */
    public function index(Request $request, Client $client): View
    {
        $projectIds = Project::where('client_id', $client->id)->pluck('id');

        $sources = DataSource::whereIn('project_id', $projectIds)
            ->orderByDesc('id')
            ->get();

        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('data-sources.index', compact('client', 'sources', 'projects'));
    }

    /**
     * Show the "add data source" page with a card per type.
     */
    public function create(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        // First project is used for "Connect HubSpot" deep-link convenience.
        $project = $projects->first();

        return view('data-sources.create', compact('client', 'projects', 'project'));
    }

    /**
     * Create a website-URL data source. Dispatches SyncDataSource.
     */
    public function storeWebsite(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'required|string|max:255',
            'url'        => 'required|url|max:2048',
        ]);

        $project = $this->guardProject((int) $data['project_id'], $client);

        $now = time();
        $source = DataSource::create([
            'project_id' => $project->id,
            'type'       => DataSource::TYPE_WEBSITE,
            'name'       => $data['name'],
            'config'     => ['url' => $data['url']],
            'status'     => DataSource::STATUS_PENDING,
            'created_at' => $now,
            'update_at'  => $now,
        ]);

        SyncDataSource::dispatch($source->id);

        return redirect()
            ->route('data-sources.index')
            ->with('success', "Website source “{$source->name}” queued for ingestion.");
    }

    /**
     * Multi-file upload — supports both narrative documents (PDF, DOCX,
     * TXT) and structured data snapshots (CSV, JSON). The form supplies
     * a `kind` field so we can tag the resulting row as `document` or
     * `data_snapshot`; the ingest pipeline is the same either way but
     * the RAG retriever can prompt around the data differently.
     */
    public function storeDocuments(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'required|string|max:255',
            'kind'       => 'nullable|in:document,data_snapshot',
            'files'      => 'required|array|min:1',
            'files.*'    => 'file|mimes:pdf,csv,txt,docx,json|max:20480',
        ]);

        $project = $this->guardProject((int) $data['project_id'], $client);

        // Make sure the destination dir exists (Storage::putFileAs
        // doesn't always create intermediate dirs reliably on Windows).
        $destDir = storage_path("app/data_sources/project_{$project->id}");
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }

        $stored = [];
        $rejected = [];
        foreach ($request->file('files') as $file) {
            if (!$file || !$file->isValid()) {
                $name = $file ? $file->getClientOriginalName() : 'file';
                $why  = $file ? $file->getErrorMessage()       : 'upload missing';
                $rejected[] = "{$name}: {$why}";
                continue;
            }

            // Use Symfony's move() which wraps PHP's move_uploaded_file.
            // This is far more reliable on Windows than Storage::store()
            // which goes through the Filesystem adapter and can hit
            // "Path cannot be empty" errors when getRealPath() fails.
            $ext = $file->getClientOriginalExtension() ?: 'bin';
            $filename = bin2hex(random_bytes(20)) . '.' . strtolower($ext);
            try {
                $file->move($destDir, $filename);
            } catch (\Throwable $e) {
                $rejected[] = $file->getClientOriginalName() . ': ' . $e->getMessage();
                continue;
            }
            $absolute = $destDir . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($absolute)) {
                $rejected[] = $file->getClientOriginalName() . ': moved but vanished from disk';
                continue;
            }
            $stored[] = [
                'path'          => $absolute,
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getClientMimeType() ?: 'application/octet-stream',
                'size'          => filesize($absolute) ?: null,
            ];
        }

        if (empty($stored)) {
            $msg = 'No files could be uploaded.';
            if (!empty($rejected)) {
                $msg .= ' ' . implode('; ', $rejected);
            }
            $msg .= ' (Apache upload_max_filesize='
                  . ini_get('upload_max_filesize') . ', post_max_size='
                  . ini_get('post_max_size') . '.)';
            return redirect()->back()
                ->withInput()
                ->withErrors(['files' => $msg]);
        }

        $type = ($data['kind'] ?? 'document') === 'data_snapshot'
            ? DataSource::TYPE_DATA_SNAPSHOT
            : DataSource::TYPE_DOCUMENT;

        $now = time();
        $source = DataSource::create([
            'project_id' => $project->id,
            'type'       => $type,
            'name'       => $data['name'],
            'config'     => ['files' => $stored],
            'status'     => DataSource::STATUS_PENDING,
            'created_at' => $now,
            'update_at'  => $now,
        ]);

        SyncDataSource::dispatch($source->id);

        $label = $type === DataSource::TYPE_DATA_SNAPSHOT ? 'data snapshot' : 'document';
        return redirect()
            ->route('data-sources.index')
            ->with('success', count($stored)." {$label}(s) uploaded and queued for ingestion.");
    }

    /**
     * Tier A — create a live-database data source.
     *
     * On save we ALSO connect to the customer DB once and introspect
     * the schema (table → columns map). The schema is stored on the
     * source's config so DatabaseResolver can include it in the SQL-
     * generation prompt without re-running SHOW TABLES on every turn.
     *
     * Password is encrypted via Crypt facade; max_rows / timeout_sec
     * default to safe values customers can override later.
     */
    public function storeDatabase(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'required|string|max:255',
            'host'       => 'required|string|max:255',
            'port'       => 'required|integer|min:1|max:65535',
            'db_name'    => 'required|string|max:255',
            'user'       => 'required|string|max:255',
            // Optional — many local dev DBs accept empty-password
            // root logins (Laragon/MAMP/XAMPP default).
            'password'   => 'nullable|string|max:255',
        ]);

        $project = $this->guardProject((int) $data['project_id'], $client);
        $password = (string) ($data['password'] ?? '');

        // 1) Test connection + introspect schema. Bail out with the
        //    specific error if we can't even reach the DB so customers
        //    don't end up with a broken source they think is configured.
        try {
            $schema = $this->introspectDbSchema(
                $data['host'], (int) $data['port'], $data['db_name'],
                $data['user'], $password,
            );
        } catch (\Throwable $e) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['host' => 'Cannot connect to DB: ' . $e->getMessage()]);
        }

        $now = time();
        $source = DataSource::create([
            'project_id' => $project->id,
            'type'       => DataSource::TYPE_DATABASE,
            'name'       => $data['name'],
            'config'     => [
                'host'        => $data['host'],
                'port'        => (int) $data['port'],
                'name'        => $data['db_name'],
                'user'        => $data['user'],
                // Encrypted at rest; DatabaseResolver decrypts on use.
                // Empty stays empty so we don't waste a ciphertext slot.
                'password'    => $password !== ''
                    ? \Illuminate\Support\Facades\Crypt::encryptString($password)
                    : '',
                'schema'      => $schema,
                // Safety caps. Customers can edit these on the detail
                // page later. SQL generated by the LLM is wrapped to
                // respect these (LIMIT, query-timeout pragma).
                'max_rows'    => 100,
                'timeout_sec' => 8,
                // Operational stats — appended on each query.
                'queries_run' => 0,
            ],
            'status'     => DataSource::STATUS_ACTIVE,
            'created_at' => $now,
            'update_at'  => $now,
        ]);

        $tableCount = count($schema ?? []);
        return redirect()
            ->route('data-sources.show', ['id' => $source->id])
            ->with('success', "Database connected. Introspected {$tableCount} table(s).");
    }

    /**
     * Connect to a MySQL/Postgres DB and return a simplified schema:
     *   [ "orders" => ["id INT PK", "customer_email VARCHAR", ...], ... ]
     */
    private function introspectDbSchema(
        string $host, int $port, string $name, string $user, string $password
    ): array {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_TIMEOUT      => 5,
            \PDO::ATTR_ERRMODE      => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        $schema = [];
        foreach ($tables as $table) {
            $cols = $pdo->query("DESCRIBE `" . str_replace('`', '', $table) . "`")->fetchAll();
            $schema[$table] = array_map(static function (array $c): string {
                $bits = [$c['Field'] ?? '?', $c['Type'] ?? '?'];
                if (($c['Key'] ?? '') === 'PRI') $bits[] = 'PK';
                if (($c['Null'] ?? '') === 'NO')  $bits[] = 'NOT NULL';
                return implode(' ', $bits);
            }, $cols);
        }

        return $schema;
    }

    /**
     * Tier-C: register a webhook tool. The bot calls the supplied URL
     * when intent matches `when_to_use` and uses the response as data
     * for its reply. Customer keeps full control — they decide what
     * fields to expose. No DB credentials shared.
     */
    public function storeWebhook(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'  => 'required|integer',
            'name'        => 'required|string|max:120',
            'url'         => 'required|url|max:2048',
            'method'      => 'required|in:GET,POST',
            'when_to_use' => 'required|string|max:500',
            'auth_type'   => 'nullable|in:none,bearer,basic,api_key,header',
            'auth_value'  => 'nullable|string|max:1024',
            'auth_header' => 'nullable|string|max:120',
            'args_json'   => 'nullable|string|max:4000',
        ]);

        $project = $this->guardProject((int) $data['project_id'], $client);

        // Validate args_json is parseable JSON (optional field).
        $argsSchema = [];
        if (!empty($data['args_json'])) {
            $decoded = json_decode($data['args_json'], true);
            if (!is_array($decoded)) {
                return redirect()->back()
                    ->withInput()
                    ->withErrors(['args_json' => 'Arguments must be valid JSON (object).']);
            }
            $argsSchema = $decoded;
        }

        // Encrypt the auth value at rest. WebhookResolver decrypts on
        // demand. Empty values stay empty (no encrypted blob for nothing).
        $authValue = $data['auth_value'] ?? null;
        if ($authValue !== null && $authValue !== '') {
            $authValue = \Illuminate\Support\Facades\Crypt::encryptString($authValue);
        }

        $now = time();
        $source = DataSource::create([
            'project_id' => $project->id,
            'type'       => DataSource::TYPE_WEBHOOK,
            'name'       => $data['name'],
            'config'     => [
                'url'         => $data['url'],
                'method'      => $data['method'],
                'when_to_use' => $data['when_to_use'],
                'auth_type'   => $data['auth_type']   ?? 'none',
                'auth_value'  => $authValue,   // encrypted ciphertext
                'auth_header' => $data['auth_header'] ?? null,
                'args'        => $argsSchema,
            ],
            'status'     => DataSource::STATUS_ACTIVE,
            'created_at' => $now,
            'update_at'  => $now,
        ]);

        return redirect()
            ->route('data-sources.show', ['id' => $source->id])
            ->with('success', "Webhook tool “{$source->name}” registered.");
    }

    /**
     * POST /data-sources/{id}/test-webhook — fire a one-off test
     * request to the saved URL using the user-supplied test args.
     */
    public function testWebhook(Request $request, Client $client, int $id)
    {
        $source = $this->guardSource($id, $client);
        abort_unless($source->type === DataSource::TYPE_WEBHOOK, 404);

        $testArgs = $request->validate(['test_args' => 'nullable|string|max:4000']);
        $args = [];
        if (!empty($testArgs['test_args'])) {
            $decoded = json_decode($testArgs['test_args'], true);
            if (is_array($decoded)) $args = $decoded;
        }

        $cfg = $source->config ?? [];
        $url = $cfg['url'] ?? '';
        if (!$url) return response()->json(['ok' => false, 'error' => 'no url configured'], 400);

        $headers = ['Accept: application/json'];
        $authValue = \App\Services\DataSource\Resolvers\WebhookResolver::decryptAuthValue(
            $cfg['auth_value'] ?? null
        );
        switch ($cfg['auth_type'] ?? 'none') {
            case 'bearer':
                $headers[] = 'Authorization: Bearer ' . $authValue;
                break;
            case 'basic':
                $headers[] = 'Authorization: Basic ' . base64_encode($authValue);
                break;
            case 'api_key':
                $headerName = $cfg['auth_header'] ?: 'X-API-Key';
                $headers[] = "{$headerName}: " . $authValue;
                break;
            case 'header':
                if (!empty($cfg['auth_header'])) {
                    $headers[] = $cfg['auth_header'] . ': ' . $authValue;
                }
                break;
        }

        $method = strtoupper($cfg['method'] ?? 'GET');
        $ch = curl_init();
        if ($method === 'GET') {
            $sep = (str_contains($url, '?')) ? '&' : '?';
            curl_setopt($ch, CURLOPT_URL, $url . ($args ? $sep . http_build_query($args) : ''));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($args));
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return response()->json([
            'ok'          => $err === '' && $code >= 200 && $code < 400,
            'status_code' => $code,
            'error'       => $err ?: null,
            'body'        => $body,
        ]);
    }

    /**
     * POST /data-sources/{id}/test-query — run a natural-language
     * question against the saved DB and return the LLM-generated SQL
     * plus the row data. Lets admins verify Tier A works without
     * spinning up a full chat session.
     */
    public function testQuery(Request $request, Client $client, int $id)
    {
        $source = $this->guardSource($id, $client);
        abort_unless($source->type === DataSource::TYPE_DATABASE, 404);

        $data = $request->validate([
            'query' => 'required|string|max:1000',
        ]);

        $resolver = app(\App\Services\DataSource\Resolvers\DatabaseResolver::class);
        $result = $resolver->resolve($data['query'], $source, []);

        return response()->json([
            'ok'    => $result->kind === \App\Services\DataSource\ResolverResult::KIND_RECORDS,
            'kind'  => $result->kind,
            'error' => $result->error,
            'sql'   => $result->metadata['sql']         ?? null,
            'duration_ms' => $result->metadata['duration_ms'] ?? null,
            'row_count'   => count($result->items),
            'rows'  => array_slice($result->items, 0, 50),  // cap preview
        ]);
    }

    /**
     * Detail page: status + recent ingestion info via PythonClient.
     */
    public function show(Request $request, Client $client, int $id): View
    {
        $source = $this->guardSource($id, $client);

        $remote = null;
        $jobId = $source->config['last_job_id'] ?? null;
        if ($jobId) {
            try {
                $remote = $this->python->ragStatus($jobId);
            } catch (\Throwable $e) {
                // best-effort — RAG service may be down
                $remote = ['error' => $e->getMessage()];
            }
        }

        return view('data-sources.show', compact('client', 'source', 'remote'));
    }

    public function resync(Request $request, Client $client, int $id): RedirectResponse
    {
        $source = $this->guardSource($id, $client);
        SyncDataSource::dispatch($source->id);

        return redirect()
            ->route('data-sources.show', ['id' => $source->id])
            ->with('success', 'Resync queued.');
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $source = $this->guardSource($id, $client);

        $source->update([
            'status'    => DataSource::STATUS_DISABLED,
            'is_active' => 'No',
            'update_at' => time(),
        ]);

        return redirect()
            ->route('data-sources.index')
            ->with('success', "Data source “{$source->name}” disabled.");
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function guardProject(int $projectId, Client $client): Project
    {
        $project = Project::where('id', $projectId)
            ->where('client_id', $client->id)
            ->first();

        if (!$project) {
            abort(403, 'Project does not belong to this workspace.');
        }
        return $project;
    }

    private function guardSource(int $id, Client $client): DataSource
    {
        $projectIds = Project::where('client_id', $client->id)->pluck('id');

        return DataSource::where('id', $id)
            ->whereIn('project_id', $projectIds)
            ->firstOrFail();
    }
}
