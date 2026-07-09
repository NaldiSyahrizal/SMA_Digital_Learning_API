<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Kode OTP Anda</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #F4F6F9; padding: 20px; }
        .card { background: #FFFFFF; max-width: 500px; margin: 0 auto; padding: 30px; border-radius: 12px; border: 1px solid #E8EAF0; }
        .otp { font-size: 32px; font-weight: bold; color: #21286A; text-align: center; letter-spacing: 4px; margin: 20px 0; }
        .footer { font-size: 12px; color: #777777; text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color: #21286A; text-align: center;">Ubah Kata Sandi</h2>
        <p>Halo,</p>
        <p>Kami menerima permintaan untuk mengatur ulang kata sandi akun SMA Digital Learning Anda. Gunakan kode OTP di bawah ini untuk memverifikasi email Anda:</p>
        <div class="otp">{{ $otp }}</div>
        <p>Kode ini berlaku selama <strong>5 menit</strong>. Jika Anda tidak merasa mengajukan permintaan ini, silakan abaikan email ini.</p>
        <div class="footer">SMA Digital Learning &copy; 2026</div>
    </div>
</body>
</html>
