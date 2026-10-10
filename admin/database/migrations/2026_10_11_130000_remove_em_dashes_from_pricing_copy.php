<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * No em dashes in the pricing and checkout copy customers read (owner's call,
 * 2026-10-11). The templates were edited directly; this updates the copy that
 * lives in the database — plan taglines and descriptions, feature names and
 * descriptions — plus the footer tagline when it is still the shipped default.
 *
 * Names read best with a colon ("Voices: 30 stock voices…"); sentences with a
 * comma. Only " — " (spaced) is replaced, so nothing else in the text moves.
 */
return new class extends Migration
{
    private const NAMES = [
        'Voices — 30 stock voices + voice cloning'              => 'Voices: 30 stock voices + voice cloning',
        'Brain Settings — bring your own AI keys / local model' => 'Brain Settings: bring your own AI keys / local model',
    ];

    public function up(): void
    {
        foreach (self::NAMES as $old => $new) {
            DB::table('features')->where('name', $old)->update(['name' => $new]);
        }

        $columns = [
            'plans'    => ['tagline', 'description'],
            'features' => ['name', 'description'],
        ];
        foreach ($columns as $table => $fields) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($fields as $field) {
                if (! Schema::hasColumn($table, $field)) {
                    continue;
                }
                DB::table($table)->where($field, 'like', '% — %')->orderBy('id')->each(function ($row) use ($table, $field) {
                    DB::table($table)->where('id', $row->id)
                        ->update([$field => str_replace(' — ', ', ', $row->{$field})]);
                });
            }
        }

        // The footer tagline is a site setting; change it only if nobody has
        // written their own since it shipped.
        if (Schema::hasTable('site_settings')) {
            $old = json_encode('AI customer support and CRM in one: AI agents that answer every call, chat and message — 24/7, from your own data.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $new = json_encode('AI customer support and CRM in one: AI agents that answer every call, chat and message, 24/7, from your own data.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            DB::table('site_settings')->where('key', 'content.footer_tagline')->where('value', $old)->update(['value' => $new]);
        }
    }

    public function down(): void
    {
        // Copy only; nothing to undo safely.
    }
};
