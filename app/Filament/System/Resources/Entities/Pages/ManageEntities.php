<?php

namespace App\Filament\System\Resources\Entities\Pages;

use App\Filament\System\Resources\Entities\EntityResource;
use Filament\Resources\Pages\ManageRecords;

class ManageEntities extends ManageRecords
{
    protected static string $resource = EntityResource::class;
}