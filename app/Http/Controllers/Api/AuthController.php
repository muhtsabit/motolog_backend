<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;



class AuthController extends Controller
{
    public function loginWithGoogle(Request $request)
    {
        // 1. Ambil id_token yang dikirim secara async oleh Flutter
        $idToken = $request->input('id_token');

        if (empty($idToken)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token kosong!'
            ], 400);
        }

        // 2. Tembak ke API Google untuk memverifikasi apakah token ini asli atau manipulasi
        $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $idToken;
        $response = Http::get($url);

        // Jika Google merespons error atau token kadaluwarsa
        if ($response->failed() || isset($response->json()['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token tidak valid atau sudah kadaluwarsa!'
            ], 401);
        }

        // 3. Ambil data profil yang disediakan oleh Google jika verifikasi sukses
        $userData = $response->json();
        $email = $userData['email'];
        $name = $userData['name'];

        // 4. Jalankan logika ORM ke MySQL: Cek apakah email ini sudah ada di tabel users
        $user = User::where('email', $email)->first();

        if (!$user) {
            // Skenario PENGGUNA BARU (Create): Otomatis daftarkan ke MySQL
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt(\Illuminate\Support\Str::random(32)),
            ]);
            $message = "Akun baru MotoLog berhasil didaftarkan!";
        } else {
            // Skenario PENGGUNA LAMA (Read): Langsung loloskan login
            $message = "Selamat datang kembali di MotoLog!";
        }

        // 5. Kembalikan respons JSON sukses ke Flutter
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'user' => $user
        ], 200);
    }
    
    public function login(Request $request)
    {
    $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Email atau kata sandi salah.',
        ], 401);
    }

    return response()->json([
        'status' => 'success',
        'user'   => $user,
    ], 200);
}

    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|min:4',
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:8',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => bcrypt($request->password),
        ]);

        return response()->json([
            'status' => 'success',
            'user'   => $user,
        ], 200);
    }
}