<?php

namespace App\Filament\Resources\Statements\Pages;

use App\Filament\Resources\Statements\StatementSendResource;
use App\Services\Budgets\StatementException;
use App\Services\Budgets\StatementSender;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ListStatementSends extends ListRecords
{
    protected static string $resource = StatementSendResource::class;

    protected function getHeaderActions(): array
    {
        $lastMonth = CarbonImmutable::now()->startOfMonth()->subMonth()->format('Y-m');

        return [
            Action::make('check')
                ->label('Check before sending')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('gray')
                ->modalHeading('Check before sending')
                ->modalDescription('Nothing is sent. This shows who would get a statement, and who would not.')
                ->schema([
                    Select::make('period')->label('Which month?')->options(fn () => static::months(false))->default($lastMonth)->required(),
                ])
                ->action(function (array $data) {
                    [$year, $month] = static::parse($data['period']);

                    try {
                        $plan = app(StatementSender::class)->plan(Filament::getTenant(), $year, $month);
                    } catch (StatementException $e) {
                        Notification::make()->title('Cannot check')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $names = fn (array $rows) => $rows ? ' (' . e(implode(', ', array_column($rows, 'name'))) . ')' : '';

                    $html = '<strong>' . e(CarbonImmutable::create($year, $month, 1)->format('F Y')) . '</strong><br>'
                        . ($plan['closed'] ? 'Month closed: yes' : '<strong>Month closed: NO. Close it under Month-end before sending.</strong>') . '<br>'
                        . 'Ready to send: ' . count($plan['ready']) . '<br>'
                        . 'No email address: ' . count($plan['no_email']) . $names($plan['no_email']) . '<br>'
                        . 'Already sent: ' . count($plan['already']) . $names($plan['already']) . '<br>'
                        . 'Nothing to report: ' . count($plan['nothing']);

                    foreach ($plan['data']['issues'] as $issue) {
                        $html .= '<br>• ' . e($issue['text']);
                    }

                    Notification::make()->title('Statement check')->body(new HtmlString($html))->persistent()->send();
                }),

            Action::make('test')
                ->label('Send me a test copy')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->visible(fn () => Access::can('send'))
                ->modalHeading('Send a test copy')
                ->modalDescription('Emails one society’s statement to you only, marked [TEST]. Nothing goes to the society.')
                ->schema([
                    Select::make('period')->label('Which month?')->options(fn () => static::months(true))->default($lastMonth)->required()->live(),
                    Select::make('society')->label('Which society?')
                        ->options(fn (Get $get) => $get('period')
                            ? app(StatementSender::class)->societyOptions(Filament::getTenant(), static::parse($get('period'))[0])
                            : [])
                        ->searchable()->required(),
                ])
                ->action(function (array $data) {
                    [$year, $month] = static::parse($data['period']);

                    try {
                        app(StatementSender::class)->sendTest(Filament::getTenant(), auth()->user(), $year, $month, (string) $data['society']);
                    } catch (StatementException $e) {
                        Notification::make()->title('Could not send the test')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title('Test copy queued')->body('It is being sent to ' . auth()->user()->email . '.')->success()->send();
                }),

            Action::make('send')
                ->label('Send statements')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn () => Access::can('send'))
                ->requiresConfirmation()
                ->modalHeading('Send statements to societies?')
                ->modalDescription('Each society with an email address on file is sent its statement for the month, with replies coming to you. Use “Check before sending” first if you have not already.')
                ->modalSubmitActionLabel('Send statements')
                ->schema([
                    Select::make('period')->label('Which month?')->options(fn () => static::months(false))->default($lastMonth)->required(),
                    Toggle::make('resend')->label('Also resend to societies already sent this month'),
                ])
                ->action(function (array $data) {
                    [$year, $month] = static::parse($data['period']);

                    try {
                        $result = app(StatementSender::class)->sendAll(Filament::getTenant(), auth()->user(), $year, $month, (bool) ($data['resend'] ?? false));
                    } catch (StatementException $e) {
                        Notification::make()->title('Statements not sent')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $html = "Queued {$result['queued']} statement(s) for sending.";

                    if ($result['no_email']) {
                        $html .= '<br><strong>No email address for:</strong> ' . e(implode(', ', $result['no_email'])) . '. Add one with “Set email” on the budget’s society list.';
                    }

                    Notification::make()->title('Sending')->body(new HtmlString($html))->success()->persistent()->send();
                }),
        ];
    }

    private static function months(bool $includeCurrent): array
    {
        $now = CarbonImmutable::now()->startOfMonth();
        $options = [];

        foreach (range($includeCurrent ? 0 : 1, 12) as $i) {
            $month = $now->subMonths($i);
            $options[$month->format('Y-m')] = $month->format('F Y');
        }

        return $options;
    }

    /** @return array{0:int,1:int} */
    private static function parse(string $period): array
    {
        [$year, $month] = explode('-', $period);

        return [(int) $year, (int) $month];
    }
}