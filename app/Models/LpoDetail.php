<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The customer's LPO is the binding agreement. It starts as a copy of the
 * quotation it points to, and its lines can be edited when prices are
 * negotiated. Invoices are raised from these lines, not from the quotation.
 */
class LpoDetail extends Model
{
    protected $table = 'lpo_details';

    protected $fillable = [
        'document_id',
        'quotation_id',
        'received_via',
        'file_path',
        'labour_minor',
        'vat_rate',
        'currency_code',
        'change_note',
        'edited_by',
        'edited_at',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:3',
        'edited_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LpoItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /** Copy the quotation's lines in the first time the LPO needs them. Older LPOs get theirs on first use. */
    public function ensureLines(): void
    {
        if ($this->labour_minor !== null) {
            return;
        }

        DB::transaction(function () {
            $q = $this->quotation()->with('items')->first();

            foreach (($q?->items ?? []) as $i => $item) {
                $this->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'rate_minor' => $item->rate_minor,
                    'sort_order' => $i,
                ]);
            }

            $this->update([
                'labour_minor' => (int) ($q?->labour_minor ?? 0),
                'vat_rate' => $q?->vat_rate ?? ((float) setting('vat_rate') / 100),
                'currency_code' => $q?->currency_code ?? currency(),
            ]);
        });

        $this->unsetRelation('items');
    }

    public function itemsSubtotalMinor(): int
    {
        return (int) $this->items->sum(fn (LpoItem $i) => $i->amountMinor());
    }

    public function subtotalMinor(): int
    {
        return $this->itemsSubtotalMinor() + (int) $this->labour_minor;
    }

    public function vatMinor(): int
    {
        return (int) round($this->subtotalMinor() * (float) $this->vat_rate);
    }

    public function totalMinor(): int
    {
        return $this->subtotalMinor() + $this->vatMinor();
    }
}
