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
  .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; background: #fef3c7; color: #92400e; margin-bottom: 20px; }
  .receipt-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px 24px; margin: 20px 0; }
  .receipt-box table { width: 100%; border-collapse: collapse; }
  .receipt-box td { padding: 6px 0; font-size: 14px; color: #475569; }
  .receipt-box td:last-child { text-align: right; font-weight: 600; color: #0f172a; }
  .amount-row td { font-size: 18px; font-weight: 800; color: #b45309; padding-top: 12px; border-top: 2px solid #e2e8f0; }
  .action-note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400e; margin-bottom: 20px; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="container">
  <div class="logo">{{ $companyName }}</div>
  <span class="badge">⏳ Awaiting Full Payment</span>
  <h1>Thank you for your payment</h1>

  <p>Hi {{ $customerName }}, we’ve received your part payment towards invoice <strong>{{ $invoiceNumber }}</strong>@if($projectName) for <strong>{{ $projectName }}</strong>@endif.</p>

  <div class="action-note">
    Your project will start once invoice {{ $invoiceNumber }} is paid in full.
  </div>

  <div class="receipt-box">
    <table>
      <tr>
        <td>Invoice</td>
        <td>{{ $invoiceNumber }}</td>
      </tr>
      <tr>
        <td>Invoice total</td>
        <td>{{ $currency }} {{ number_format($total, 2) }}</td>
      </tr>
      <tr>
        <td>Paid so far</td>
        <td>{{ $currency }} {{ number_format($paid, 2) }}</td>
      </tr>
      @if($dueDate)
      <tr>
        <td>Due date</td>
        <td>{{ $dueDate }}</td>
      </tr>
      @endif
      <tr class="amount-row">
        <td>Remaining balance</td>
        <td>{{ $currency }} {{ number_format($remaining, 2) }}</td>
      </tr>
    </table>
  </div>

  <p>Once the remaining balance is settled we’ll set your project up and keep you posted on its progress.</p>
  <p>If you have any questions about this invoice, just reply to this email.</p>

  <div class="footer">
    This is an automated message from {{ $companyName }}.
  </div>
</div>
</body>
</html>
