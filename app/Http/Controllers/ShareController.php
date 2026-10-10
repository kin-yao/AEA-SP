<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\FinancePdf;

/** Opens an invoice or receipt PDF from a signed, expiring link. The signature is the permission. */
class ShareController extends Controller
{
    public function invoice(Invoice $invoice)
    {
        abort_if($invoice->status === 'Draft', 404);

        return $this->pdf(FinancePdf::invoice($invoice), $invoice->reference);
    }

    public function receipt(Payment $payment)
    {
        return $this->pdf(FinancePdf::receipt($payment), $payment->reference);
    }

    private function pdf(string $bytes, string $name)
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $name).'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
