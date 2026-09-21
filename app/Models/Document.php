<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    // Matches the type codes used throughout the prototype, keep them in
    // sync with the frontend rather than renaming casually.
    public const TYPE_REPORT = 'rep';
    public const TYPE_CERTIFICATE = 'cert';
    public const TYPE_LPO = 'lpo';
    public const TYPE_VOUCHER = 'mv';
    public const TYPE_DELIVERY_NOTE = 'dn';

    protected $fillable = [
        'reference',
        'type',
        'work_order_id',
        'customer_id',
        'status',
        'filed_by',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

        public function reportDetail(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ServiceReportDetail::class);
    }

        public function certificateDetail(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CertificateDetail::class);
    }

    // lpoDetail(), voucherDetail(), and deliveryNoteDetail() HasOne
    // relationships get added one at a time as we build each remaining
    // *_details table.
    
    // Which document types a role may see, mirrors DOCSCOPE in the
    // prototype, this is where "a technician never sees an LPO" gets
    // enforced later, at the query layer.
    public static function scopeForRole(string $role): array
    {
        return match ($role) {
            'Manager', 'Supervisor', 'Service Admin' => [
                self::TYPE_REPORT, self::TYPE_CERTIFICATE, self::TYPE_LPO,
                self::TYPE_VOUCHER, self::TYPE_DELIVERY_NOTE,
            ],
            'Technician' => [self::TYPE_REPORT, self::TYPE_VOUCHER, self::TYPE_DELIVERY_NOTE],
            'Finance' => [self::TYPE_LPO],
            'Customer' => [self::TYPE_REPORT, self::TYPE_CERTIFICATE, self::TYPE_VOUCHER, self::TYPE_DELIVERY_NOTE],
            default => [],
        };
    }
}