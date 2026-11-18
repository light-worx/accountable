<?php

namespace App\Filament\System\Resources\Translations\Pages;

use App\Filament\System\Resources\Translations\TranslationOverrideResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTranslationOverrides extends ManageRecords
{
    protected static string $resource = TranslationOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a correction')];
    }
}