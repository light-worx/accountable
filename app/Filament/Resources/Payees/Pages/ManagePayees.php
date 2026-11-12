<?php

namespace App\Filament\Resources\Payees\Pages;

use App\Filament\Resources\Payees\PayeeResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManagePayees extends ManageRecords
{
    protected static string $resource = PayeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add person / supplier')
                ->mutateDataUsing(fn (array $data) => $data + ['entity_id' => Filament::getTenant()->id]),
        ];
    }
}
