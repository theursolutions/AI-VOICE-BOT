<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\OAuthService;

/**
 * Watches every connected WhatsApp number's quality rating.
 *
 * Meta grades each number GREEN → YELLOW → RED based on how recipients
 * react. Red means reduced messaging limits, and a number left red long
 * enough gets restricted or suspended.
 *
 * The important thing about that sequence is that it is SLOW and VISIBLE.
 * Nobody wakes up to a banned number — they wake up to a number that has
 * been yellow for a fortnight and nobody looked. This command is the looking.
 *
 * Runs daily. Logs a warning on anything below green so it surfaces wherever
 * logs are watched, rather than only when someone runs it by hand.
 */
class MetaQualityCheck extends Command
{
    protected $signature = 'meta:quality-check {--project= : Only this project id}';

    protected $description = 'Report the WhatsApp quality rating and messaging limit for every connected number';

    public function __construct(private OAuthService $oauth) { parent::__construct(); }

    public function handle(): int
    {
        $numbers = ChannelConnection::query()
            ->where('provider', ChannelConnection::PROVIDER_WHATSAPP)
            ->when($this->option('project'), fn ($q) => $q->where('project_id', (int) $this->option('project')))
            ->enabled()
            ->get();

        if ($numbers->isEmpty()) {
            $this->line('No WhatsApp numbers connected.');
            return self::SUCCESS;
        }

        $degraded = 0;

        foreach ($numbers as $c) {
            $label = ($c->metadata['display_phone_number'] ?? $c->external_id)
                   . ' (project ' . $c->project_id . ')';

            if (! $c->access_token) {
                $this->warn("  ⚠ {$label} — no token stored; reconnect this channel");
                continue;
            }

            try {
                $data = $this->oauth->whatsappNumberDetails((string) $c->external_id, $c->access_token);
            } catch (\Throwable $e) {
                $this->error("  ✗ {$label} — could not read status: " . $e->getMessage());
                continue;
            }

            $rating = strtoupper((string) ($data['quality_rating'] ?? 'UNKNOWN'));

            // Keep the last reading so a trend is visible, not just a value.
            $c->metadata = array_merge((array) $c->metadata, [
                'quality_rating'    => $rating,
                'quality_checked_at' => time(),
            ]);
            $c->save();

            match ($rating) {
                'GREEN' => $this->info("  ok     {$label} — quality GREEN"),
                'YELLOW', 'RED' => $this->reportDegraded($c, $label, $rating, $degraded),
                default => $this->line("  ?      {$label} — quality {$rating}"),
            };
        }

        $this->newLine();

        if ($degraded > 0) {
            $this->warn("{$degraded} number(s) below green.");
            $this->line('Quality is driven by recipients blocking or reporting. Check:');
            $this->line('  · Is the bot answering usefully, or looping? (see ComplianceGuard::BOT_TURN_LIMIT)');
            $this->line('  · Are opt-outs being honoured?   php artisan tinker → sessions with meta.opted_out');
            $this->line('  · Any business-initiated sends without opt-in?');

            return self::FAILURE;
        }

        $this->info('All numbers green.');

        return self::SUCCESS;
    }

    private function reportDegraded(ChannelConnection $c, string $label, string $rating, int &$degraded): void
    {
        $degraded++;
        $this->warn("  ⚠ {$label} — quality {$rating}");

        // Logged as well as printed: a scheduled run nobody watches is only
        // useful if it leaves a trace where alerts are already looked at.
        Log::warning('WhatsApp quality rating degraded', [
            'project'    => $c->project_id,
            'number'     => $c->metadata['display_phone_number'] ?? $c->external_id,
            'rating'     => $rating,
        ]);
    }
}
