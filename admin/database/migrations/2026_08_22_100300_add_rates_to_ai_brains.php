<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a brain's tokens cost, in dollars per million.
 *
 * The platform has counted tokens since ai_brain_usage existed and has never
 * known what any of them cost. That was tolerable while pricing was set by hand;
 * it is not once a customer can configure their own plan and be quoted for it,
 * because the quote has to come from somewhere and the only honest source is
 * measured usage multiplied by a real rate.
 *
 * DOLLARS PER MILLION TOKENS, as a decimal, because that is the unit every
 * provider quotes on their own pricing page — 0.1000 in / 0.4000 out is
 * transcribed rather than converted, and a transcription can be checked against
 * the source by eye. Rates expressed per-1k or in micro-units invite a
 * factor-of-1000 error that produces a plausible-looking number.
 *
 * DECIMAL, not float: these are multiplied by token counts in the millions and
 * then rounded to cents, and a binary float that cannot hold 0.1 exactly has no
 * business anywhere near a price a customer is shown.
 *
 * Defaults are seeded per preset from each vendor's published rate at the time
 * of writing, EXCEPT where the preset cannot have one — OpenRouter and Custom
 * span arbitrary models, and Ollama is our own hardware, so those stay at zero.
 * Zero means "not costed" and the pricer treats it as such rather than as free.
 */
return new class extends Migration
{
    /**
     * preset => [input $/1M, output $/1M].
     *
     * The lowest-cost model in each vendor's line-up, since that is what a cost
     * floor should assume; an operator running something dearer edits the brain.
     */
    private const RATES = [
        'openai'     => ['0.1500', '0.6000'],   // gpt-4o-mini class
        'deepseek'   => ['0.2700', '1.1000'],   // deepseek-chat
        'gemini'     => ['0.1000', '0.4000'],   // 3.5 flash-lite
        'groq'       => ['0.0500', '0.0800'],   // llama-3.1-8b-instant
        'cerebras'   => ['0.1000', '0.1000'],   // llama3.1-8b
        'together'   => ['0.2000', '0.2000'],
        'anthropic'  => ['0.2500', '1.2500'],   // haiku class
        'ollama'     => ['0.0000', '0.0000'],   // our own hardware
        'openrouter' => ['0.0000', '0.0000'],   // spans every model; operator sets it
        'custom'     => ['0.0000', '0.0000'],
    ];

    public function up(): void
    {
        Schema::table('ai_brains', function (Blueprint $table) {
            $table->decimal('rate_in', 10, 4)->default(0)->after('max_tokens');
            $table->decimal('rate_out', 10, 4)->default(0)->after('rate_in');
        });

        // Seed from the preset each existing brain was created from, so an
        // install that already has brains starts costed rather than at zero.
        foreach (self::RATES as $preset => [$in, $out]) {
            DB::table('ai_brains')
                ->where('preset', $preset)
                ->update(['rate_in' => $in, 'rate_out' => $out]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_brains', function (Blueprint $table) {
            $table->dropColumn(['rate_in', 'rate_out']);
        });
    }
};
