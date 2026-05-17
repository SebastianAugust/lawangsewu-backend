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
    Schema::table('order_items', function (Blueprint $table) {
        $table->foreignId('menu_variant_id')->nullable()->after('menu_id')->constrained()->onDelete('set null');
        $table->string('variant_name')->nullable()->after('menu_variant_id');
    });
}

public function down(): void
{
    Schema::table('order_items', function (Blueprint $table) {
        $table->dropForeign(['menu_variant_id']);
        $table->dropColumn(['menu_variant_id', 'variant_name']);
    });
}
};
