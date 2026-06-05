<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    /**
     * POST /api/services
     * Menyimpan data riwayat servis baru dari Flutter ke MySQL
     */
    public function store(Request $request)
    {
        // 1. Validasi Input agar cocok dengan request JSON dari AppState Provider
        $request->validate([
            'motorcycle_id'  => 'required',
            'service_km'     => 'required|integer|min:0',
            'notes'          => 'nullable|string',
            'service_date'   => 'required|date',
            'components'     => 'required|array|min:1' 
        ]);

        // 2. Ambil data kondisi motor saat ini
        $motor = DB::table('motorcycles')->where('id', $request->motorcycle_id)->first();
        if (!$motor) {
            return response()->json(['status' => 'error', 'message' => 'Data motor tidak ditemukan.'], 404);
        }

        // Aturan Bisnis: Proteksi agar KM servis tidak lebih kecil dari KM saat ini
        if ($request->service_km < $motor->current_km) {
            return response()->json([
                'status' => 'error',
                'message' => "KM servis tidak boleh lebih kecil dari KM motor saat ini ({$motor->current_km} km)."
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Ambil nama komponen utama (item pertama dari array) untuk dicatat di tabel induk services
            $mainComponent = $request->components[0];

           // 3. ALTERNATIF BYPASS: Simpan tanpa kolom component_name
            DB::table('services')->insert([
                'motorcycle_id'  => $request->motorcycle_id,
                'service_km'     => $request->service_km,
                // Kita gabungkan nama komponen ke dalam notes jika user tidak mengisi catatan
                'notes'          => $request->notes ? "[{$mainComponent}] " . $request->notes : "Servis komponen: {$mainComponent}",
                'service_date'   => $request->service_date,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // 4. Reset baseline KM komponen di tabel 'component_histories' agar bar di dashboard kembali 100% hijau
            foreach ($request->components as $componentName) {
                // Cek apakah komponen ini sudah ada di database atau belum
                $exists = DB::table('component_histories')
                    ->where('motorcycle_id', $request->motorcycle_id)
                    ->where('component_name', $componentName)
                    ->exists();

                if ($exists) {
                    // Jika sudah ada, lakukan update kilometer
                    DB::table('component_histories')
                        ->where('motorcycle_id', $request->motorcycle_id)
                        ->where('component_name', $componentName)
                        ->update([
                            'last_service_km' => $request->service_km,
                            'updated_at'      => now()
                        ]);
                } else {
                    // Jika belum ada (komponen kustom baru), lakukan insert baru agar tidak mentok di dashboard
                    DB::table('component_histories')->insert([
                        'motorcycle_id'   => $request->motorcycle_id,
                        'component_name'  => $componentName,
                        'last_service_km' => $request->service_km,
                        'created_at'      => now(),
                        'updated_at'      => now()
                    ]);
                }
            }

            // 5. Samakan KM motor utama mengikuti angka KM servis terbaru
            DB::table('motorcycles')
                ->where('id', $request->motorcycle_id)
                ->update([
                    'current_km' => $request->service_km,
                    'updated_at' => now()
                ]);

            DB::commit();
            return response()->json([
                'status' => 'success', 
                'message' => 'Catatan servis berhasil disimpan ke database MySQL!'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/services/{motorcycle_id}
     * Menarik data riwayat untuk dikirim kembali ke halaman timeline Flutter
     */
    public function show($motorcycle_id)
    {
        try {
            // Tarik data riwayat servis dari MySQL, urutkan dari yang paling baru
            $services = DB::table('services')
                ->where('motorcycle_id', $motorcycle_id)
                ->orderBy('service_date', 'desc')
                ->get();

            // Kembalikan data dalam bentuk JSON List murni agar langsung di-parsing elastis oleh ServiceModel.fromMap
            return response()->json($services, 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}