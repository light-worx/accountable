<?php

namespace App\Filament\Resources\Funds;

use App\Filament\Resources\Funds\Pages\ManageFunds;
use App\Filament\Support\SetupResource;
use App\Models\Fund;
use App\Support\Money;
use App\Support\MoneyInput;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class FundResource extends SetupResource
{
    protected static ?string $model = Fund::class;
    protected static ?string $navigationLabel = 'Funds';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Fund name')
                ->placeholder('e.g. Building Fund, Manse Maintenance Fund')
                ->required()->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('entity_id', Filament::getTenant()->id)),
            MoneyInput::cents('opening_balance_cents', 'Balance at the start (1 January)')
                ->helperText('Money already held for this fund when you begin. All fund balances must add up to all account balances.')
                ->disabled(fn ($record) => $record?->lines()->exists()),
            Toggle::make('is_active')->label('In use')->default(true)
                ->disabled(fn ($record) => (bool) $record?->is_general),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->withSum('lines as movement_cents', 'amount_cents'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('balance')->label('Balance now')->alignEnd()
                    ->state(fn (Fund $r) => Money::format($r->opening_balance_cents + (int) $r->movement_cents)),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->recordActions([EditAction::make()->modalHeading('Edit fund')])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageFunds::route('/')];
    }
}
