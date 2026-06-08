<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Google\Client;

class MotorCycleController extends Controller {
    
    public function index($user_id) {
        $motorcycles = DB::table('motorcycles')->where('user_id', $user_id)->get();

        foreach ($motorcycles as $motor) {
            $components = DB::table('component_histories')
                ->where('motorcycle_id', $motor->id)
                ->pluck('last_service_km', 'component_name');

            $motor->component_last_services = $components->isEmpty() ? (object)[] : $components;
        }
        
        return response()->json($motorcycles, 200);
    }

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
                        'message' => "Kilometer terakhir servis '$name' ($km km) tidak boleh melebihi kilometer motor saat ini (" . $request->current_km . " km)."
                    ], 422);
                }
            }
        }

        DB::beginTransaction();
        try {
            $motorId = DB::table('motorcycles')->insertGetId([
                'user_id' => $request->user_id,
                'name' => $request->name,
                'brand' => $request->brand,
                'current_km' => $request->current_km,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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
        $request->validate([
            'current_km' => 'required|integer|min:0'
        ]);

        $motor = DB::table('motorcycles')->where('id', $id)->first();
        
        if (!$motor) {
            return response()->json(['status' => 'error', 'message' => 'Data motor tidak ditemukan.'], 404);
        }

        if ($request->current_km < $motor->current_km) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kilometer baru tidak boleh lebih kecil dari kilometer sekarang (' . $motor->current_km . ' km).'
            ], 422);
        }

        DB::table('motorcycles')
            ->where('id', $id)
            ->update([
                'current_km' => $request->current_km,
                'updated_at' => now()
            ]);

        $user = DB::table('users')->where('id', $motor->user_id)->first();
        
        if ($user && !empty($user->fcm_token)) {
            $masterKomponen = [
                'Oli Mesin'    => 2000,
                'Busi'         => 8000,
                'Kampas Rem'   => 10000,
                'Filter Udara' => 12000,
            ];

            foreach ($masterKomponen as $namaKomponen => $batasKm) {
                $lastServiceKm = DB::table('component_histories')
                    ->where('motorcycle_id', $id)
                    ->where('component_name', $namaKomponen)
                    ->value('last_service_km') ?? 0;

                $selisihKm = $request->current_km - $lastServiceKm;

                if ($selisihKm >= $batasKm) {
                    $title = "Waktunya Ganti " . $namaKomponen . "! 🏍️";
                    $body  = "Motor " . $motor->name . " Anda sudah berjalan " . $selisihKm . " KM. Yuk lakukan perawatan!";
                    
                    // Tembak FCM menggunakan fungsi internal
                    $this->sendFcmNotification($user->fcm_token, $title, $body);
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Kilometer motor berhasil diperbarui!',
            'updated_km' => $request->current_km
        ], 200);
    }

   private function sendFcmNotification($deviceToken, $title, $body) {
    $projectId = env('FIREBASE_PROJECT_ID');
    
    // Baca service account
    $serviceAccount = json_decode(
        file_get_contents(storage_path('app/service-account.json')), 
        true
    );
    
    // Buat JWT untuk Google OAuth2
    $now = time();
    $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = base64_encode(json_encode([
        'iss'   => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    
    $unsignedJwt = $header . '.' . $claim;
    
    // Sign JWT dengan private key
    $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
    openssl_sign($unsignedJwt, $signature, $privateKey, 'SHA256');
    $jwt = $unsignedJwt . '.' . base64_encode($signature);
    
    // Tukar JWT dengan access token
    $tokenResponse = file_get_contents('https://oauth2.googleapis.com/token', false, 
        stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]])
    );
    
    $accessToken = json_decode($tokenResponse, true)['access_token'];
    
    // Kirim FCM
    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    $payload = json_encode([
        'message' => [
            'token'        => $deviceToken,
            'notification' => ['title' => $title, 'body' => $body],
            'android'      => [
                'notification' => [
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'sound'        => 'default',
                ],
            ],
        ],
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    \Log::info('FCM Result ' . $httpCode . ' >>> ' . $result);
    
    return $result;
}  

    public function update(Request $request, $id) {
        $request->validate([
            'name'  => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
        ]);

        DB::table('motorcycles')->where('id', $id)->update([
            'name'       => $request->name,
            'brand'      => $request->brand ?? '',
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'message' => 'Data motor berhasil diperbarui!'], 200);
    }

    public function destroy($id) {
        DB::beginTransaction();
        try {
            DB::table('services')->where('motorcycle_id', $id)->delete();
            DB::table('component_histories')->where('motorcycle_id', $id)->delete();
            DB::table('motorcycles')->where('id', $id)->delete();

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Motor dan seluruh riwayatnya berhasil dihapus!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function logout(Request $request) {
        try {
            auth()->logout(); 
            return response()->json(['status' => 'success', 'message' => 'Sesi di server Laravel berhasil dihapus!'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Gagal menghapus sesi server: ' . $e->getMessage()], 500);
        }
    }
}