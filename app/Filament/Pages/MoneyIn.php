<?php

namespace App\Filament\Pages;

use App\Enums\EntityType;
use App\Enums\PayeeType;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Entity;
use App\Models\Payee;
use App\Services\Ledger\PostingException;
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
use Illuminate\Contracts\Support\Htmlable;

class MoneyIn extends EntryPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownCircle;
    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Money in');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Record money received');
    }

    public function submitLabel(): string
    {
        return __('Save money received');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                DatePicker::make('date')->label(__('Date received'))->required()->maxDate(today()),
                Select::make('money_account_id')->label(__('Paid into'))
                    ->options(fn () => static::accountOptions())->required(),
                Select::make('payee_id')->label(__('Received from (optional)'))
                    ->options(fn () => Filament::getTenant()->payees()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->helperText(fn () => Filament::getTenant()?->type === EntityType::Circuit
                        ? __('For assessments, choose the society that paid.')
                        : null)
                    ->createOptionForm([TextInput::make('name')->label(__('Name'))->required()->maxLength(255)])
                    ->createOptionUsing(fn (array $data) => Payee::firstOrCreate([
                        'entity_id' => Filament::getTenant()->id,
                        'name' => $data['name'],
                    ])->id),
                TextInput::make('description')->label(__('Description (optional)'))
                    ->placeholder(__('e.g. Sunday 9 Nov service'))->maxLength(255),
                Repeater::make('lines')->label(__('What was received?'))
                    ->schema([
                        Select::make('category_id')->label(__('Type of income'))
                            ->options(fn () => static::categoryOptions('income'))->searchable()->required(),
                        Select::make('fund_id')->label(__('Fund'))
                            ->options(fn () => static::fundOptions())->required(),
                        MoneyInput::rands('amount')->required()->minValue(0.01),
                    ])
                    ->columns(3)->defaultItems(1)->minItems(1)->reorderable(false)
                    ->addActionLabel(__('Add another amount'))
                    ->columnSpanFull(),
                static::attachmentField(),
            ])->columns(2),
        ])->statePath('data');
    }

    protected function buildEntry(array $data, Entity $entity): array
    {
        $lines = $this->linesFromRepeater($data, +1, $entity);
        $payeeId = filled($data['payee_id'] ?? null) ? (int) $data['payee_id'] : null;

        // A circuit's assessment payments must say which society paid, so they appear on that society's statement.
        if ($entity->type === EntityType::Circuit) {
            $assessmentTypes = Category::where('entity_id', $entity->id)->where('return_line_code', '3.1')->pluck('id')->all();
            $isAssessment = collect($lines)->contains(fn ($l) => in_array((int) $l['category_id'], $assessmentTypes, true));
            $fromSociety = $payeeId !== null
                && Payee::where(['entity_id' => $entity->id, 'id' => $payeeId, 'type' => PayeeType::Society->value])->exists();

            if ($isAssessment && ! $fromSociety) {
                throw new PostingException(__('Please choose which society this assessment came from, under “Received from”. If the society is not in the list, use “Load societies” under People & suppliers.'));
            }
        }

        return [TransactionType::Income, $lines, $data['description'] ?? null, $payeeId];
    }
}