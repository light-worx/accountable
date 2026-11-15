<?php

namespace App\Filament\Pages\Tenancy;

use App\Services\ChurchApi\Directory;
use App\Services\ChurchApi\EntityRegistrar;
use App\Services\ChurchApi\SyncException;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class RegisterEntity extends RegisterTenant
{
    protected bool $warned = false;

    public static function getLabel(): string
    {
        return 'Set up your society or circuit';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Radio::make('kind')->label('What are you setting up accounts for?')
                ->options(['society' => 'A society (congregation)', 'circuit' => 'A circuit'])
                ->default('society')->required()->live(),

            Select::make('circuit')->label('Circuit')
                ->options(fn () => $this->load(fn (Directory $d) => $d->circuitOptions()))
                ->searchable()->required()->live()
                ->afterStateUpdated(fn (Set $set) => $set('society', null))
                ->helperText('Type to search. The list comes from church.net.za.'),

            Select::make('society')->label('Society')
                ->options(fn (Get $get) => $this->load(fn (Directory $d) => $d->societyOptions($get('circuit') !== null ? (string) $get('circuit') : null)))
                ->searchable()->required()
                ->visible(fn (Get $get) => $get('kind') === 'society')
                ->helperText('Choose the circuit first.'),

            TextInput::make('number')->label('Society number (as used on the 4S)')
                ->maxLength(20)
                ->visible(fn (Get $get) => $get('kind') === 'society'),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        try {
            return app(EntityRegistrar::class)->register(
                auth()->user(),
                $data['kind'],
                (string) $data['circuit'],
                isset($data['society']) ? (string) $data['society'] : null,
                $data['number'] ?? null,
            );
        } catch (SyncException $e) {
            throw ValidationException::withMessages(['data.circuit' => $e->getMessage()]);
        }
    }

    /** Reads a list from church.net.za; if it cannot be reached, says so once instead of crashing. */
    private function load(Closure $callback): array
    {
        try {
            return $callback(app(Directory::class));
        } catch (SyncException $e) {
            if (! $this->warned) {
                $this->warned = true;
                Notification::make()->title('Could not load the list')->body($e->getMessage())->danger()->send();
            }

            return [];
        }
    }
}