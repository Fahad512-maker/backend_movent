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
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="container">
  <div class="logo">{{ $companyName }}</div>
  <span class="badge">✅ Project Activated</span>
  <h1>Your project is now active</h1>

  <p>Hi {{ $recipientName }}, good news — <strong>{{ $projectName }}</strong> has been activated and work is underway.</p>

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
      <tr>
        <td>Status</td>
        <td>Active</td>
      </tr>
      @if($startDate)
      <tr>
        <td>Start date</td>
        <td>{{ $startDate }}</td>
      </tr>
      @endif
      @if($deadline)
      <tr>
        <td>Deadline</td>
        <td>{{ $deadline }}</td>
      </tr>
      @endif
      @if($description)
      <tr>
        <td>Details</td>
        {{-- The description is markup-formatted now (see
             frontend/components/ui/RichTextField.tsx). MarkupText::toHtml()
             escapes every text fragment itself and emits a fixed tag
             whitelist with http/https/mailto hrefs only, so {!! !!} here is
             never raw user HTML — printing $description with {{ }} instead
             would show the client literal "**bold**" markers. --}}
        <td>{!! \App\Support\MarkupText::toHtml($description) !!}</td>
      </tr>
      @endif
    </table>
  </div>

  <p>We'll keep you posted as work progresses. If you have any questions in the meantime, just reply to this email.</p>

  <div class="footer">
    This is an automated message from {{ $companyName }}.
  </div>
</div>
</body>
</html>
