<?php

namespace App\Services\Ops;

use App\Mail\OpsAlertMail;
use App\Models\OpsNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Platform events for the team: each one lands in the super-admin
 * notification panel (the bell, one tab per kind) and is emailed to the ops
 * inbox (services.ops_alerts.email, info@serveai.com.pk by default).
 *
 * Never throws. These run inside sign-ups, payments and contact forms, and a
 * mail server hiccup must not cost us the customer's action.
 */
class OpsAlerts
{
    /** A payment that cleared, whichever gateway took it. */
    public function payment(?\App\Models\Client $client, int $amountMinor, string $currency, string $gateway,
                            ?string $what = null, ?string $reference = null, array $extra = []): void
    {
        $amount = tva_money($amountMinor, $currency);
        $who    = $client?->name ?? 'Unknown client';

        $this->record('payment', "Payment received: {$amount} from {$who}", $what,
            $reference && \Illuminate\Support\Facades\Route::has('ops.billing.charges.show') && $gateway !== 'stripe'
                ? route('ops.billing.charges.show', ['reference' => $reference])
                : route('ops.billing.charges.index', ['q' => $client?->name]),
            [
                'Client'    => $who,
                'Amount'    => $amount,
                'For'       => $what,
                'Gateway'   => ucfirst($gateway),
                'Reference' => $reference,
                'Billing email' => $client?->billing_email,
            ] + $extra);
    }

    /**
     * @param string               $kind    one of OpsNotification::KINDS
     * @param array<string, mixed> $details label => value; empty values are left out
     */
    public function record(string $kind, string $title, ?string $body = null, ?string $url = null, array $details = []): void
    {
        $details = array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v),
            array_filter($details, fn ($v) => $v !== null && $v !== '' && $v !== []));

        try {
            $notification = OpsNotification::create([
                'kind'    => $kind,
                'title'   => mb_substr($title, 0, 190),
                'body'    => $body !== null ? mb_substr($body, 0, 500) : null,
                'url'     => $url,
                // Label/value pairs, not an object: MySQL's JSON type re-sorts object keys.
                'details' => array_map(fn ($label, $value) => [$label, $value], array_keys($details), $details),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ops-alert: could not store notification', ['kind' => $kind, 'err' => $e->getMessage()]);
            $notification = null;
        }

        $to = (string) config('services.ops_alerts.email', '');
        if ($to === '' || ! config('services.ops_alerts.mail', true)) {
            return;
        }

        try {
            // Queued: the sign-up or payment request does not wait on SMTP.
            Mail::to($to)->queue(new OpsAlertMail(
                $title, $body, $details, $url,
                ($notification?->created_at ?? now())->copy()
                    ->timezone((string) config('services.ops_alerts.timezone', 'Asia/Karachi'))->format('j M Y, g:i A T'),
            ));
        } catch (\Throwable $e) {
            Log::warning('ops-alert: could not queue email', ['kind' => $kind, 'err' => $e->getMessage()]);
        }
    }
}
