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
  .status { display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: capitalize; }
  .status-sent     { background: #eff6ff; color: #2563eb; }
  .status-overdue  { background: #fef2f2; color: #dc2626; }
  .status-paid     { background: #ecfdf5; color: #059669; }
  .status-draft    { background: #f8fafc; color: #64748b; }
  .btn { display: inline-block; padding: 14px 32px; background: #2563eb; color: #fff !important; text-decoration: none; border-radius: 9px; font-weight: 700; font-size: 15px; margin: 8px 0 20px; }
  .link-box { background: #f1f5f9; border-radius: 8px; padding: 12px 16px; margin: 16px 0; font-size: 12px; color: #64748b; word-break: break-all; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
  /* Letterhead — the issuer's logo above their name. Sized in HTML
     attributes as well as CSS: Outlook ignores width/height in a style
     block on an <img>. */
  .letterhead { margin-bottom: 20px; }
  .letterhead img { display: block; max-width: 160px; max-height: 56px; border: 0; }
  /* Billed From / Billed To. A table, not flexbox or grid — neither works
     in Outlook, and this has to survive every mail client. */
  .parties { width: 100%; border-collapse: collapse; margin: 20px 0; }
  .parties td { width: 50%; vertical-align: top; padding: 0 12px 0 0; font-size: 13px; color: #475569; line-height: 1.55; }
  .parties td:last-child { padding: 0 0 0 12px; }
  .parties .label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #94a3b8; padding-bottom: 6px; }
  .parties .who { font-size: 14px; font-weight: 700; color: #0f172a; }
  .parties a { color: #475569; text-decoration: none; }
</style>
</head>
<body>
<div class="container">
  {{-- Letterhead. For a Brand Invoice $companyName and the logo are the
       BRAND's, resolved once in Invoice::brandingProfile() — the same values
       the share link, the client portal and the detail screen render, so one
       invoice can never go out as the company here and as a brand there.
       The name still shows under the logo: a logo alone doesn't tell a client
       who is billing them, and there may be no logo on file at all. --}}
  <div class="letterhead">
    @if(!empty($issuerLogoUrl))
      <img src="{{ $issuerLogoUrl }}" alt="{{ $companyName }}" width="160">
    @endif
    <div class="logo" style="margin: {{ empty($issuerLogoUrl) ? '0 0 24px' : '10px 0 0' }};">{{ $companyName }}</div>
  </div>
  <h1>Invoice {{ $invoiceNumber }}</h1>
  <p>Hi{{ $clientName ? ' ' . $clientName : '' }},</p>
  <p>Please find your invoice below. Click the button to view and pay online.</p>

  {{-- Billed From / Billed To. "From" is the issuer resolved above — brand
       or company; "To" is the client's own details. Website is brand-only
       (companies have no such field), and is stored already carrying a
       scheme (Support\Website) so it is safe to use straight as an href. --}}
  <table class="parties">
    <tr>
      <td class="label">Billed From</td>
      <td class="label">Billed To</td>
    </tr>
    <tr>
      <td>
        <div class="who">{{ $companyName }}</div>
        @if(!empty($issuerEmail))<div>{{ $issuerEmail }}</div>@endif
        @if(!empty($issuerPhone))<div>{{ $issuerPhone }}</div>@endif
        @if(!empty($issuerWebsite))<div><a href="{{ $issuerWebsite }}">{{ $issuerWebsite }}</a></div>@endif
        @if(!empty($issuerAddress))<div>{{ $issuerAddress }}</div>@endif
        @if(!empty($issuerCountry))<div>{{ $issuerCountry }}</div>@endif
      </td>
      <td>
        <div class="who">{{ $clientName ?: '—' }}</div>
        @if(!empty($clientEmail))<div>{{ $clientEmail }}</div>@endif
        @if(!empty($clientPhone))<div>{{ $clientPhone }}</div>@endif
        @if(!empty($clientAddress))<div>{{ $clientAddress }}</div>@endif
      </td>
    </tr>
  </table>

  <div class="invoice-box">
    <table>
      <tr>
        <td>Invoice Number</td>
        <td>{{ $invoiceNumber }}</td>
      </tr>
      @if(!empty($purpose))
      <tr>
        <td>Payment For</td>
        <td>{{ $purpose }}</td>
      </tr>
      @endif
      <tr>
        <td>Issue Date</td>
        <td>{{ $issueDate }}</td>
      </tr>
      @if($dueDate)
      <tr>
        <td>Due Date</td>
        <td>{{ $dueDate }}</td>
      </tr>
      @endif
      <tr>
        <td>Status</td>
        <td><span class="status status-{{ $status }}">{{ ucfirst(str_replace('_', ' ', $status)) }}</span></td>
      </tr>
      <tr class="amount-row">
        <td>Amount Due</td>
        <td>{{ $currency }} {{ number_format($amountDue, 2) }}</td>
      </tr>
    </table>
  </div>

  @if($paymentUrl)
  <a href="{{ $paymentUrl }}" class="btn">View &amp; Pay Invoice →</a>
  <div class="link-box">
    Or copy this link: {{ $paymentUrl }}
  </div>
  @endif

  @if($notes)
  <p style="font-size:13px; color:#64748b;"><strong>Note:</strong> {{ $notes }}</p>
  @endif

  <div class="footer">
    <p>This invoice was sent by {{ $companyName }}. If you have questions, reply to this email.</p>
    <p>© {{ date('Y') }} {{ $companyName }}. All rights reserved.</p>
  </div>
</div>
</body>
</html>
