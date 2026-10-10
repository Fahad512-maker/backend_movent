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
  <h1>Welcome, {{ $admin->name }}! 🎉</h1>
  <p>Your company <strong>{{ $company->name }}</strong> has been successfully registered. You're all set to start managing your business smarter.</p>

  <div class="info-box">
    <p><strong>Company:</strong> {{ $company->name }}</p>
    <p><strong>Login Email:</strong> {{ $admin->email }}</p>
    @if($trialEndsAt)
    <p><strong>Trial Ends:</strong> {{ $trialEndsAt->format('d M Y') }}</p>
    @endif
    @if($package)
    <p>
      <strong>Plan:</strong> {{ $package->name }} —
      ${{ number_format($priceUsd, 2) }}/{{ $package->billingTerm?->name ?? 'mo' }}
      @if($trialEndsAt)
        (billed once your trial ends)
      @elseif($admin->subscription_status === 'pending_payment')
        (payment required to activate)
      @endif
    </p>
    @endif
  </div>

  <p>Login to your dashboard to invite team members, set up your modules, and start working.</p>

  @if($admin->subscription_status === 'pending_payment')
  <a href="{{ config('app.url') }}/login" class="btn">Complete Payment →</a>
  @else
  <a href="{{ config('app.url') }}/dashboard" class="btn">Go to Dashboard →</a>
  @endif

  <div class="footer">
    <p>If you didn't create this account, please ignore this email.</p>
    <p>© {{ date('Y') }} Movent. All rights reserved.</p>
  </div>
</div>
</body>
</html>
