<?php

namespace App\Services\DataSource\Resolvers;

use App\Models\DataSource;
use App\Services\Conversation\PythonClient;
use App\Services\DataSource\ResolverInterface;
use App\Services\DataSource\ResolverResult;
use Throwable;

class WebsiteResolver implements ResolverInterface
{
    public function __construct(private PythonClient $python) {}

    public function type(): string
    {
        return DataSource::TYPE_WEBSITE;
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
        if (empty($config['url'])) {
            $errors['url'] = 'Website URL is required';
        } elseif (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
            $errors['url'] = 'Invalid URL';
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

        $resp = $this->python->ragIngest(
            $source->project_id,
            $source->id,
            DataSource::TYPE_WEBSITE,
            [
                'url'       => $cfg['url'],
                'max_depth' => $cfg['max_depth'] ?? 2,
            ],
        );

        $source->update([
            'status'         => DataSource::STATUS_ACTIVE,
            'last_synced_at' => time(),
            'update_at'      => time(),
            'config'         => array_merge($cfg, ['last_job_id' => $resp['job_id'] ?? null]),
        ]);
    }
}
