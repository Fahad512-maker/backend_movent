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
  .action-note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400e; margin-bottom: 20px; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="container">
  <div class="logo">{{ $companyName }}</div>
  <span class="badge">💰 Payment Received</span>
  <h1>Payment for Invoice {{ $invoiceNumber }}</h1>
  <p>A payment has been submitted for invoice <strong>{{ $invoiceNumber }}</strong> by <strong>{{ $customerName }}</strong>.</p>

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
        <td>Customer</td>
        <td>{{ $customerName }}</td>
      </tr>
      <tr>
        <td>Payment Date</td>
        <td>{{ $paymentDate }}</td>
      </tr>
      <tr>
        <td>Method</td>
        <td>{{ $method }}</td>
      </tr>
      <tr>
        <td>Invoice Status</td>
        <td>{{ ucfirst(str_replace('_', ' ', $invoiceStatus)) }}</td>
      </tr>
      <tr class="amount-row">
        <td>Amount</td>
        <td>{{ $currency }} {{ number_format($amount, 2) }}</td>
      </tr>
    </table>
  </div>

  <div class="action-note">
    ⚠️ This payment is <strong>pending verification</strong>. Please log in to your Movent dashboard to confirm or reject this payment.
  </div>

  <div class="footer">
    <p>This is an automated notification from {{ $companyName }} on Movent.</p>
    <p>© {{ date('Y') }} {{ $companyName }}. All rights reserved.</p>
  </div>
</div>
</body>
</html>
