<?php

namespace App\Filament\Pages;

use App\Enums\EntityType;
use App\Services\Budgets\BudgetService;
use App\Support\Returns\ReturnForms;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Reports extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.reports';

    protected static ?string $navigationLabel = 'Reports';
    protected static ?string $title = 'Reports';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;
    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public function mount(): void
    {
        $now = CarbonImmutable::now();
        $lastMonth = $now->startOfMonth()->subMonth()->format('Y-m');

        $this->form->fill([
            'period' => $lastMonth,
            'year' => $now->year - 1, // the year just ended
            'assess_period' => $lastMonth,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Management accounts')
                ->description('A PDF for the finance committee: receipts and payments, where the money is, and fund balances.')
                ->schema([
                    Select::make('period')->label('Which period?')
                        ->options(fn () => static::periodOptions())->required()->live(),
                ]),

            Section::make('Society assessments')
                ->description('How much each society should have paid the circuit, how much it has paid, and what is still owing, from the approved budget.')
                ->visible(fn () => Filament::getTenant()?->type === EntityType::Circuit)
                ->schema([
                    Select::make('assess_period')->label('Up to which month?')
                        ->options(fn () => static::monthOptions())->required()->live()
                        ->afterStateUpdated(fn (Set $set) => $set('assess_society', null)),
                    Select::make('assess_society')->label('Statement for one society (optional)')
                        ->options(fn (Get $get) => static::societyOptions($get('assess_period')))
                        ->searchable()->live(),
                ]),

            Section::make(fn () => 'Annual return – Schedule ' . (($f = ReturnForms::for(Filament::getTenant())) ? $f::code() : ''))
                ->description('A guide for capturing the annual return on the MCO system, with a check for anything still needing attention.')
                ->visible(fn () => ReturnForms::for(Filament::getTenant()) !== null)
                ->schema([
                    Select::make('year')->label('Which year?')
                        ->options(fn () => static::yearOptions())->required()->live(),
                ]),
        ])->statePath('data');
    }

    public function downloadUrl(): ?string
    {
        $period = $this->data['period'] ?? null;

        return $period
            ? route('reports.statement', ['entity' => Filament::getTenant()->id, 'period' => $period])
            : null;
    }

    public function returnUrl(): ?string
    {
        $year = $this->data['year'] ?? null;

        return ($year && ReturnForms::for(Filament::getTenant()) !== null)
            ? route('reports.return', ['entity' => Filament::getTenant()->id, 'year' => $year])
            : null;
    }

    public function returnLabel(): string
    {
        $form = ReturnForms::for(Filament::getTenant());

        return 'Open ' . ($form ? $form::code() : '') . ' capture guide';
    }

    public function assessmentSummaryUrl(): ?string
    {
        $period = $this->data['assess_period'] ?? null;

        return ($period && Filament::getTenant()?->type === EntityType::Circuit)
            ? route('reports.assessments', ['entity' => Filament::getTenant()->id, 'period' => $period])
            : null;
    }

    public function assessmentStatementUrl(): ?string
    {
        $period = $this->data['assess_period'] ?? null;
        $society = $this->data['assess_society'] ?? null;

        return ($period && $society && Filament::getTenant()?->type === EntityType::Circuit)
            ? route('reports.assessments.society', ['entity' => Filament::getTenant()->id, 'period' => $period, 'society' => $society])
            : null;
    }

    private static function periodOptions(): array
    {
        $now = CarbonImmutable::now()->startOfMonth();
        $options = static::monthOptions();

        foreach ([$now->year, $now->year - 1, $now->year - 2] as $year) {
            $options["year-{$year}"] = "Whole year {$year}";
        }

        return $options;
    }

    private static function monthOptions(): array
    {
        $now = CarbonImmutable::now()->startOfMonth();
        $options = [];

        foreach (range(0, 23) as $i) {
            $month = $now->subMonths($i);
            $options[$month->format('Y-m')] = $month->format('F Y');
        }

        return $options;
    }

    private static function yearOptions(): array
    {
        $year = CarbonImmutable::now()->year;

        return collect(range($year, $year - 4))->mapWithKeys(fn ($y) => [$y => (string) $y])->all();
    }

    /** The societies in the approved budget for the chosen month's year. */
    private static function societyOptions(?string $period): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant || ! $period || ! preg_match('/^(\d{4})-/', $period, $m)) {
            return [];
        }

        $budget = app(BudgetService::class)->current($tenant, (int) $m[1]);

        return $budget
            ? $budget->assessments()->orderBy('society_name')->pluck('society_name', 'society_external_id')->all()
            : [];
    }
}