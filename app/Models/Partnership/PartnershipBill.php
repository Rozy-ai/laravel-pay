<?php

namespace App\Models\Partnership;

use App\Models\Partnership;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnershipBill extends Model
{
    use HasFactory;

    protected $table = 'partnerships_bills';

    const CREATED_AT = NULL;
    const UPDATED_AT = NULL;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'sum',
        'partnership_id',
        'additional_partnership_id',
        'type',
        'is_required',
        'date',
        'days',
        'paid',
        'paid_date',
        'is_enabled',
        'is_pro',
        'pro_sum',
    ];

    public function getServiceNameAttribute(): string
    {
        return $this->service ? __("pay.{$this->service}") : "";
    }

    public function partnership(): belongsTo
    {
        return $this->belongsTo(Partnership::class);
    }
}
