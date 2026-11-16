<?php

namespace App\Filament\Resources\Statements;

use App\Enums\EntityType;
use App\Filament\Resources\Statements\Pages\ListStatementSends;
use App\Models\StatementSend;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The log of assessment statements emailed to societies. Circuits only. */
class StatementSendResource extends Resource
{
    protected static ?string $model = StatementSend::class;
    protected static ?string $navigationLabel = 'Statements sent';
    protected static ?string $modelLabel = 'statement';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;
    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Filament::getTenant()?->type === EntityType::Circuit;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

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
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Queued')->dateTime('d M Y H:i'),
                TextColumn::make('period')->label('Month')
                    ->state(fn (StatementSend $record) => CarbonImmutable::create($record->year, $record->month, 1)->format('F Y')),
                TextColumn::make('society_name')->label('Society')->searchable(),
                TextColumn::make('recipients')->label('Sent to')->wrap(),
                TextColumn::make('balance')->label('Balance then')->alignEnd()
                    ->state(fn (StatementSend $record) => Money::format($record->balance_cents)),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'queued' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (StatementSend $record) => $record->error),
            ])
            ->filters([
                SelectFilter::make('status')->options(['sent' => 'Sent', 'queued' => 'Waiting', 'failed' => 'Failed', 'test' => 'Test copies']),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStatementSends::route('/')];
    }
}