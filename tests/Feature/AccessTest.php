<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_area_is_for_active_admins_only(): void
    {
        $panel = Filament::getPanel('system');
        $user = User::factory()->create();
        $this->assertFalse($user->canAccessPanel($panel));

        $user->forceFill(['is_admin' => true])->save();
        $this->assertTrue($user->fresh()->canAccessPanel($panel));

        $user->forceFill(['is_active' => false])->save();
        $this->assertFalse($user->fresh()->canAccessPanel($panel));
    }

    public function test_someone_with_no_society_can_open_the_main_area_but_not_a_society(): void
    {
        $panel = Filament::getPanel('admin');
        $user = User::factory()->create();
        $this->assertTrue($user->canAccessPanel($panel)); // they are sent to the set-up page

        $entity = Entity::create(['type' => 'society', 'name' => 'Test Society']);
        $this->assertFalse($user->canAccessTenant($entity));

        $entity->users()->attach($user->id, ['role' => 'viewer']);
        $this->assertTrue($user->canAccessTenant($entity));

        $entity->update(['is_active' => false]);
        $this->assertFalse($user->canAccessTenant($entity));
    }

    public function test_role_changes_are_written_to_the_audit_log(): void
    {
        $this->actingAs(User::factory()->create());

        $entity = Entity::create(['type' => 'society', 'name' => 'Test Society']);
        $person = User::factory()->create();

        $membership = EntityUser::create(['entity_id' => $entity->id, 'user_id' => $person->id, 'role' => 'viewer']);
        $membership->update(['role' => 'treasurer']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'access',
            'description' => 'updated',
            'subject_type' => EntityUser::class,
        ]);
    }
}