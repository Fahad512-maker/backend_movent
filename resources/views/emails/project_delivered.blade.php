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
  .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; background: #d1fae5; color: #065f46; margin-bottom: 20px; }
  .receipt-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px 24px; margin: 20px 0; }
  .receipt-box table { width: 100%; border-collapse: collapse; }
  .receipt-box td { padding: 6px 0; font-size: 14px; color: #475569; vertical-align: top; }
  .receipt-box td:last-child { text-align: right; font-weight: 600; color: #0f172a; }
  .btn { display: inline-block; margin: 10px 0 4px; padding: 12px 24px; border-radius: 8px; background: #2563eb; color: #fff !important; text-decoration: none; font-weight: 700; font-size: 14px; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="container">
  <div class="logo">{{ $companyName }}</div>
  <span class="badge">📦 Project Delivered</span>
  <h1>Your project is ready</h1>

  <p>Good news — the final package for <strong>{{ $projectName }}</strong> has been delivered{{ $downloadUrl ? ' and is ready to download' : ', attached to this email' }}.</p>

  <div class="receipt-box">
    <table>
      @if($reference)
      <tr>
        <td>Reference</td>
        <td>{{ $reference }}</td>
      </tr>
      @endif
      <tr>
        <td>Project</td>
        <td>{{ $projectName }}</td>
      </tr>
      @if($fileName)
      <tr>
        <td>File</td>
        <td>{{ $fileName }}</td>
      </tr>
      @endif
    </table>
  </div>

  @if($downloadUrl)
  <a class="btn" href="{{ $downloadUrl }}">View &amp; Download</a>
  @else
  <p>You'll find it attached to this email.</p>
  @endif

  <p>If you have any questions, just reply to this email.</p>

  <div class="footer">
    This is an automated message from {{ $companyName }}.
  </div>
</div>
</body>
</html>
