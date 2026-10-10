<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ReferenceSeries;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/** Raises an invoice for a finished job straight from its LPO or quotation, with no form. */
class InvoiceBuilder
{
    /** Why this job cannot be invoiced, or null when it can. */
    public static function blocker(WorkOrder $job): ?string
    {
        if ($job->invoices()->exists()) {
            return 'This job already has an invoice.';
        }

        if ($job->status !== 'Closed' && ! $job->documents()->where('type', 'rep')->where('status', 'Released')->exists()) {
            return 'This job is not finished yet. It must be closed, or its service report released.';
        }

        return null;
    }

    /** True when the prices are already agreed in an LPO or quotation, so no form is needed. */
    public static function canRaiseDirectly(WorkOrder $job): bool
    {
        $q = $job->sourceQuotation;

        return $q && ($q->lpoDetail || $q->items()->exists());
    }

    public static function raise(WorkOrder $job, int $by, bool $send = true): Invoice
    {
        if ($why = self::blocker($job)) {
            throw new \DomainException($why);
        }

        $q = $job->sourceQuotation;
        $lpo = $q?->lpoDetail;
        $lines = [];

        if ($lpo) {
            $lpo->ensureLines();
            $lpo->load('items');
            foreach ($lpo->items as $i) {
                $lines[] = [$i->description, $i->quantity, $i->rate_minor];
            }
            if ($lpo->labour_minor > 0) {
                $lines[] = ['Labour', 1, $lpo->labour_minor];
            }
            $vat = (float) $lpo->vat_rate;
            $currency = $lpo->currency_code ?: $q->currency_code;
        } elseif ($q) {
            foreach ($q->items as $i) {
                $lines[] = [$i->description, $i->quantity, $i->rate_minor];
            }
            if ($q->labour_minor > 0) {
                $lines[] = ['Labour', 1, $q->labour_minor];
            }
            $vat = (float) $q->vat_rate;
            $currency = $q->currency_code;
        } else {
            throw new \DomainException('There is no quotation or LPO to take the prices from.');
        }

        if ($lines === []) {
            throw new \DomainException('The quotation has no lines to invoice.');
        }

        $subtotal = (int) round(collect($lines)->sum(fn ($l) => round((float) $l[1] * (int) $l[2])));
        $total = $subtotal + (int) round($subtotal * $vat);

        return DB::transaction(function () use ($job, $by, $send, $lines, $vat, $currency, $total, $lpo) {
            $invoice = Invoice::create([
                'reference' => ReferenceSeries::next('invoice'),
                'customer_id' => $job->customer_id,
                'work_order_id' => $job->id,
                'issued_at' => now(),
                'due_at' => now()->addDays((int) setting('invoice_due_days')),
                'amount_minor' => $total,
                'vat_rate' => $vat,
                'currency_code' => $currency ?: $job->customer?->currencyCode() ?: currency(),
                'raised_by' => $by,
                'lpo_document_id' => $lpo?->document_id,
                'status' => $send ? 'Unpaid' : 'Draft',
            ]);

            foreach ($lines as [$desc, $qty, $rate]) {
                $invoice->items()->create(['description' => $desc, 'quantity' => $qty, 'rate_minor' => $rate]);
            }

            if ($send) {
                WorkflowNotifier::customer(
                    $job->customer,
                    'Invoice issued',
                    ["Invoice {$invoice->reference} for {$invoice->currency_code} ".number_format($invoice->amount_minor / 100, 2).' has been issued.', 'Due date: '.$invoice->due_at->format('d M Y')],
                    url('/invoices/'.$invoice->id),
                    'View invoice',
                );
            }

            return $invoice;
        });
    }
}
