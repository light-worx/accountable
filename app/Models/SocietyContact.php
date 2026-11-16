<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SocietyContact extends Model
{
    use LogsActivity;

    protected $fillable = ['entity_id', 'society_external_id', 'society_name', 'email'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['society_name', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('contacts');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}