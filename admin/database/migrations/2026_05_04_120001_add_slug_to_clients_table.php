<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->after('name');
        });

        // Backfill from name
        $clients = DB::table('clients')->whereNull('slug')->get();
        $seen = [];
        foreach ($clients as $c) {
            $base = Str::slug($c->name) ?: 'client';
            $slug = $base;
            $i = 2;
            while (in_array($slug, $seen, true) || DB::table('clients')->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$i++;
            }
            $seen[] = $slug;
            DB::table('clients')->where('id', $c->id)->update(['slug' => $slug]);
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
