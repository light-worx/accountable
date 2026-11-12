<?php

namespace App\Models;

use App\Enums\PayeeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payee extends Model
{
    use LogsActivity;

    protected $fillable = ['entity_id', 'name', 'type', 'pf_number', 'in_our_circuit', 'is_active'];

    protected function casts(): array
    {
        return ['type' => PayeeType::class, 'in_our_circuit' => 'boolean', 'is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
