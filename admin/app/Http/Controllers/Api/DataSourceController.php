<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDataSource;
use App\Models\DataSource;
use App\Services\Conversation\PythonClient;
use App\Services\DataSource\DataSourceRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DataSourceController extends Controller
{
    public function __construct(
        private DataSourceRouter $router,
        private PythonClient $python,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $project = $request->attributes->get('project');

        $sources = DataSource::where('project_id', $project->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $sources]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $request->attributes->get('project');

        $data = $request->validate([
            'type'   => 'required|in:website,document,crm_oauth,database,agent',
            'name'   => 'required|string|max:255',
            'config' => 'required|array',
        ]);

        $resolver = $this->router->resolverFor($data['type']);
        if (!$resolver) {
            throw ValidationException::withMessages(['type' => 'Unknown source type']);
        }

        $errors = $resolver->validateConfig($data['config']);
        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $now = time();
        $source = DataSource::create([
            'project_id' => $project->id,
            'type'       => $data['type'],
            'name'       => $data['name'],
            'config'     => $data['config'],
            'status'     => $resolver->needsSync()
                ? DataSource::STATUS_PENDING
                : DataSource::STATUS_ACTIVE,
            'created_at' => $now,
            'update_at'  => $now,
        ]);

        if ($resolver->needsSync()) {
            SyncDataSource::dispatch($source->id);
        }

        return response()->json($source, 201);
    }

    public function uploadDocuments(Request $request): JsonResponse
    {
        $project = $request->attributes->get('project');

        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'files'   => 'required|array|min:1',
            'files.*' => 'file|mimes:pdf,csv,txt,docx|max:20480',
        ]);

        $stored = [];
        foreach ($request->file('files') as $file) {
            $relative = $file->store("data_sources/project_{$project->id}", 'local');
            $absolute = storage_path('app/'.$relative);
            $stored[] = [
                'path'          => $absolute,
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getClientMimeType(),
                'size'          => $file->getSize(),
            ];
        }

        $now = time();
        $source = DataSource::create([
            'project_id' => $project->id,
            'type'       => DataSource::TYPE_DOCUMENT,
            'name'       => $data['name'],
            'config'     => ['files' => $stored],
            'status'     => DataSource::STATUS_PENDING,
            'created_at' => $now,
            'update_at'  => $now,
        ]);

        SyncDataSource::dispatch($source->id);

        return response()->json($source, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('project');

        $source = DataSource::where('id', $id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        return response()->json($source);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('project');

        $source = DataSource::where('id', $id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $jobId = $source->config['last_job_id'] ?? null;
        $remote = $jobId ? $this->python->ragStatus($jobId) : null;

        return response()->json([
            'source'  => $source,
            'ingest'  => $remote,
        ]);
    }

    public function resync(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('project');

        $source = DataSource::where('id', $id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        SyncDataSource::dispatch($source->id);

        return response()->json(['queued' => true, 'source_id' => $source->id]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('project');

        $source = DataSource::where('id', $id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $source->update([
            'status'     => DataSource::STATUS_DISABLED,
            'is_active'  => 'No',
            'update_at'  => time(),
        ]);

        return response()->json(['disabled' => true]);
    }
}
