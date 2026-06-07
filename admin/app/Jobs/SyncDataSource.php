<?php

namespace App\Jobs;

use App\Models\DataSource;
use App\Services\DataSource\DataSourceRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncDataSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $dataSourceId) {}

    public function handle(DataSourceRouter $router): void
    {
        $source = DataSource::find($this->dataSourceId);
        if (!$source) {
            return;
        }

        $resolver = $router->resolverFor($source->type);
        if (!$resolver || !$resolver->needsSync()) {
            return;
        }

        $source->update(['status' => DataSource::STATUS_PENDING, 'update_at' => time()]);

        try {
            $resolver->sync($source);
        } catch (Throwable $e) {
            $source->update([
                'status'     => DataSource::STATUS_FAILED,
                'last_error' => $e->getMessage(),
                'update_at'  => time(),
            ]);
            throw $e;
        }
    }
}
