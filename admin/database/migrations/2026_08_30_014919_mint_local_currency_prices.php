<?php

use App\Services\Billing\LocalPriceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every sellable plan a price in the currency the local gateway settles.
 *
 * Without this a Pakistani workspace reaches checkout, resolvePrice() finds no
 * rupee row and refuses the sale — so the gateway is wired up and nothing can
 * be bought through it.
 *
 * Idempotent: LocalPriceService skips any interval that already has a row in
 * the target currency, so re-running mints nothing and never overwrites a price
 * an operator has since adjusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $local = app(LocalPriceService::class);

        if (! $local->isConfigured('PKR')) {
            return;
        }

        $local->mirrorAll('PKR');
    }

    public function down(): void
    {
        // Only the rows this minted — identified by the metadata it stamps, so
        // a price an operator created or edited by hand is left alone.
        DB::table('plan_prices')
            ->where('currency', 'pkr')
            ->where('metadata', 'like', '%"minted_from"%')
            ->delete();
    }
};
