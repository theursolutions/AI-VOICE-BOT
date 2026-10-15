<?php

namespace App\Http\Controllers\LeadGen;

use App\Http\Controllers\Controller;
use App\Jobs\LeadGen\RunSearch;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\LeadGen\Event;
use App\Models\LeadGen\Search;
use App\Services\LeadGen\LeadGenContext;
use App\Services\LeadGen\Providers\ImportSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Upload → map columns → import in the background (as a Search with
 * provider "import", so it shares progress, pause and cancel).
 *
 * The uploaded file is referenced by a random token held in the session,
 * never by a path the browser sends back, so a request can't point the
 * importer at another project's file or anywhere else on disk.
 */
class ImportController extends Controller
{
    public function __construct(private LeadGenContext $context) {}

    public function create(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);
        return view('leadgen.imports.create', compact('client', 'project', 'projects'));
    }

    public function upload(Request $request, Client $client): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        // No `mimes` rule: CSV MIME detection is unreliable across browsers
        // (same reason as the member import); the extension is checked
        // instead and the reader rejects anything it can't parse.
        $request->validate([
            'file'     => 'required|file|max:5120',
            'attested' => 'accepted',
        ], ['attested.accepted' => 'Please confirm you have the right to use this list.']);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['csv', 'txt', 'tsv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload a .csv or .xlsx file.']);
        }

        $token = Str::random(32);
        $path  = $file->storeAs("leadgen/imports/project_{$project->id}", $token . '.' . $ext, 'local');

        try {
            [$header, $rows] = ImportSource::readSheet($path);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'We couldn’t read that file: ' . $e->getMessage()]);
        }
        if (!$header || !$rows) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'The file has no rows below the header.']);
        }

        $request->session()->put("leadgen.import.{$token}", [
            'path' => $path, 'project_id' => $project->id, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 160),
        ]);

        return redirect()->route('leadgen.imports.map', ['client' => $client->slug, 'token' => $token, 'project_id' => $project->id]);
    }

    public function map(Request $request, Client $client, string $token): View
    {
        [$project, $projects] = $this->open($request, $client);
        $pending = $this->pending($request, $token, $project->id);
        [$header, $rows] = ImportSource::readSheet($pending['path']);

        return view('leadgen.imports.map', [
            'client'   => $client,
            'project'  => $project,
            'projects' => $projects,
            'token'    => $token,
            'header'   => $header,
            'preview'  => array_slice($rows, 0, 5),
            'rowCount' => count($rows),
            'guess'    => ImportSource::guessMapping($header),
            'fields'   => ImportSource::FIELDS,
            'countries'=> SearchController::COUNTRIES,
            'fileName' => $pending['original_name'],
            'tooMany'  => count($rows) >= ImportSource::MAX_ROWS,
        ]);
    }

    public function start(Request $request, Client $client, string $token): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $pending = $this->pending($request, $token, $project->id);
        [$header] = ImportSource::readSheet($pending['path']);
        $columns = count($header);

        $data = $request->validate([
            'mapping'               => 'required|array',
            'mapping.business_name' => 'required|integer|min:0|max:' . ($columns - 1),
            'mapping.*'             => 'nullable|integer|min:0|max:' . ($columns - 1),
            'country_code'          => 'nullable|string|size:2|alpha',
            'name'                  => 'nullable|string|max:160',
        ], ['mapping.business_name.required' => 'Choose which column holds the business name.']);

        $mapping = array_intersect_key(array_filter($data['mapping'], fn ($v) => $v !== null && $v !== ''), ImportSource::FIELDS);

        $search = Search::create([
            'name'       => ($data['name'] ?? null) ?: 'Import: ' . $pending['original_name'],
            'provider'   => ImportSource::KEY,
            'criteria'   => [
                'file'          => $pending['path'],
                'original_name' => $pending['original_name'],
                'mapping'       => array_map('intval', $mapping),
                'country_code'  => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
                'attested_by'   => $request->user()->id,
                'attested_at'   => time(),
            ],
            'status'     => Search::QUEUED,
            'progress'   => ['phase' => 'queued'],
            'created_by' => $request->user()->id,
        ]);
        $request->session()->forget("leadgen.import.{$token}");

        Event::log('import', $search->id, 'import.created', ['file' => $pending['original_name'], 'mapping' => $mapping]);
        AuditLog::record('leadgen.import.started', ['target_type' => 'lg_search', 'target_id' => $search->id,
            'payload' => ['project_id' => $project->id, 'file' => $pending['original_name']]]);

        RunSearch::dispatch($project->id, $search->id);

        return redirect()->route('leadgen.searches.show', ['client' => $client->slug, 'id' => $search->id, 'project_id' => $project->id]);
    }

    private function pending(Request $request, string $token, int $projectId): array
    {
        $pending = $request->session()->get("leadgen.import.{$token}");
        abort_unless(is_array($pending) && (int) $pending['project_id'] === $projectId
            && Storage::disk('local')->exists($pending['path']), 404);
        return $pending;
    }

    private function open(Request $request, Client $client): array
    {
        $projects = $this->context->projects($request, $client);
        $project  = $this->context->resolve($request, $client);
        abort_unless($project && LeadGenContext::tablesReady(), 404);
        return [$project, $projects];
    }
}
