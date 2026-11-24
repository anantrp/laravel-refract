<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the migration connection name: the database collector's connection.
     */
    public function getConnection(): ?string
    {
        $connection = config('refract.collectors.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('refract_traces', function (Blueprint $table) {
            // The failed exports of each tree, so one that keeps failing is sent alone, then dropped.
            $table->unsignedSmallInteger('attempts')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The table may be gone already, with the migration that created it.
        if (! Schema::hasColumn('refract_traces', 'attempts')) {
            return;
        }

        Schema::table('refract_traces', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
