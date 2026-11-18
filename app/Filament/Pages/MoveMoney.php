<?php

namespace App\Filament\Pages;

use App\Enums\TransactionType;
use App\Models\Entity;
use App\Support\Money;
use App\Support\MoneyInput;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class MoveMoney extends EntryPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;
    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('Move money');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Move money');
    }

    public function submitLabel(): string
    {
        return __('Move the money');
    }

    protected function defaults(): array
    {
        return ['date' => today()->toDateString(), 'kind' => 'accounts'] + collect(parent::defaults())->only(['money_account_id'])->all();
    }

    public function form(Schema $schema): Schema
    {
        $isAccounts = fn (Get $get) => $get('kind') === 'accounts';
        $isFunds = fn (Get $get) => $get('kind') !== 'accounts';

        return $schema->components([
            Section::make()->schema([
                Radio::make('kind')->label(__('What are you moving?'))
                    ->options([
                        'accounts' => __('Money from one bank / cash account to another (e.g. cash banked)'),
                        'funds' => __('Money from one fund to another (e.g. General Fund to Building Fund)'),
                    ])->live()->required()->columnSpanFull(),

                DatePicker::make('date')->label(__('Date'))->required()->maxDate(today()),
                MoneyInput::rands('amount')->required()->minValue(0.01),

                // Between accounts
                Select::make('from_account_id')->label(__('From account'))
                    ->options(fn () => static::accountOptions())->visible($isAccounts)->required($isAccounts),
                Select::make('to_account_id')->label(__('To account'))
                    ->options(fn () => static::accountOptions())->visible($isAccounts)->required($isAccounts),
                Select::make('fund_id')->label(__('Which fund does this money belong to?'))
                    ->options(fn () => static::fundOptions())->visible($isAccounts)->required($isAccounts)
                    ->default(fn () => Filament::getTenant()->generalFund()?->id),

                // Between funds
                Select::make('from_fund_id')->label(__('From fund'))
                    ->options(fn () => static::fundOptions())->visible($isFunds)->required($isFunds),
                Select::make('to_fund_id')->label(__('To fund'))
                    ->options(fn () => static::fundOptions())->visible($isFunds)->required($isFunds),
                Select::make('money_account_id')->label(__('Which account is the money in?'))
                    ->options(fn () => static::accountOptions())->visible($isFunds)->required($isFunds),

                TextInput::make('description')->label(__('Note (optional)'))->maxLength(255)->columnSpanFull(),
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