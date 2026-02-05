<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { background-color: #0f172a; font-family: 'Segoe UI', sans-serif; margin: 0; padding: 40px 0; }
        .container { max-width: 600px; margin: 0 auto; background-color: #1e293b; border-radius: 24px; overflow: hidden; border: 1px solid #334155; }
        .header { background-color: #f43f5e; padding: 30px; text-align: center; }
        .content { padding: 40px; text-align: center; color: #f1f5f9; }
        .footer { padding: 20px; text-align: center; color: #64748b; font-size: 12px; }
        .btn { display: inline-block; padding: 16px 32px; background-color: #f43f5e; color: #ffffff !important; text-decoration: none; border-radius: 12px; font-weight: bold; margin-top: 25px; box-shadow: 0 10px 15px -3px rgba(244, 63, 94, 0.4); }
        h1 { color: #ffffff; margin-bottom: 10px; font-size: 24px; }
        p { line-height: 1.6; color: #cbd5e1; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin:0; color:white; text-transform:uppercase; letter-spacing: 2px;">POS SYSTEM</h1>
        </div>
        <div class="content">
            <h1>Halo, {{ $name }}!</h1>
            <p>Kami menerima permintaan untuk mengatur ulang kata sandi Anda. Jika ini memang Anda, silakan klik tombol di bawah ini:</p>
            
            <a href="{{ $url }}" class="btn">RESET PASSWORD SEKARANG</a>
            
            <p style="margin-top: 30px; font-size: 13px; color: #94a3b8;">
                Link ini hanya berlaku selama 60 menit. <br>
                Jika Anda tidak meminta ini, silakan abaikan email ini dengan aman.
            </p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} POS System Cloud. Akses Keamanan Terintegrasi.
        </div>
    </div>
</body>
</html>