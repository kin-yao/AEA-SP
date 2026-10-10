<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;

/** Makes the invoice and receipt PDFs, and the temporary links used to share them. */
class FinancePdf
{
    public const LINK_DAYS = 14;

    public static function invoice(Invoice $invoice): string
    {
        $invoice->loadMissing(['customer.branch', 'items', 'payments', 'workOrder.sourceRequest', 'workOrder.sourceQuotation.lpoDetail']);

        return Pdf::loadView('pdfs.invoice', [
            'invoice' => $invoice,
            'company' => Settings::company(),
            'banks' => BankAccount::forDocument($invoice->currency_code, $invoice->customer?->branch?->country_id),
        ])->output();
    }

    public static function receipt(Payment $payment): string
    {
        $payment->loadMissing(['invoice', 'customer', 'recordedBy']);

        return Pdf::loadView('pdfs.receipt', [
            'payment' => $payment,
            'invoice' => $payment->invoice,
            'company' => Settings::company(),
        ])->output();
    }

    /** A link that opens the PDF without signing in, good for 14 days. */
    public static function invoiceLink(Invoice $invoice): string
    {
        return URL::temporarySignedRoute('share.invoice', now()->addDays(self::LINK_DAYS), ['invoice' => $invoice->id]);
    }

    public static function receiptLink(Payment $payment): string
    {
        return URL::temporarySignedRoute('share.receipt', now()->addDays(self::LINK_DAYS), ['payment' => $payment->id]);
    }
}
