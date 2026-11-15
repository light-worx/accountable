<?php

namespace App\Models;

use App\Enums\EntityRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/** One person's role in one society / circuit. Every grant, change and removal is recorded. */
class EntityUser extends Model
{
    use LogsActivity;

    protected $table = 'entity_user';

    protected $fillable = ['entity_id', 'user_id', 'role'];

    protected function casts(): array
    {
        return ['role' => EntityRole::class];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('access');
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->merge([
            'entity' => $this->entity?->name,
            'person' => $this->user?->name,
        ]);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}