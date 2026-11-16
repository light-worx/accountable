<?php

namespace App\Filament\Resources\Budgets\RelationManagers;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Services\Budgets\BudgetService;
use App\Support\Access;
use App\Support\Money;
use App\Support\MoneyInput;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The amounts in a budget: one row per income / payment type. Editable while the budget is a draft. */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Amounts';

    private ?int $derivedCategory = null;

    private bool $derivedLoaded = false;

    public function isReadOnly(): bool
    {
        return false; // the buttons below decide who can change what
    }

    private function canEditLines(): bool
    {
        return $this->getOwnerRecord()->status === 'draft' && Access::can('setup');
    }

    /** On a circuit budget with a society schedule, the assessments line is the total of that schedule. */
    private function isDerived(BudgetLine $record): bool
    {
        if (! $this->derivedLoaded) {
            $budget = $this->getOwnerRecord();
            $this->derivedCategory = $budget->assessments()->exists()
                ? app(BudgetService::class)->assessmentCategory($budget->entity)?->id
                : null;
            $this->derivedLoaded = true;
        }

        return $this->derivedCategory !== null && $record->category_id === $this->derivedCategory;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('category')
                ->orderByDesc(Category::select('kind')->whereColumn('categories.id', 'budget_lines.category_id'))
                ->orderBy(Category::select('sort_order')->whereColumn('categories.id', 'budget_lines.category_id')))
            ->paginated(false)
            ->columns([
                TextColumn::make('category.kind')->label('')->badge(),
                TextColumn::make('category.name')->label('Type'),
                TextColumn::make('annual')->label('Annual amount')->alignEnd()
                    ->state(fn (BudgetLine $record) => Money::format($record->annualCents())),
                TextColumn::make('phasing')->label('Months')->badge()
                    ->state(function (BudgetLine $record) {
                        if ($this->isDerived($record)) {
                            return 'From societies';
                        }

                        return $record->annualCents() === 0 ? '' : ($record->isEven() ? 'Even' : 'Adjusted');
                    })
                    ->color(fn ($state) => match ($state) {
                        'Adjusted' => 'warning',
                        'From societies' => 'info',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (BudgetLine $record) => $this->canEditLines() && ! $this->isDerived($record))
                    ->modalHeading(fn (BudgetLine $record) => $record->category->name)
                    ->fillForm(function (BudgetLine $record): array {
                        $data = ['annual' => $record->annualCents() / 100, 'even' => $record->isEven()];

                        foreach ($record->months() as $i => $cents) {
                            $data['m' . ($i + 1)] = $cents / 100;
                        }

                        return $data;
                    })
                    ->schema([
                        MoneyInput::rands('annual', 'Amount for the whole year')
                            ->minValue(0)
                            ->visible(fn (Get $get) => (bool) $get('even'))
                            ->required(fn (Get $get) => (bool) $get('even')),
                        Toggle::make('even')->label('Same amount every month')->live(),
                        Fieldset::make('Amount for each month')
                            ->visible(fn (Get $get) => ! $get('even'))
                            ->schema(array_map(
                                fn (int $m) => MoneyInput::rands("m{$m}", CarbonImmutable::create(2000, $m, 1)->format('F'))->minValue(0)->default(0),
                                range(1, 12),
                            ))
                            ->columns(4),
                    ])
                    ->action(function (BudgetLine $record, array $data) {
                        $cents = ! empty($data['even'])
                            ? Budget::spread(Money::toCents($data['annual'] ?? 0))
                            : array_map(fn (int $m) => Money::toCents($data["m{$m}"] ?? 0), range(1, 12));

                        $record->update(array_combine(array_map(fn ($m) => "m{$m}", range(1, 12)), $cents));

                        Notification::make()->title('Saved')->success()->send();
                    }),
            ]);
    }
}