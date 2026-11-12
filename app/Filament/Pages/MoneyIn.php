<?php

namespace App\Filament\Pages;

use App\Enums\TransactionType;
use App\Models\Entity;
use App\Support\MoneyInput;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MoneyIn extends EntryPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownCircle;
    protected static ?string $navigationLabel = 'Money in';
    protected static ?string $title = 'Record money received';
    protected static ?int $navigationSort = 1;

    public function submitLabel(): string
    {
        return 'Save money received';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                DatePicker::make('date')->label('Date received')->required()->maxDate(today()),
                Select::make('money_account_id')->label('Paid into')
                    ->options(fn () => static::accountOptions())->required(),
                TextInput::make('description')->label('Description (optional)')
                    ->placeholder('e.g. Sunday 9 Nov service')->maxLength(255)->columnSpanFull(),
                Repeater::make('lines')->label('What was received?')
                    ->schema([
                        Select::make('category_id')->label('Type of income')
                            ->options(fn () => static::categoryOptions('income'))->searchable()->required(),
                        Select::make('fund_id')->label('Fund')
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
        return [TransactionType::Income, $this->linesFromRepeater($data, +1, $entity), $data['description'] ?? null, null];
    }
}
