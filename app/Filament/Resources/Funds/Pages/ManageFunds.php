<?php

namespace App\Filament\Resources\Funds\Pages;

use App\Filament\Resources\Funds\FundResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageFunds extends ManageRecords
{
    protected static string $resource = FundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add fund')
                ->mutateDataUsing(fn (array $data) => $data + ['entity_id' => Filament::getTenant()->id]),
        ];
    }
}
