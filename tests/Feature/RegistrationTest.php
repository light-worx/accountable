<?php

namespace Tests\Feature;

use App\Enums\EntityRole;
use App\Models\Entity;
use App\Models\EntityUser;
use App\Models\User;
use App\Services\ChurchApi\Directory;
use App\Services\ChurchApi\EntityRegistrar;
use App\Services\ChurchApi\SyncException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['church_api.url' => 'https://church.net.za/api/v1', 'church_api.token' => 'test-token']);
    }

    private function fakeApi(): void
    {
        Http::fake([
            'church.net.za/api/v1/circuits*' => Http::response(['data' => [[
                'id' => 10, 'slug' => '10', 'name' => 'Circuit A', 'reference' => 1010, 'active' => false,
                'district' => ['id' => 5, 'slug' => 'central', 'name' => 'Central District'],
            ]]]),
            'church.net.za/api/v1/societies*' => Http::response(['data' => [
                ['id' => 100, 'slug' => 'society-x', 'name' => 'Society X', 'circuit' => ['id' => 10, 'slug' => '10']],
                ['id' => 101, 'slug' => 'society-y', 'name' => 'Society Y', 'circuit' => ['id' => 10, 'slug' => '10']],
                ['id' => 200, 'slug' => 'elsewhere', 'name' => 'Elsewhere', 'circuit' => ['id' => 11, 'slug' => '11']],
            ]]),
        ]);
    }

    public function test_dropdown_options_come_from_the_api_and_ignore_other_circuits(): void
    {
        $this->fakeApi();
        $directory = app(Directory::class);

        $this->assertSame([10 => 'Circuit A — Central District'], $directory->circuitOptions());
        $this->assertSame([100 => 'Society X', 101 => 'Society Y'], $directory->societyOptions('10'));
    }

    public function test_registering_a_society_makes_the_user_its_treasurer(): void
    {
        $this->fakeApi();
        $user = User::factory()->create();

        $society = app(EntityRegistrar::class)->register($user, 'society', '10', '100', '0042');

        $this->assertSame('Society X', $society->name);
        $this->assertSame('0042', $society->number);
        $this->assertSame('Circuit A', $society->parent->name);
        $this->assertSame('1010', $society->parent->number);
        $this->assertSame(EntityRole::Treasurer, $user->roleIn($society));
        $this->assertNotNull($society->generalFund());
        $this->assertNull($user->roleIn($society->parent)); // the parent circuit stays unclaimed
        $this->assertSame(2, Entity::count());              // only what was chosen, not the whole directory
    }

    public function test_a_second_person_cannot_claim_a_society_that_is_already_set_up(): void
    {
        $this->fakeApi();
        $registrar = app(EntityRegistrar::class);
        $registrar->register(User::factory()->create(), 'society', '10', '100', null);

        $this->expectException(SyncException::class);
        $registrar->register(User::factory()->create(), 'society', '10', '100', null);
    }

    public function test_registering_again_is_harmless(): void
    {
        $this->fakeApi();
        $registrar = app(EntityRegistrar::class);
        $user = User::factory()->create();

        $first = $registrar->register($user, 'society', '10', '100', null);
        $second = $registrar->register($user, 'society', '10', '100', null);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Entity::where('type', 'society')->count());
        $this->assertSame(1, EntityUser::count());
    }

    public function test_a_circuit_that_was_only_a_parent_can_be_claimed_later(): void
    {
        $this->fakeApi();
        $registrar = app(EntityRegistrar::class);
        $registrar->register(User::factory()->create(), 'society', '10', '100', null);

        $treasurer = User::factory()->create();
        $circuit = $registrar->register($treasurer, 'circuit', '10');

        $this->assertSame('circuit', $circuit->type->value);
        $this->assertSame(EntityRole::Treasurer, $treasurer->roleIn($circuit));
        $this->assertSame(1, Entity::where('type', 'circuit')->count());
    }

    public function test_an_unreachable_directory_gives_a_plain_error(): void
    {
        Http::fake(['church.net.za/*' => Http::response('', 500)]);

        $this->expectException(SyncException::class);
        app(Directory::class)->circuitOptions();
    }
}