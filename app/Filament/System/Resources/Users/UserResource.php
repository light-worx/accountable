<?php

namespace App\Filament\System\Resources\Users;

use App\Enums\EntityRole;
use App\Filament\System\Resources\Users\Pages\ManageUsers;
use App\Models\Entity;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationLabel = 'People & access';
    protected static ?string $modelLabel = 'person';
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
    protected static ?int $navigationSort = 20;

    public static function canDelete(Model $record): bool
    {
        return false; // switch people off instead, so history stays intact
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $isMe = fn ($record) => $record && $record->is(auth()->user());

        return $schema->components([
            Section::make('Person')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->label('Email address')->email()->required()->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')->password()->revealable()
                    ->label(fn (string $operation) => $operation === 'create'
                        ? 'Starting password'
                        : 'New password (leave blank to keep the current one)')
                    ->required(fn (string $operation) => $operation === 'create')
                    ->minLength(12)
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('At least 12 characters. Give it to the person privately; they can change it on their profile page.'),
                Toggle::make('is_active')->label('Allowed to sign in')->default(true)
                    ->disabled($isMe)
                    ->helperText('Switch off when someone leaves. Their history stays on record.'),
                Toggle::make('is_admin')->label('System administrator')->default(false)
                    ->disabled($isMe)
                    ->helperText('Can create societies and people and see the audit log. Keep this to one or two trusted people.'),
            ])->columns(2),

            Section::make('Access')->schema([
                Repeater::make('memberships')->relationship()
                    ->label('Which societies / circuits can they use, and as what?')
                    ->schema([
                        Select::make('entity_id')->label('Society / circuit')
                            ->options(fn () => Entity::query()->where('is_active', true)->orderBy('name')->get()
                                ->mapWithKeys(fn (Entity $e) => [$e->id => "{$e->name} — {$e->type->getLabel()}"]))
                            ->searchable()->required()
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        Select::make('role')->label('Role')
                            ->options(EntityRole::class)->required(),
                    ])
                    ->columns(2)->defaultItems(0)->reorderable(false)
                    ->addActionLabel('Give access to another society / circuit')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('memberships.entity'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('access')->label('Access')->wrap()
                    ->state(fn (User $r) => $r->memberships
                        ->map(fn ($m) => ($m->entity?->name ?? '?') . ' (' . ucfirst($m->role->value) . ')')
                        ->implode(', ') ?: '—'),
                IconColumn::make('two_factor')->label('2FA set up')->boolean()
                    ->state(fn (User $r) => filled($r->app_authentication_secret)),
                IconColumn::make('is_admin')->label('Admin')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->modalHeading('Edit person'),
                Action::make('resetMfa')
                    ->label('Reset 2FA')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('warning')
                    ->visible(fn (User $record) => filled($record->app_authentication_secret))
                    ->requiresConfirmation()
                    ->modalHeading('Reset two-factor login?')
                    ->modalDescription('Use this when the person has lost their phone. They will be asked to set up two-factor login again the next time they sign in. This is recorded in the audit log.')
                    ->action(function (User $record) {
                        $record->forceFill([
                            'app_authentication_secret' => null,
                            'app_authentication_recovery_codes' => null,
                        ])->save();

                        activity('access')->performedOn($record)->causedBy(auth()->user())->log('two-factor login reset');

                        Notification::make()->title('Two-factor login reset')->success()->send();
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}