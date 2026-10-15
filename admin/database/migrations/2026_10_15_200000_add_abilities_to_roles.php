<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finer permissions inside a module, for modules that declare `abilities`
 * in config/modules.php (Lead Generation is the first: view / search /
 * import / edit / export / approve / send / settings).
 *
 * Shape: {"leadgen": ["view", "search"]}. Nullable, and a module missing
 * from the map means "every ability of that module" — so every existing role
 * keeps exactly the access it has today and nothing needs backfilling.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('roles', 'abilities')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->json('abilities')->nullable()->after('modules');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'abilities')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('abilities');
            });
        }
    }
};
