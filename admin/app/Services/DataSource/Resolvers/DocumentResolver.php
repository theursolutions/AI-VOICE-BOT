<?php

namespace App\Services\DataSource\Resolvers;

use App\Models\DataSource;
use App\Services\Conversation\PythonClient;
use App\Services\DataSource\ResolverInterface;
use App\Services\DataSource\ResolverResult;
use Throwable;

class DocumentResolver implements ResolverInterface
{
    public function __construct(private PythonClient $python) {}

    public function type(): string
    {
        return DataSource::TYPE_DOCUMENT;
    }

    public function resolve(string $userQuery, DataSource $source, array $context = []): ResolverResult
    {
        try {
            $topK = (int) ($context['top_k'] ?? 5);
            $resp = $this->python->ragQuery(
                $source->project_id,
                $userQuery,
                $topK,
                [$source->id]
            );

            $passages  = $resp['passages']  ?? [];
            $citations = array_map(fn ($p) => $p['citation'] ?? [], $passages);

            if (empty($passages)) {
                return ResolverResult::empty($source->id, $source->type);
            }

            return ResolverResult::passages(
                $source->id,
                $source->type,
                $passages,
                $citations,
            );
        } catch (Throwable $e) {
            return ResolverResult::error($source->id, $source->type, $e->getMessage());
        }
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (empty($config['files']) || !is_array($config['files'])) {
            $errors['files'] = 'At least one file is required';
        }
        return $errors;
    }

    public function needsSync(): bool
    {
        return true;
    }

    public function sync(DataSource $source): void
    {
        $cfg = $source->config ?? [];

        $files = array_map(static function (array $f): array {
            return [
                'path'          => $f['path'],
                'original_name' => $f['original_name'] ?? basename($f['path']),
            ];
        }, $cfg['files'] ?? []);

        if (empty($files)) {
            $source->update([
                'status'     => DataSource::STATUS_FAILED,
                'last_error' => 'No files configured',
                'update_at'  => time(),
            ]);
            return;
        }

        $resp = $this->python->ragIngest(
            $source->project_id,
            $source->id,
            DataSource::TYPE_DOCUMENT,
            ['files' => $files],
        );

        $source->update([
            'status'         => DataSource::STATUS_ACTIVE,
            'last_synced_at' => time(),
            'update_at'      => time(),
            'config'         => array_merge($cfg, ['last_job_id' => $resp['job_id'] ?? null]),
        ]);
    }
}
