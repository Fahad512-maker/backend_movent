<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; background: #f8fafc; margin: 0; padding: 20px; }
  .container { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
  .logo { font-size: 22px; font-weight: 800; color: #2563eb; margin-bottom: 24px; }
  h1 { font-size: 24px; color: #0f172a; margin: 0 0 12px; }
  p { color: #475569; line-height: 1.6; }
  .info-box { background: #eff6ff; border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
  .info-box p { margin: 4px 0; color: #1e40af; font-size: 14px; }
  .btn { display: inline-block; padding: 12px 28px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; margin-top: 16px; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="container">
  <div class="logo">Movent</div>
  <h1>Welcome, {{ $client->name }}! 🎉</h1>
  <p><strong>{{ $company->name }}</strong> has set up a client portal account for you. You can log in to view your projects, invoices, and more.</p>

  <div class="info-box">
    <p><strong>Login Email:</strong> {{ $portalEmail }}</p>
    <p><strong>Password:</strong> {{ $portalPassword }}</p>
  </div>

  <p>We recommend changing your password after your first login.</p>

  {{-- See the matching note in emails/user-welcome.blade.php. --}}
  <a href="{{ \App\Support\FrontendUrl::to('client/login') }}" class="btn">Log In to Client Portal →</a>

  <div class="footer">
    <p>If you weren't expecting this email, please contact {{ $company->name }} directly.</p>
    <p>© {{ date('Y') }} Movent. All rights reserved.</p>
  </div>
</div>
</body>
</html>
