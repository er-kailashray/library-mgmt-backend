<?php

namespace App\Services\Receipts;

use App\Models\Payment;
use App\Models\SubscriptionPayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF receipts.
 *  - Member fee receipts: from the library to its student, one per recorded payment (admission,
 *    fee payment, renewal, refund). The owner sends the link on WhatsApp.
 *  - Plan invoices: from LibriSpace (KailashRay) to the library owner, one per paid plan.
 * Links are signed (no sign-in needed, can't be guessed or edited) and don't expire, since a
 * receipt is a lasting record. Changing APP_KEY invalidates them.
 */
class ReceiptService
{
    public function paymentUrl(Payment $payment): string
    {
        return URL::signedRoute('receipts.payment', ['payment' => $payment->id]);
    }

    public function paymentNumber(Payment $payment): string
    {
        return sprintf('RC-%d-%05d', $payment->library_id, $payment->id);
    }

    public function invoiceUrl(SubscriptionPayment $payment): string
    {
        return URL::signedRoute('receipts.invoice', ['payment' => $payment->id]);
    }

    public function paymentPdf(Payment $payment): Response
    {
        $payment->loadMissing(['member', 'library.user']);
        $library = $payment->library;
        $refund = $payment->amount < 0;
        $amount = abs((float) $payment->amount);

        $pdf = Pdf::loadView('receipts.payment', [
            'title' => $refund ? 'Refund receipt' : 'Fee receipt',
            'refund' => $refund,
            'number' => $this->paymentNumber($payment),
            'date' => $payment->payment_date->format('j M Y'),
            'library' => [
                'name' => $library->library_name,
                'address' => $library->address,
                'phone' => $library->user?->phone,
            ],
            'member' => [
                'name' => $payment->member?->name,
                'phone' => $payment->member?->phone,
            ],
            'seats' => collect($payment->seats ?? [])->map(fn ($seat) => trim(
                (isset($seat['seat_number']) ? 'Seat '.$seat['seat_number'] : '').
                (isset($seat['batch_name']) ? ' · '.$seat['batch_name'] : ''),
                ' ·'
            ))->filter()->values()->all(),
            'period' => $payment->period_start && $payment->period_end
                ? $payment->period_start->format('j M Y').' – '.$payment->period_end->format('j M Y')
                : null,
            'amount' => $amount,
            'amountWords' => AmountInWords::rupees($amount),
            'status' => $payment->status->label(),
            'mode' => config("library.payment_modes.{$payment->payment_mode}") ?? config('library.payment_mode_fallback_label'),
            'notes' => $payment->notes,
        ])->setPaper('a5')->setOption(['isFontSubsettingEnabled' => true]);

        return $pdf->stream($this->paymentNumber($payment).'.pdf');
    }

    public function invoicePdf(SubscriptionPayment $payment): Response
    {
        $payment->loadMissing(['plan', 'subscription.library.user']);
        $library = $payment->subscription->library;
        $period = $payment->payload['period'] ?? null;
        $months = $payment->plan?->duration_months;

        $pdf = Pdf::loadView('receipts.invoice', [
            'number' => $payment->invoice_number,
            'date' => ($payment->paid_at ?? $payment->created_at)->format('j M Y'),
            'seller' => [
                'name' => config('library.business_name'),
                'address' => config('library.business_address'),
                'email' => config('library.support_email'),
                'gstin' => config('library.business_gstin'),
            ],
            'buyer' => [
                'library' => $library->library_name,
                'owner' => $library->user?->name,
                'phone' => $library->user?->phone,
                'address' => $library->address,
            ],
            'item' => 'LibriSpace '.($payment->plan?->name ?? '').' plan'.($months ? ' ('.$months.' '.($months === 1 ? 'month' : 'months').')' : ''),
            'period' => $period
                ? Carbon::parse($period['starts'])->format('j M Y').' – '.Carbon::parse($period['ends'])->format('j M Y')
                : null,
            'amount' => (float) $payment->amount,
            'amountWords' => AmountInWords::rupees((float) $payment->amount),
            'reference' => $payment->provider_payment_id,
        ])->setPaper('a4')->setOption(['isFontSubsettingEnabled' => true]);

        return $pdf->stream(($payment->invoice_number ?? 'invoice').'.pdf');
    }
}
