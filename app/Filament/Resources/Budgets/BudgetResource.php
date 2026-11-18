<?php

namespace App\Filament\Resources\Budgets;

use App\Filament\Resources\Budgets\Pages\ListBudgets;
use App\Filament\Resources\Budgets\Pages\ViewBudget;
use App\Filament\Resources\Budgets\RelationManagers\LinesRelationManager;
use App\Models\Budget;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use App\Filament\Resources\Budgets\RelationManagers\AssessmentsRelationManager;

/** Budgets are created and changed through the buttons on these pages: never by editing a record directly. */
class BudgetResource extends Resource
{
    protected static ?string $model = Budget::class;
    protected static ?string $navigationLabel = 'Budget';
    protected static ?string $modelLabel = 'budget';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;
    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return __('Setup');   // use __('Record') in PeriodEventResource
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('year')->label('Year'),
            TextEntry::make('version')->label('Version')->formatStateUsing(fn ($state) => "Version {$state}"),
            TextEntry::make('status')->label('Status')->badge()
                ->formatStateUsing(fn ($state) => ucfirst($state))
                ->color(fn ($state) => match ($state) {
                    'approved' => 'success',
                    'draft' => 'warning',
                    default => 'gray',
                }),
            TextEntry::make('note')->label('Approved by / at')->placeholder('Not approved yet'),
            TextEntry::make('approved_at')->label('Approved on')->dateTime('d M Y')->placeholder('—'),
            TextEntry::make('change_reason')->label('Reason for this version')->placeholder('—')->columnSpanFull(),
            TextEntry::make('income_total')->label('Budgeted income')
                ->state(fn (Budget $record) => Money::format($record->totals()['income'])),
            TextEntry::make('expense_total')->label('Budgeted payments')
                ->state(fn (Budget $record) => Money::format($record->totals()['expense'])),
            TextEntry::make('surplus_total')->label('Budgeted surplus / (deficit)')
                ->state(fn (Budget $record) => Money::format($record->totals()['surplus'])),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('year', 'desc')
            ->columns([
                TextColumn::make('year')->sortable(),
                TextColumn::make('version')->formatStateUsing(fn ($state) => "Version {$state}"),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'draft' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('note')->label('Approved by / at')->placeholder('—')->wrap(),
                TextColumn::make('income')->label('Income')->alignEnd()
                    ->state(fn (Budget $r) => Money::format($r->totals()['income'])),
                TextColumn::make('expense')->label('Payments')->alignEnd()
                    ->state(fn (Budget $r) => Money::format($r->totals()['expense'])),
            ])
            ->recordActions([ViewAction::make()->label('Open')]);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class, AssessmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBudgets::route('/'),
            'view' => ViewBudget::route('/{record}'),
        ];
    }
}