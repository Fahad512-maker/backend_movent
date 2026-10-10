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
  .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; background: #e0e7ff; color: #3730a3; margin-bottom: 20px; }
  .receipt-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px 24px; margin: 20px 0; }
  .receipt-box table { width: 100%; border-collapse: collapse; }
  .receipt-box td { padding: 6px 0; font-size: 14px; color: #475569; vertical-align: top; }
  .receipt-box td:last-child { text-align: right; font-weight: 600; color: #0f172a; }
  .btn { display: inline-block; padding: 14px 32px; background: #2563eb; color: #fff !important; text-decoration: none; border-radius: 9px; font-weight: 700; font-size: 15px; margin: 8px 0 20px; }
  .link-box { background: #f1f5f9; border-radius: 8px; padding: 12px 16px; margin: 16px 0; font-size: 12px; color: #64748b; word-break: break-all; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="container">
  <div class="logo">{{ $companyName }}</div>
  <span class="badge">{{ $badge }}</span>
  <h1>{{ $heading }}</h1>

  <p>Hi {{ $recipientName }}, {{ $intro }}</p>

  @if(count($rows))
  <div class="receipt-box">
    <table>
      @foreach($rows as $label => $value)
      <tr>
        <td>{{ $label }}</td>
        <td>{{ $value }}</td>
      </tr>
      @endforeach
    </table>
  </div>
  @endif

  @if($actionUrl)
  <a href="{{ $actionUrl }}" class="btn">{{ $actionLabel ?? 'Open' }} →</a>
  <div class="link-box">
    Or copy this link: {{ $actionUrl }}
  </div>
  <p style="font-size:13px; color:#64748b;">You'll need to be signed in to your {{ $companyName }} dashboard to open it.</p>
  @endif

  @if($footNote)
  <p style="font-size:13px; color:#64748b;">{{ $footNote }}</p>
  @endif

  <div class="footer">
    This is an automated message from {{ $companyName }}.
  </div>
</div>
</body>
</html>
