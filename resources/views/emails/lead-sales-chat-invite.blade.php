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
  .btn { display: inline-block; padding: 13px 30px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; margin: 20px 0 8px; }
  .fallback { font-size: 12px; color: #94a3b8; word-break: break-all; margin-top: 4px; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="container">
  {{-- The sending company's own trading name, not a hardcoded product name:
       every company under one account has its own identity on invoices and
       emails since settings became per-company. --}}
  <div class="logo">{{ $companyName }}</div>

  <h1>Hi {{ $leadName }},</h1>

  <p>Thank you for your interest in our services.</p>

  @if ($sellerName)
    <p>{{ $sellerName }} from our team will be looking after you. You can message
       {{ $sellerName }} directly using the button below — no account or password needed.</p>
  @else
    <p>You can communicate with our team directly using the button below — no account
       or password needed.</p>
  @endif

  <a href="{{ $chatUrl }}" class="btn">Start Conversation →</a>

  {{-- Plenty of mail clients strip or fail to linkify a styled anchor, and this
       link is the entire point of the email — so the raw URL is printed too. --}}
  <p class="fallback">Or paste this link into your browser:<br>{{ $chatUrl }}</p>

  <p>Regards,<br>{{ $companyName }}</p>

  <div class="footer">
    {{-- The link is a credential: anyone holding it can read and write this
         conversation, which is why it must not be forwarded. --}}
    <p>This link is personal to you — please don't forward it, as it opens your
       conversation with our team.</p>
    <p>If you weren't expecting this email, you can simply ignore it.</p>
    <p>&copy; {{ date('Y') }} {{ $companyName }}</p>
  </div>
</div>
</body>
</html>
