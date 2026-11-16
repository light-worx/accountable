<?php

namespace App\Filament\Resources\Budgets\RelationManagers;

use App\Enums\EntityType;
use App\Models\Budget;
use App\Models\BudgetAssessment;
use App\Services\Budgets\BudgetException;
use App\Services\Budgets\BudgetService;
use App\Services\Budgets\SocietyContacts;
use App\Services\ChurchApi\Directory;
use App\Services\ChurchApi\SyncException;
use App\Support\Access;
use App\Support\Money;
use App\Support\MoneyInput;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** What each society in the circuit is expected to pay, month by month. The total becomes the 3.1 budget line. */
class AssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assessments';

    protected static ?string $title = 'Assessments by society';

    private ?array $contacts = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return $ownerRecord->entity?->type === EntityType::Circuit;
    }

    public function isReadOnly(): bool
    {
        return false; // the buttons below decide who can change what
    }

    private function canEditRows(): bool
    {
        return $this->getOwnerRecord()->status === 'draft' && Access::can('setup');
    }

    /** The circuit's own email entries, read once per request. */
    private function overrides(): array
    {
        return $this->contacts ??= app(SocietyContacts::class)->overrides($this->getOwnerRecord()->entity);
    }

    private function override(BudgetAssessment $row): ?string
    {
        $email = $this->overrides()[$row->society_external_id] ?? null;

        return filled($email) ? $email : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn () => 'Total assessments budgeted: ' . Money::format($this->totalCents()))
            ->defaultSort('society_name')
            ->paginated(false)
            ->emptyStateHeading('No societies yet')
            ->emptyStateDescription('Use “Load societies from church.net.za” to add every society in this circuit.')
            ->columns([
                TextColumn::make('society_name')->label('Society')->searchable(),
                TextColumn::make('annual')->label('Annual amount')->alignEnd()
                    ->state(fn (BudgetAssessment $record) => Money::format($record->annualCents())),
                TextColumn::make('phasing')->label('Months')->badge()
                    ->state(fn (BudgetAssessment $record) => $record->annualCents() === 0 ? '' : ($record->isEven() ? 'Even' : 'Adjusted'))
                    ->color(fn ($state) => $state === 'Adjusted' ? 'warning' : 'gray'),
                TextColumn::make('email')->label('Email for statements')->wrap()
                    ->state(fn (BudgetAssessment $record) => $this->override($record) ?? $record->society_email)
                    ->placeholder('— none: use “Set email” —')
                    ->description(fn (BudgetAssessment $record) => $this->override($record)
                        ? 'set here'
                        : ($record->society_email ? 'from church.net.za' : null)),
            ])
            ->headerActions([
                Action::make('load')
                    ->label('Load societies from church.net.za')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn () => $this->canEditRows())
                    ->requiresConfirmation()
                    ->modalHeading('Load societies?')
                    ->modalDescription('Adds every society in this circuit that is not already listed, with zero amounts. Societies already listed are not changed.')
                    ->action(function () {
                        $budget = $this->getOwnerRecord();
                        $circuit = $budget->entity;

                        if (blank($circuit->external_id)) {
                            Notification::make()->title('Cannot load societies')
                                ->body('This circuit was not set up from church.net.za, so its societies cannot be looked up.')
                                ->danger()->persistent()->send();

                            return;
                        }

                        try {
                            $societies = app(Directory::class)->societies((string) $circuit->external_id);
                            $added = app(BudgetService::class)->addSocieties($budget, auth()->user(), array_values($societies));
                        } catch (SyncException|BudgetException $e) {
                            Notification::make()->title('Could not load societies')->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title($added === 0 ? 'Nothing new to add' : "Added {$added} societies")->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn () => $this->canEditRows())
                    ->modalHeading(fn (BudgetAssessment $record) => $record->society_name)
                    ->fillForm(function (BudgetAssessment $record): array {
                        $data = ['annual' => $record->annualCents() / 100, 'even' => $record->isEven()];

                        foreach ($record->months() as $i => $cents) {
                            $data['m' . ($i + 1)] = $cents / 100;
                        }

                        return $data;
                    })
                    ->schema([
                        MoneyInput::rands('annual', 'Assessment for the whole year')
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
                    ->action(function (BudgetAssessment $record, array $data) {
                        $months = ! empty($data['even'])
                            ? Budget::spread(Money::toCents($data['annual'] ?? 0))
                            : array_map(fn (int $m) => Money::toCents($data["m{$m}"] ?? 0), range(1, 12));

                        try {
                            app(BudgetService::class)->setAssessment($this->getOwnerRecord(), auth()->user(), $record->society_external_id, $months);
                        } catch (BudgetException $e) {
                            Notification::make()->title('Could not save')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Saved')->success()->send();
                    }),

                // Contact details are not budget figures, so this works even after the budget is approved.
                Action::make('email')
                    ->label('Set email')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn () => Access::can('setup'))
                    ->modalHeading(fn (BudgetAssessment $record) => 'Statement email – ' . $record->society_name)
                    ->modalDescription('Monthly statements for this society are sent here. Leave it blank to use the address from church.net.za, if there is one.')
                    ->fillForm(fn (BudgetAssessment $record) => ['email' => $this->override($record)])
                    ->schema([
                        Textarea::make('email')->label('Email address(es)')->rows(2)
                            ->helperText('Separate several addresses with commas.'),
                    ])
                    ->action(function (BudgetAssessment $record, array $data) {
                        try {
                            app(SocietyContacts::class)->set(
                                $this->getOwnerRecord()->entity, auth()->user(),
                                $record->society_external_id, $record->society_name, $data['email'] ?? null,
                            );
                        } catch (BudgetException $e) {
                            Notification::make()->title('Could not save the email')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        $this->contacts = null; // read the new entry straight away
                        Notification::make()->title('Email saved')->success()->send();
                    }),

                Action::make('remove')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn () => $this->canEditRows())
                    ->requiresConfirmation()
                    ->modalHeading('Remove this society from the budget?')
                    ->modalDescription('Only this draft is affected. You can load the society again later.')
                    ->action(function (BudgetAssessment $record) {
                        try {
                            app(BudgetService::class)->removeAssessment($this->getOwnerRecord(), auth()->user(), $record->society_external_id);
                        } catch (BudgetException $e) {
                            Notification::make()->title('Could not remove')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    private function totalCents(): int
    {
        return (int) $this->getOwnerRecord()->assessments()->get()->sum(fn (BudgetAssessment $row) => $row->annualCents());
    }
}