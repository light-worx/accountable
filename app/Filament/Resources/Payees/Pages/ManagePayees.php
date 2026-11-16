<?php

namespace App\Filament\Resources\Payees\Pages;

use App\Enums\EntityType;
use App\Filament\Resources\Payees\PayeeResource;
use App\Services\Budgets\BudgetException;
use App\Services\Budgets\SocietyPayees;
use App\Services\ChurchApi\SyncException;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManagePayees extends ManageRecords
{
    protected static string $resource = PayeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('loadSocieties')
                ->label('Load societies from church.net.za')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => Access::can('setup')
                    && Filament::getTenant()?->type === EntityType::Circuit
                    && filled(Filament::getTenant()->external_id))
                ->requiresConfirmation()
                ->modalHeading('Load societies?')
                ->modalDescription('Adds each society in this circuit to this list, so assessment payments can be recorded against them. Existing entries are not changed.')
                ->action(function () {
                    try {
                        $added = app(SocietyPayees::class)->loadFromDirectory(Filament::getTenant());
                    } catch (SyncException|BudgetException $e) {
                        Notification::make()->title('Could not load societies')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title($added === 0 ? 'Nothing new to add' : "Added {$added} societies")->success()->send();
                }),

            CreateAction::make()->label('Add person / supplier')
                ->mutateDataUsing(fn (array $data) => $data + ['entity_id' => Filament::getTenant()->id]),
        ];
    }
}