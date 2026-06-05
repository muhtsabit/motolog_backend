<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // 1. Tabel Utama Motor
        Schema::create('motorcycles', function (Blueprint $table) {
            $table->id(); 
            $table->string('user_id')->index();
            $table->string('name');
            $table->string('brand');
            $table->integer('current_km'); 
            $table->timestamps();
        });

        // Tabel Riwayat Kilometer Komponen (Oli, Busi, Kustom, dll)
        Schema::create('component_histories', function (Blueprint $table) {
            $table->id();
            // Menghubungkan ke tabel motorcycles di atas. Jika motor dihapus, data komponen ikut terhapus.
            $table->foreignId('motorcycle_id')->constrained('motorcycles')->onDelete('cascade');
            $table->string('component_name');   // Nama komponen (Oli Mesin / Aki / Kampas Kopling)
            $table->integer('last_service_km'); // Angka KM terakhir servis yang diisi user
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('component_histories');
        Schema::dropIfExists('motorcycles');
    }
};