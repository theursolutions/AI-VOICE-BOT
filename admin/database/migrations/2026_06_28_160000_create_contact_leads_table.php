<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Website contact / "Call me now" captures from the public marketing site.
 *
 * Until now these landed only in storage/app/demo-leads.jsonl. This central
 * table makes them visible (and triageable) in the super-admin ops console.
 * The migration also back-imports any existing JSONL rows so history is kept.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contact_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->string('source', 40)->default('demo_call'); // demo_call | contact_form | ...
            $table->enum('status', ['new', 'contacted', 'closed'])->default('new');
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        // Back-import the JSONL capture log so nothing is lost.
        try {
            if (Storage::disk('local')->exists('demo-leads.jsonl')) {
                $raw   = (string) Storage::disk('local')->get('demo-leads.jsonl');
                $rows  = [];
                foreach (preg_split('/\r?\n/', $raw) as $ln) {
                    $ln = trim($ln);
                    if ($ln === '') continue;
                    $d = json_decode($ln, true);
                    if (!is_array($d)) continue;
                    $ts = !empty($d['ts']) ? strtotime($d['ts']) : time();
                    if (!$ts) $ts = time();
                    $rows[] = [
                        'name'       => null,
                        'email'      => null,
                        'phone'      => $d['phone'] ?? null,
                        'subject'    => null,
                        'message'    => null,
                        'source'     => 'demo_call',
                        'status'     => 'new',
                        'ip'         => $d['ip'] ?? null,
                        'user_agent' => isset($d['ua'])  ? substr((string) $d['ua'], 0, 255)  : null,
                        'referrer'   => isset($d['ref']) ? substr((string) $d['ref'], 0, 255) : null,
                        'created_at' => date('Y-m-d H:i:s', $ts),
                        'updated_at' => date('Y-m-d H:i:s', $ts),
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('contact_leads')->insert($chunk);
                }
            }
        } catch (\Throwable $e) {
            // Import is best-effort — never block the migration on it.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_leads');
    }
};
