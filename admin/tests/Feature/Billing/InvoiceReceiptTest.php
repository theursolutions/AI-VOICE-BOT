<?php

namespace Tests\Feature\Billing;

use App\Mail\InvoicePaidMail;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\Mail;

/**
 * The receipt email sent when Stripe confirms an invoice is paid.
 *
 * Two things are load-bearing here: it goes out exactly once per payment, and
 * it can never take the webhook down with it — the subscription sync in the
 * same handler matters more than the email.
 */
class InvoiceReceiptTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /** An `invoices->retrieve()` double carrying tax. */
    private function invoiceService(array $overrides = []): object
    {
        $invoice = array_merge([
            'id'          => 'in_test_1',
            'object'      => 'invoice',
            'number'      => 'B1F4C2A9-0007',
            'customer'    => 'cus_test_1',
            'status'      => 'paid',
            'currency'    => 'usd',
            'subtotal'    => 5900,
            'total'       => 6903,
            'amount_paid' => 6903,
            'created'     => now()->getTimestamp(),
            'period_start'=> now()->getTimestamp(),
            'period_end'  => now()->addMonth()->getTimestamp(),
            'invoice_pdf' => 'https://pay.stripe.test/invoice.pdf',
            'customer_email' => 'owner@acme.test',
            'status_transitions' => ['paid_at' => now()->getTimestamp()],
            'lines' => ['data' => [[
                'description' => 'Growth — monthly',
                'quantity'    => 1,
                'amount'      => 5900,
                'period'      => ['start' => now()->getTimestamp(), 'end' => now()->addMonth()->getTimestamp()],
            ]]],
            // Modern tax shape.
            'total_taxes' => [[
                'amount'            => 1003,
                'tax_behavior'      => 'exclusive',
                'taxable_amount'    => 5900,
                'taxability_reason' => 'standard_rated',
                'tax_rate_details'  => ['tax_rate' => 'txr_gst'],
            ]],
            'default_tax_rates' => [[
                'id'           => 'txr_gst',
                'object'       => 'tax_rate',
                'display_name' => 'GST',
                'percentage'   => 17.0,
                'jurisdiction' => 'PK',
                'inclusive'    => false,
                'tax_type'     => 'gst',
            ]],
            'customer_tax_ids' => [['type' => 'pk_ntn', 'value' => '1234567-8']],
        ], $overrides);

        $double = \Mockery::mock();
        $double->shouldReceive('retrieve')->andReturn(
            \Stripe\Util\Util::convertToStripeObject($invoice, [])
        );
        $double->shouldReceive('all')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['object' => 'list', 'data' => [$invoice]], [])
        );

        return $double;
    }

    private function paidWorkspace(array $invoiceOverrides = []): array
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $subs = $this->subscriptionServiceReturning(
            $this->subscriptionEvent('x', $client, 'active', 'growth', 'monthly')['data']['object']
        );

        $this->fakeStripe($this->savedCardServices() + [
            'subscriptions' => $subs,
            'invoices'      => $this->invoiceService($invoiceOverrides),
        ]);

        $client->forceFill(['stripe_customer_ref' => 'cus_test_1'])->save();

        return [$client->fresh(), $user];
    }

    private function paidEvent($client, array $overrides = []): array
    {
        return [
            'id'     => 'evt_' . bin2hex(random_bytes(6)),
            'object' => 'event',
            'type'   => 'invoice.paid',
            'data'   => ['object' => array_merge([
                'id'           => 'in_test_1',
                'object'       => 'invoice',
                'customer'     => 'cus_test_1',
                'subscription' => 'sub_test_1',
                'amount_paid'  => 6903,
                'metadata'     => ['client_ref' => (string) $client->id],
            ], $overrides)],
        ];
    }

    // ── Delivery ─────────────────────────────────────────────────────

    public function test_a_receipt_is_emailed_when_an_invoice_is_paid(): void
    {
        [$client] = $this->paidWorkspace();

        $this->postWebhook($this->paidEvent($client))->assertOk();

        Mail::assertSent(InvoicePaidMail::class, function ($mail) use ($client) {
            return $mail->hasTo('owner@acme.test')
                && $mail->client->is($client);
        });
    }

    public function test_the_receipt_comes_from_the_billing_address(): void
    {
        [$client] = $this->paidWorkspace();

        config(['billing.mail.from_address' => 'billing@serveai.com.pk']);

        $this->postWebhook($this->paidEvent($client))->assertOk();

        Mail::assertSent(InvoicePaidMail::class, function ($mail) {
            // A receipt is the one transactional mail people reply to, so it
            // must not come from no-reply@.
            return $mail->envelope()->from->address === 'billing@serveai.com.pk';
        });
    }

    public function test_a_stripe_retry_does_not_send_a_second_receipt(): void
    {
        [$client] = $this->paidWorkspace();

        $event = $this->paidEvent($client);

        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk();     // same event id

        Mail::assertSentCount(1);
    }

    public function test_a_zero_amount_invoice_sends_nothing(): void
    {
        // A trial starting, or credit covering the whole amount. "You have
        // been charged $0.00" is a confusing thing to receive.
        [$client] = $this->paidWorkspace();

        $this->postWebhook($this->paidEvent($client, ['amount_paid' => 0]))->assertOk();

        Mail::assertNothingSent();
    }

    public function test_receipts_can_be_switched_off(): void
    {
        config(['billing.mail.receipts' => false]);

        [$client] = $this->paidWorkspace();

        $this->postWebhook($this->paidEvent($client))->assertOk();

        Mail::assertNothingSent();
    }

    public function test_a_mail_failure_never_breaks_the_webhook(): void
    {
        // The subscription sync in the same handler matters more than the
        // email. A 500 here would make Stripe retry for days and could leave
        // a paying customer un-provisioned.
        [$client] = $this->paidWorkspace();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $this->postWebhook($this->paidEvent($client))->assertOk();

        $this->assertSame(
            \App\Models\Billing\StripeEvent::STATUS_PROCESSED,
            \App\Models\Billing\StripeEvent::first()->status
        );
    }

    // ── Content ──────────────────────────────────────────────────────

    public function test_the_receipt_states_the_tax_that_was_charged(): void
    {
        [$client] = $this->paidWorkspace();

        $this->postWebhook($this->paidEvent($client))->assertOk();

        Mail::assertSent(InvoicePaidMail::class, function ($mail) {
            $html = $mail->render();

            return str_contains($html, 'GST')
                && str_contains($html, '17%')
                && str_contains($html, '$10.03')       // the tax amount
                && str_contains($html, '$69.03')       // the total paid
                && str_contains($html, '1234567-8');   // the buyer's tax id
        });
    }

    public function test_tax_is_read_from_the_modern_stripe_field(): void
    {
        // Invoice::$tax was REMOVED from the Stripe API; tax now lives in
        // total_taxes[]. Reading the old field returns null, which silently
        // reports zero tax on every invoice.
        [$client] = $this->paidWorkspace();

        $data = app(\App\Services\Billing\BillingService::class)->invoice($client, 'in_test_1');

        $this->assertSame(1003, $data['tax']);
        $this->assertSame('GST', $data['tax_lines'][0]['label']);
        $this->assertSame(17.0, $data['tax_lines'][0]['percentage']);
        $this->assertSame('PK', $data['tax_lines'][0]['jurisdiction']);
        $this->assertFalse($data['tax_lines'][0]['inclusive']);
    }

    public function test_an_untaxed_invoice_says_so_rather_than_hiding_the_line(): void
    {
        // Omitting the row leaves the reader unable to tell "no tax" from
        // "tax hidden inside the total".
        [$client] = $this->paidWorkspace([
            'total_taxes'       => [],
            'default_tax_rates' => [],
            'customer_tax_ids'  => [],
            'total'             => 5900,
            'amount_paid'       => 5900,
        ]);

        $data = app(\App\Services\Billing\BillingService::class)->invoice($client, 'in_test_1');

        $this->assertSame(0, $data['tax']);
        $this->assertSame('No tax applied.', $data['tax_note']);

        $this->postWebhook($this->paidEvent($client, ['amount_paid' => 5900]))->assertOk();

        Mail::assertSent(InvoicePaidMail::class, function ($mail) {
            $html = $mail->render();

            return str_contains($html, 'No tax applied.') && str_contains($html, '$0.00');
        });
    }

    public function test_a_reverse_charge_invoice_explains_itself(): void
    {
        [$client] = $this->paidWorkspace([
            'total_taxes'         => [],
            'default_tax_rates'   => [],
            'customer_tax_exempt' => 'reverse',
        ]);

        $data = app(\App\Services\Billing\BillingService::class)->invoice($client, 'in_test_1');

        $this->assertStringContainsString('Reverse charge', (string) $data['tax_note']);
    }

    public function test_the_receipt_lists_the_plan_and_line_items(): void
    {
        [$client] = $this->paidWorkspace();

        $this->postWebhook($this->paidEvent($client))->assertOk();

        Mail::assertSent(InvoicePaidMail::class, function ($mail) {
            $html = $mail->render();

            return str_contains($html, 'Growth')
                && str_contains($html, 'B1F4C2A9-0007')       // invoice number
                && str_contains($html, 'Visa ending 4242')
                && str_contains($html, 'Amount paid');
        });
    }
}
