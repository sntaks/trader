<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('copy_trades', function (Blueprint $table) {
            $table->string('master_contract_id')->nullable()->after('master_trx_id');
            $table->string('status', 16)->default('placed')->after('is_win');
            $table->string('failure_reason')->nullable()->after('status');

            $table->unique(['user_id', 'master_contract_id'], 'copy_trades_user_master_contract_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('copy_trades', function (Blueprint $table) {
            $table->dropUnique('copy_trades_user_master_contract_unique');
            $table->dropColumn(['master_contract_id', 'status', 'failure_reason']);
        });
    }
};
