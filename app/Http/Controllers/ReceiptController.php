<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\SubscriptionPayment;
use App\Services\Receipts\ReceiptService;

/** Opens a receipt PDF from its signed link (sent by the app; no sign-in needed). */
class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receipts,
    ) {}

    public function payment(Payment $payment)
    {
        return $this->receipts->paymentPdf($payment);
    }

    public function invoice(SubscriptionPayment $payment)
    {
        // Only paid plans have an invoice.
        abort_unless($payment->status === 'paid' && $payment->invoice_number, 404);

        return $this->receipts->invoicePdf($payment);
    }
}
