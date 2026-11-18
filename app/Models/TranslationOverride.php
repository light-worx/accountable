<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TranslationOverride extends Model
{
    use LogsActivity;

    protected $fillable = ['locale', 'source', 'translation'];

    protected static function booted(): void
    {
        static::saving(function (TranslationOverride $row) {
            $row->source_hash = hash('sha256', $row->source);
            $row->updated_by = auth()->id();
        });

        static::saved(fn (TranslationOverride $row) => Cache::forget("translation_overrides.{$row->locale}"));
        static::deleted(fn (TranslationOverride $row) => Cache::forget("translation_overrides.{$row->locale}"));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['locale', 'source', 'translation'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('translations');
    }
}