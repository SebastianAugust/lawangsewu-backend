<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sebelumnya orders.user_id pakai onDelete('cascade'): menghapus kasir akan
     * ikut menghapus seluruh transaksinya. Kita ubah ke nullOnDelete (seperti
     * branch_id) supaya owner bisa menghapus cabang/kasir tanpa kehilangan
     * riwayat transaksi — order lama tetap tersimpan, hanya jadi tidak terikat.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
