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
            $table->index(['user_id', 'traded_at'], 'copy_trades_user_traded_at_index');
            $table->unique('follower_contract_id', 'copy_trades_follower_contract_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('copy_trades', function (Blueprint $table) {
            $table->dropUnique('copy_trades_follower_contract_unique');
            $table->dropIndex('copy_trades_user_traded_at_index');
        });
    }
};
