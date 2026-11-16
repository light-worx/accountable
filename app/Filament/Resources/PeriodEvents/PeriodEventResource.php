<?php

namespace App\Filament\Resources\PeriodEvents;

use App\Filament\Resources\PeriodEvents\Pages\ListPeriodEvents;
use App\Models\PeriodEvent;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** The history of closing and reopening months. Read-only. */
class PeriodEventResource extends Resource
{
    protected static ?string $model = PeriodEvent::class;
    protected static ?string $navigationLabel = 'Month-end';
    protected static ?string $modelLabel = 'month-end event';
    protected static string|UnitEnum|null $navigationGroup = 'Record';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;
    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('month')->label('Month')
                    ->state(fn (PeriodEvent $record) => CarbonImmutable::create($record->year, $record->month, 1)->format('F Y')),
                TextColumn::make('action')->label('What happened')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'close' ? 'Closed' : 'Reopened')
                    ->color(fn ($state) => $state === 'close' ? 'success' : 'warning'),
                TextColumn::make('user.name')->label('By'),
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i'),
                TextColumn::make('reason')->label('Reason')->wrap()->placeholder('—'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPeriodEvents::route('/')];
    }
}