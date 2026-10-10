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
  .invoice-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px 24px; margin: 20px 0; }
  .invoice-box table { width: 100%; border-collapse: collapse; }
  .invoice-box td { padding: 5px 0; font-size: 14px; color: #475569; }
  .invoice-box td:last-child { text-align: right; font-weight: 600; color: #0f172a; }
  .amount-row td { font-size: 18px; font-weight: 800; color: #0f172a; padding-top: 12px; border-top: 2px solid #e2e8f0; }
  .overdue-banner { background: #fef2f2; border: 1px solid #fecaca; border-radius: 9px; padding: 12px 16px; margin: 0 0 18px; color: #b91c1c; font-size: 13px; font-weight: 600; }
  .note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 9px; padding: 14px 16px; margin: 18px 0; color: #78350f; font-size: 13.5px; line-height: 1.6; white-space: pre-line; }
  .btn { display: inline-block; padding: 14px 32px; background: #2563eb; color: #fff !important; text-decoration: none; border-radius: 9px; font-weight: 700; font-size: 15px; margin: 8px 0 20px; }
  .link-box { background: #f1f5f9; border-radius: 8px; padding: 12px 16px; margin: 16px 0; font-size: 12px; color: #64748b; word-break: break-all; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
  .letterhead { margin-bottom: 20px; }
  .letterhead img { display: block; max-width: 160px; max-height: 56px; border: 0; }
</style>
</head>
<body>
<div class="container">
  {{-- Same letterhead resolution as emails/invoice.blade.php — for a Brand
       Invoice this is the BRAND's name and logo, so a reminder never arrives
       under a different identity than the invoice it is chasing. --}}
  <div class="letterhead">
    @if(!empty($issuerLogoUrl))
      <img src="{{ $issuerLogoUrl }}" alt="{{ $issuerName }}" width="160">
    @endif
    <div class="logo" style="margin: {{ empty($issuerLogoUrl) ? '0 0 24px' : '10px 0 0' }};">{{ $issuerName }}</div>
  </div>

  @if($daysLate > 0)
    <div class="overdue-banner">
      This invoice is {{ $daysLate }} {{ $daysLate === 1 ? 'day' : 'days' }} past its due date.
    </div>
  @endif

  <h1>Payment reminder</h1>
  <p>Hi{{ $clientName ? ' ' . $clientName : '' }},</p>
  <p>
    This is a friendly reminder about invoice <strong>{{ $invoiceNumber }}</strong>@if($purpose) for {{ $purpose }}@endif.
    @if($amountDue > 0)
      There is still {{ $currency }} {{ number_format($amountDue, 2) }} outstanding.
    @endif
  </p>

  {{-- The Finance user's own words, when they wrote any. Escaped by Blade
       and rendered with white-space: pre-line, so line breaks survive but
       markup never does. --}}
  @if(!empty($customMessage))
    <div class="note">{{ $customMessage }}</div>
  @endif

  <div class="invoice-box">
    <table>
      <tr><td>Invoice number</td><td>{{ $invoiceNumber }}</td></tr>
      @if($issueDate)<tr><td>Issued</td><td>{{ $issueDate }}</td></tr>@endif
      @if($dueDate)<tr><td>Due</td><td>{{ $dueDate }}</td></tr>@endif
      <tr><td>Invoice total</td><td>{{ $currency }} {{ number_format($totalAmount, 2) }}</td></tr>
      @if($paidAmount > 0)
        <tr><td>Already paid</td><td>&minus; {{ $currency }} {{ number_format($paidAmount, 2) }}</td></tr>
      @endif
      <tr class="amount-row"><td>Amount due</td><td>{{ $currency }} {{ number_format($amountDue, 2) }}</td></tr>
    </table>
  </div>

  @if($paymentUrl)
    <a href="{{ $paymentUrl }}" class="btn">View &amp; Pay Invoice</a>
    <div class="link-box">Or copy this link into your browser:<br>{{ $paymentUrl }}</div>
  @endif

  <p>If you have already sent this payment, please ignore this reminder &mdash; and thank you.</p>

  <div class="footer">
    {{ $issuerName }}
    @if(!empty($issuerEmail)) &middot; {{ $issuerEmail }}@endif
    @if(!empty($issuerPhone)) &middot; {{ $issuerPhone }}@endif
  </div>
</div>
</body>
</html>
