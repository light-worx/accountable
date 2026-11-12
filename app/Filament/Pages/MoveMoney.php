<?php

namespace App\Filament\Pages;

use App\Enums\TransactionType;
use App\Models\Entity;
use App\Support\Money;
use App\Support\MoneyInput;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MoveMoney extends EntryPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;
    protected static ?string $navigationLabel = 'Move money';
    protected static ?string $title = 'Move money';
    protected static ?int $navigationSort = 3;

    public function submitLabel(): string
    {
        return 'Move the money';
    }

    protected function defaults(): array
    {
        return ['date' => today()->toDateString(), 'kind' => 'accounts'] + collect(parent::defaults())->only(['money_account_id'])->all();
    }

    public function form(Schema $schema): Schema
    {
        $isAccounts = fn (Get $get) => $get('kind') === 'accounts';

        return $schema->components([
            Section::make()->schema([
                Radio::make('kind')->label('What are you moving?')
                    ->options([
                        'accounts' => 'Money from one bank / cash account to another (e.g. cash banked)',
                        'funds' => 'Money from one fund to another (e.g. General Fund to Building Fund)',
                    ])->live()->required()->columnSpanFull(),

                DatePicker::make('date')->required()->maxDate(today()),
                MoneyInput::rands('amount')->required()->minValue(0.01),

                // Between accounts
                Select::make('from_account_id')->label('From account')
                    ->options(fn () => static::accountOptions())->visible($isAccounts)->required($isAccounts),
                Select::make('to_account_id')->label('To account')
                    ->options(fn () => static::accountOptions())->visible($isAccounts)->required($isAccounts),
                Select::make('fund_id')->label('Which fund does this money belong to?')
                    ->options(fn () => static::fundOptions())->visible($isAccounts)->required($isAccounts)
                    ->default(fn () => \Filament\Facades\Filament::getTenant()->generalFund()?->id),

                // Between funds
                Select::make('from_fund_id')->label('From fund')
                    ->options(fn () => static::fundOptions())->visible(fn (Get $get) => ! $isAccounts($get))->required(fn (Get $get) => ! $isAccounts($get)),
                Select::make('to_fund_id')->label('To fund')
                    ->options(fn () => static::fundOptions())->visible(fn (Get $get) => ! $isAccounts($get))->required(fn (Get $get) => ! $isAccounts($get)),
                Select::make('money_account_id')->label('Which account is the money in?')
                    ->options(fn () => static::accountOptions())->visible(fn (Get $get) => ! $isAccounts($get))->required(fn (Get $get) => ! $isAccounts($get)),

                TextInput::make('description')->label('Note (optional)')->maxLength(255)->columnSpanFull(),
                static::attachmentField(),
            ])->columns(2),
        ])->statePath('data');
    }

    protected function buildEntry(array $data, Entity $entity): array
    {
        $cents = Money::toCents($data['amount'] ?? 0);

        if (($data['kind'] ?? 'accounts') === 'accounts') {
            $lines = [
                ['money_account_id' => $data['from_account_id'], 'fund_id' => $data['fund_id'], 'category_id' => null, 'amount_cents' => -$cents],
                ['money_account_id' => $data['to_account_id'], 'fund_id' => $data['fund_id'], 'category_id' => null, 'amount_cents' => $cents],
            ];

            return [TransactionType::AccountTransfer, $lines, $data['description'] ?? null, null];
        }

        $lines = [
            ['money_account_id' => $data['money_account_id'], 'fund_id' => $data['from_fund_id'], 'category_id' => null, 'amount_cents' => -$cents],
            ['money_account_id' => $data['money_account_id'], 'fund_id' => $data['to_fund_id'], 'category_id' => null, 'amount_cents' => $cents],
        ];

        return [TransactionType::FundTransfer, $lines, $data['description'] ?? null, null];
    }
}
