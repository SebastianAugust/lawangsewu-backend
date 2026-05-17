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
    Schema::table('orders', function (Blueprint $table) {
        $table->enum('payment_method', ['cash', 'qris', 'transfer'])->default('cash')->after('total_price');
        $table->integer('cash_received')->nullable()->after('payment_method');
        $table->integer('change_amount')->nullable()->after('cash_received');
        $table->enum('status', ['completed', 'void_pending', 'voided'])->default('completed')->after('change_amount');
        $table->text('void_reason')->nullable()->after('status');
        $table->foreignId('voided_by')->nullable()->constrained('users')->after('void_reason');
        $table->timestamp('voided_at')->nullable()->after('voided_by');
    });
}

public function down(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->dropColumn(['payment_method', 'cash_received', 'change_amount', 'status', 'void_reason', 'voided_by', 'voided_at']);
    });
}
};
