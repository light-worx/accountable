<?php

namespace App\Filament\System\Resources\AuditLog\Pages;

use App\Filament\System\Resources\AuditLog\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLog extends ListRecords
{
    protected static string $resource = AuditLogResource::class;
}