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
        Schema::create('refract_traces', function (Blueprint $table) {
            $table->id();
            $table->longText('payload');
            $table->unsignedInteger('bytes');
            // Unix times. Big integers: PostgreSQL has no unsigned integer, and a signed one ends in 2038.
            $table->unsignedBigInteger('available_at');
            $table->unsignedBigInteger('reserved_at')->nullable();

            // A claim reads due trees in (available_at, id) order, so a released tree goes behind the trees due before it.
            $table->index(['available_at', 'id']);

            // Each push sums the stored bytes to keep the cap: this index covers the sum, so it never reads the payloads.
            $table->index('bytes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refract_traces');
    }
};
