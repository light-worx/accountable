<?php

namespace App\Filament\Pages;

use App\Enums\TransactionType;
use App\Models\Entity;
use App\Models\Payee;
use App\Support\MoneyInput;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MoneyOut extends EntryPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpCircle;
    protected static ?string $navigationLabel = 'Money out';
    protected static ?string $title = 'Record a payment';
    protected static ?int $navigationSort = 2;

    public function submitLabel(): string
    {
        return 'Save payment';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                DatePicker::make('date')->label('Date paid')->required()->maxDate(today()),
                Select::make('money_account_id')->label('Paid from')
                    ->options(fn () => static::accountOptions())->required(),
                Select::make('payee_id')->label('Paid to (optional)')
                    ->options(fn () => Filament::getTenant()->payees()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->createOptionForm([TextInput::make('name')->label('Name')->required()->maxLength(255)])
                    ->createOptionUsing(fn (array $data) => Payee::create([
                        'entity_id' => Filament::getTenant()->id,
                        'name' => $data['name'],
                    ])->id),
                TextInput::make('description')->label('What was it for?')
                    ->placeholder('e.g. October electricity, invoice 4471')->maxLength(255),
                Repeater::make('lines')->label('Amounts')
                    ->schema([
                        Select::make('category_id')->label('Type of payment')
                            ->options(fn () => static::categoryOptions('expense'))->searchable()->required(),
                        Select::make('fund_id')->label('Paid from fund')
                            ->options(fn () => static::fundOptions())->required(),
                        MoneyInput::rands('amount')->required()->minValue(0.01),
                    ])
                    ->columns(3)->defaultItems(1)->minItems(1)->reorderable(false)
                    ->addActionLabel('Add another amount')
                    ->columnSpanFull(),
                static::attachmentField(),
            ])->columns(2),
        ])->statePath('data');
    }

    protected function buildEntry(array $data, Entity $entity): array
    {
        return [TransactionType::Expense, $this->linesFromRepeater($data, -1, $entity), $data['description'] ?? null, $data['payee_id'] ?? null];
    }
}
