<?php

namespace App\Filament\Resources\MoneyAccounts\Pages;

use App\Filament\Resources\MoneyAccounts\MoneyAccountResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageMoneyAccounts extends ManageRecords
{
    protected static string $resource = MoneyAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add account')
                ->mutateDataUsing(fn (array $data) => $data + ['entity_id' => Filament::getTenant()->id]),
        ];
    }
}
