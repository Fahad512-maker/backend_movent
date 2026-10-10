<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; background: #f8fafc; margin: 0; padding: 20px; }
  .container { max-width: 580px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
  .logo { font-size: 22px; font-weight: 800; color: #2563eb; margin-bottom: 24px; }
  h1 { font-size: 22px; color: #0f172a; margin: 0 0 8px; }
  p { color: #475569; line-height: 1.6; margin: 0 0 14px; }
  .btn { display: inline-block; padding: 14px 32px; background: #2563eb; color: #fff !important; text-decoration: none; border-radius: 9px; font-weight: 700; font-size: 15px; margin: 8px 0 20px; }
  .link-box { background: #f1f5f9; border-radius: 8px; padding: 12px 16px; margin: 16px 0; font-size: 12px; color: #64748b; word-break: break-all; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="container">
  <div class="logo">Movent</div>
  <h1>Reset your password</h1>
  <p>Hi{{ $name ? ' ' . $name : '' }},</p>
  <p>We received a request to reset your password. Click the button below to choose a new one. This link expires in 60 minutes.</p>

  <a href="{{ $resetUrl }}" class="btn">Reset Password →</a>
  <div class="link-box">
    Or copy this link: {{ $resetUrl }}
  </div>

  <p style="font-size:13px; color:#64748b;">If you didn't request this, you can safely ignore this email — your password will not be changed.</p>

  <div class="footer">
    <p>© {{ date('Y') }} Movent. All rights reserved.</p>
  </div>
</div>
</body>
</html>
