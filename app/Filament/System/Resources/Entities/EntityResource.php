<?php

namespace App\Filament\System\Resources\Entities;

use App\Enums\EntityType;
use App\Filament\System\Resources\Entities\Pages\ManageEntities;
use App\Models\Entity;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Societies and circuits that have been set up here. They are created by users choosing them from church.net.za. */
class EntityResource extends Resource
{
    protected static ?string $model = Entity::class;
    protected static ?string $navigationLabel = 'Societies & circuits';
    protected static ?string $modelLabel = 'society / circuit';
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;
    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->label('What is it?')->options(EntityType::class)->disabled(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('number')->label('Number (as used by the MCO)')->maxLength(20),
            Toggle::make('allow_overdrawn')->label('Allow balances to go below zero')
                ->helperText('Normally off. When off, the system refuses payments that would take an account or fund below zero.'),
            Toggle::make('is_active')->label('In use')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('parent.name')->label('Circuit')->placeholder('—'),
                TextColumn::make('number')->placeholder('—'),
                TextColumn::make('users_count')->label('People')->counts('users'),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->filters([SelectFilter::make('type')->options(EntityType::class)])
            ->recordActions([EditAction::make()->modalHeading('Edit')])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageEntities::route('/')];
    }
}