<?php

namespace App\Filament\Resources\PeriodEvents\Pages;

use App\Filament\Resources\PeriodEvents\PeriodEventResource;
use App\Services\Ledger\PeriodService;
use App\Services\Ledger\PostingException;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPeriodEvents extends ListRecords
{
    protected static string $resource = PeriodEventResource::class;

    public function getSubheading(): ?string
    {
        $closed = app(PeriodService::class)->closedMonths(Filament::getTenant());

        if ($closed === []) {
            return 'No months are closed yet.';
        }

        $labels = array_map(fn (string $m) => CarbonImmutable::createFromFormat('Y-m-d', "{$m}-01")->format('M Y'), array_slice($closed, 0, 6));

        return 'Closed: ' . implode(', ', $labels) . (count($closed) > 6 ? ' and earlier.' : '.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label('Close a month')
                ->icon(Heroicon::OutlinedLockClosed)
                ->visible(fn () => Access::can('close'))
                ->modalHeading('Close a month')
                ->modalDescription('Once a month is closed, nothing can be recorded in it unless you reopen it, with a reason. Check the month’s management accounts first.')
                ->schema([
                    Select::make('month')->label('Which month?')
                        ->options(fn () => static::closableMonths())->required(),
                ])
                ->action(function (array $data) {
                    [$year, $month] = array_map('intval', explode('-', $data['month']));

                    try {
                        app(PeriodService::class)->close(Filament::getTenant(), auth()->user(), $year, $month);
                    } catch (PostingException $e) {
                        Notification::make()->title('Could not close the month')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title('Month closed')->success()->send();
                }),

            Action::make('reopen')
                ->label('Reopen a month')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('gray')
                ->visible(fn () => Access::can('close'))
                ->modalHeading('Reopen a month')
                ->modalDescription('Use this to record a late entry or fix a mistake. The reason is kept on record.')
                ->schema([
                    Select::make('month')->label('Which month?')
                        ->options(fn () => static::closedOptions())->required(),
                    Textarea::make('reason')->label('Why is it being reopened?')->required()->minLength(5),
                ])
                ->action(function (array $data) {
                    [$year, $month] = array_map('intval', explode('-', $data['month']));

                    try {
                        app(PeriodService::class)->reopen(Filament::getTenant(), auth()->user(), $year, $month, $data['reason']);
                    } catch (PostingException $e) {
                        Notification::make()->title('Could not reopen the month')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title('Month reopened')->success()->send();
                }),
        ];
    }

    /** Finished months that are not closed yet. */
    private static function closableMonths(): array
    {
        $closed = app(PeriodService::class)->closedMonths(Filament::getTenant());
        $now = CarbonImmutable::now()->startOfMonth();
        $options = [];

        foreach (range(1, 18) as $i) {
            $month = $now->subMonths($i);

            if (! in_array($month->format('Y-m'), $closed, true)) {
                $options[$month->format('Y-m')] = $month->format('F Y');
            }
        }

        return $options;
    }

    private static function closedOptions(): array
    {
        $options = [];

        foreach (app(PeriodService::class)->closedMonths(Filament::getTenant()) as $m) {
            $options[$m] = CarbonImmutable::createFromFormat('Y-m-d', "{$m}-01")->format('F Y');
        }

        return $options;
    }
}