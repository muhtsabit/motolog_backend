<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MotorCycleController extends Controller {
    
   // GANTI FUNGSI INDEX LAMA LU DENGAN INI DI SEBELAH BACKEND LARAVEL:
    public function index($user_id) {
        // 1. Ambil semua data motor milik user
        $motorcycles = DB::table('motorcycles')->where('user_id', $user_id)->get();

        foreach ($motorcycles as $motor) {
            // 2. Ambil data kilometer terakhir dari tabel component_histories untuk motor ini
            $components = DB::table('component_histories')
                ->where('motorcycle_id', $motor->id)
                ->pluck('last_service_km', 'component_name'); // Menghasilkan format: ["Oli Mesin" => 2000, "Busi" => 10000]

            // 3. Bungkus ke dalam key component_last_services agar dibaca reaktif oleh Flutter
            $motor->component_last_services = $components->isEmpty() ? (object)[] : $components;
        }
        
        return response()->json($motorcycles, 200);
    }
    // Menyimpan data dari form onboarding MotoLog
    public function store(Request $request) {
        $request->validate([
            'user_id' => 'required',
            'name' => 'required|min:3',
            'brand' => 'required',
            'current_km' => 'required|integer|min:0',
            'components' => 'array' 
        ]);

        if ($request->has('components')) {
            foreach ($request->components as $name => $km) {
                if ($km > $request->current_km) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Kilometer terakhir servis '$name' ($km km) 
                        tidak boleh melebihi kilometer motor saat ini (" . $request->current_km . " km)."
                    ], 422); // 422: Unprocessable Entity (Eror validasi bisnis)
                }
            }
        }

        DB::beginTransaction();
        try {
            // 1. Masukkan ke tabel motorcycles
            $motorId = DB::table('motorcycles')->insertGetId([
                'user_id' => $request->user_id,
                'name' => $request->name,
                'brand' => $request->brand,
                'current_km' => $request->current_km,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Masukkan ke tabel komponen secara dinamis (Oli, Busi, maupun kustom tambahan)
            if ($request->has('components')) {
                foreach ($request->components as $name => $km) {
                    DB::table('component_histories')->insert([
                        'motorcycle_id' => $motorId,
                        'component_name' => $name,
                        'last_service_km' => $km,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Motor terdata di MySQL!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function updateKm(Request $request, $id) {
        // 1. Validasi input agar wajib berupa angka bulat positif
        $request->validate([
            'current_km' => 'required|integer|min:0'
        ]);

        // 2. Cari data motornya di MySQL
        $motor = DB::table('motorcycles')->where('id', $id)->first();
        
        if (!$motor) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data motor tidak ditemukan.'
            ], 404);
        }

        // 3. Proteksi Aturan Bisnis: Kilometer tidak boleh berjalan mundur
        if ($request->current_km < $motor->current_km) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kilometer baru tidak boleh lebih kecil dari kilometer sekarang (' . $motor->current_km . ' km).'
            ], 422); // 422: Unprocessable Entity
        }

        // 4. Eksekusi Update ke MySQL
        DB::table('motorcycles')
            ->where('id', $id)
            ->update([
                'current_km' => $request->current_km,
                'updated_at' => now()
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Kilometer motor berhasil diperbarui!',
            'updated_km' => $request->current_km
        ], 200);
    }

    public function logout(Request $request) {
        try {
            // Menghapus session autentikasi di sisi server Laravel
            auth()->logout(); 
        
            return response()->json([
                'status' => 'success',
                'message' => 'Sesi di server Laravel berhasil dihapus!'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus sesi server: ' . $e->getMessage()
            ], 500);
        }
    }
}