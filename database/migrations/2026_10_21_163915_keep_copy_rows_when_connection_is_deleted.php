<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removing a Deriv connection no longer deletes the copy settings and copy-trade history that point at it:
 * the link is emptied instead, so followers keep their settings and everyone keeps their history.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['copy_settings', 'copy_trades'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['master_connection_id']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('master_connection_id')->nullable()->change();
                $table->foreign('master_connection_id')->references('id')->on('deriv_connections')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations. This fails once any copy row has no master, because the old column is not nullable;
     * delete or reassign those rows first.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['master_connection_id']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('master_connection_id')->nullable(false)->change();
                $table->foreign('master_connection_id')->references('id')->on('deriv_connections')->cascadeOnDelete();
            });
        }
    }
};
