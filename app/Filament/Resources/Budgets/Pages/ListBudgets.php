<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use App\Services\Budgets\BudgetException;
use App\Services\Budgets\BudgetService;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBudgets extends ListRecords
{
    protected static string $resource = BudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New budget')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => Access::can('setup'))
                ->modalHeading('Start a new budget')
                ->schema([
                    Select::make('year')->label('Which year?')
                        ->options(fn () => collect(range(CarbonImmutable::now()->year + 1, CarbonImmutable::now()->year - 1))
                            ->mapWithKeys(fn ($y) => [$y => (string) $y])->all())
                        ->default(CarbonImmutable::now()->year)
                        ->required(),
                    Select::make('start_from')->label('Start from')
                        ->options([
                            'actuals' => 'Last year’s actual figures, spread evenly over the months',
                            'copy' => 'A copy of last year’s budget',
                            'blank' => 'A blank budget',
                        ])
                        ->default('actuals')->required(),
                ])
                ->action(function (array $data) {
                    try {
                        $budget = app(BudgetService::class)->create(Filament::getTenant(), auth()->user(), (int) $data['year'], $data['start_from']);
                    } catch (BudgetException $e) {
                        Notification::make()->title('Could not start the budget')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $this->redirect(BudgetResource::getUrl('view', ['record' => $budget]));
                }),
        ];
    }
}