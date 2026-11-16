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
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('reserved_at')->nullable();

            // A claim takes due trees ((reserved_at null, available_at past) or reserved_at past) in id order.
            $table->index(['reserved_at', 'available_at']);
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
