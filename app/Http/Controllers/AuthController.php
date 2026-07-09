<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Mail\SendOtpMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
   public function login(Request $request)
    {
        // Validasi input email dari Android
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        // Proses autentikasi menggunakan email
        if (Auth::attempt($request->only('email', 'password'))) {
            $user = Auth::user();
            
            if (!$user->is_active) {
                Auth::logout();
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Akun Anda telah dinonaktifkan oleh Admin'
                ], 403);
            }
            
            // Ambil role pertama dari relasi many-to-many
            $role = $user->roles->first()?->name; 

            $token = $user->createToken('AndroidToken')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Login Berhasil!',
                'token' => $token,
                'role' => $role, 
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email
                ]
            ], 200);
        }

        return response()->json([
            'status' => 'failed',
            'message' => 'Email atau Password salah!'
        ], 401);
    }

    public function forgotPasswordRequest(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email Belum Terdaftar'
            ], 404);
        }

        // Generate OTP 6 digit
        $otp = sprintf("%06d", mt_rand(100000, 999999));

        // Simpan ke database
        \DB::table('password_reset_otps')->insert([
            'email' => $request->email,
            'otp' => $otp,
            'expires_at' => Carbon::now()->addMinutes(5),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Kirim email
        try {
            Mail::to($request->email)->send(new SendOtpMail($otp));
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengirim email: ' . $e->getMessage()
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Kode OTP berhasil dikirim ke email Anda!'
        ], 200);
    }

    public function forgotPasswordVerify(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6'
        ]);

        $otpRecord = \DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('is_verified', false)
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('id', 'desc')
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kode OTP salah atau telah kadaluwarsa!'
            ], 400);
        }

        // Buat reset token sementara
        $token = Str::random(60);

        \DB::table('password_reset_otps')
            ->where('id', $otpRecord->id)
            ->update([
                'is_verified' => true,
                'token' => $token
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'OTP terverifikasi!',
            'reset_token' => $token
        ], 200);
    }

    public function forgotPasswordReset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:6'
        ]);

        $otpRecord = \DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->where('is_verified', true)
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('id', 'desc')
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesi verifikasi tidak valid atau kadaluwarsa!'
            ], 400);
        }

        // Update password user
        $user = User::where('email', $request->email)->first();
        if ($user) {
            $user->update([
                'password' => bcrypt($request->password)
            ]);
        }

        // Hapus token setelah digunakan
        \DB::table('password_reset_otps')->where('email', $request->email)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Kata sandi berhasil diubah!'
        ], 200);
    }
}