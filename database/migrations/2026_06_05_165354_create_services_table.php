<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
    {
    Schema::create('services', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('motorcycle_id'); // Menghubungkan ke motor yang diservis
        $table->integer('service_km');               // Angka KM pas servis dilakukan
        $table->text('notes')->nullable();           // Catatan opsional sesuai image_973203.png
        $table->date('service_date');                // Tanggal servis sesuai image_973203.png
        $table->timestamps();

        // Foreign key ke tabel motorcycles milikmu
        $table->foreign('motorcycle_id')->references('id')->on('motorcycles')->onDelete('cascade');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
