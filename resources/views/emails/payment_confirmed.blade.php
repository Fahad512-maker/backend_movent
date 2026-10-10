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
  .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; background: #dcfce7; color: #16a34a; margin-bottom: 20px; }
  .receipt-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px 24px; margin: 20px 0; }
  .receipt-box table { width: 100%; border-collapse: collapse; }
  .receipt-box td { padding: 6px 0; font-size: 14px; color: #475569; }
  .receipt-box td:last-child { text-align: right; font-weight: 600; color: #0f172a; }
  .amount-row td { font-size: 18px; font-weight: 800; color: #16a34a; padding-top: 12px; border-top: 2px solid #e2e8f0; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="container">
  <div class="logo">{{ $companyName }}</div>
  <span class="badge">✅ Payment Confirmed</span>
  <h1>Invoice {{ $invoiceNumber }} — Paid</h1>
  @if($forAdmin)
    <p><strong>{{ $customerName }}</strong> has successfully paid invoice <strong>{{ $invoiceNumber }}</strong> via {{ $gateway }}.</p>
  @else
    <p>Thank you, <strong>{{ $customerName }}</strong> — your payment for invoice <strong>{{ $invoiceNumber }}</strong> was successful.</p>
  @endif

  <div class="receipt-box">
    <table>
      <tr>
        <td>Receipt Number</td>
        <td>{{ $receiptNumber }}</td>
      </tr>
      <tr>
        <td>Invoice</td>
        <td>{{ $invoiceNumber }}</td>
      </tr>
      <tr>
        <td>Payment Date</td>
        <td>{{ $paymentDate }}</td>
      </tr>
      <tr>
        <td>Payment Method</td>
        <td>{{ $gateway }}</td>
      </tr>
      @if($transactionId)
      <tr>
        <td>Transaction ID</td>
        <td>{{ $transactionId }}</td>
      </tr>
      @endif
      <tr class="amount-row">
        <td>Amount Paid</td>
        <td>{{ $currency }} {{ number_format($amount, 2) }}</td>
      </tr>
    </table>
  </div>

  <div class="footer">
    <p>This is an automated notification from {{ $companyName }} on Movent.</p>
    <p>© {{ date('Y') }} {{ $companyName }}. All rights reserved.</p>
  </div>
</div>
</body>
</html>
