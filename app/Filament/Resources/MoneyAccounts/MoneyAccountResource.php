<?php

namespace App\Filament\Resources\MoneyAccounts;

use App\Enums\MoneyAccountType;
use App\Filament\Resources\MoneyAccounts\Pages\ManageMoneyAccounts;
use App\Filament\Support\SetupResource;
use App\Models\MoneyAccount;
use App\Support\Money;
use App\Support\MoneyInput;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class MoneyAccountResource extends SetupResource
{
    protected static ?string $model = MoneyAccount::class;
    protected static ?string $modelLabel = 'bank / cash account';
    protected static ?string $navigationLabel = 'Bank & cash accounts';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Name')->placeholder('e.g. Cheque account, Petty cash')
                ->required()->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('entity_id', Filament::getTenant()->id)),
            Select::make('type')->label('What kind of account is it?')
                ->options(MoneyAccountType::class)->required()->live(),
            TextInput::make('bank_name')->label('Bank')
                ->visible(fn (Get $get) => in_array($get('type'), ['bank', 'investment'], true)),
            TextInput::make('account_number')->label('Account number')
                ->visible(fn (Get $get) => in_array($get('type'), ['bank', 'investment'], true)),
            MoneyInput::cents('opening_balance_cents', 'Balance at the start (1 January)')
                ->helperText('The amount in this account when you begin using the system. Locked once entries exist.')
                ->disabled(fn ($record) => $record?->lines()->exists()),
            DatePicker::make('opening_date')->label('Balance as at'),
            Toggle::make('is_active')->label('In use')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->withSum('lines as movement_cents', 'amount_cents'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('balance')->label('Balance now')->alignEnd()
                    ->state(fn (MoneyAccount $r) => Money::format($r->opening_balance_cents + (int) $r->movement_cents)),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->recordActions([EditAction::make()->modalHeading('Edit account')])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageMoneyAccounts::route('/')];
    }
}
