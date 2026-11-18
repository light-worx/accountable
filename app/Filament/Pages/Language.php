<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class Language extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.language';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;
    protected static ?int $navigationSort = 90;

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Language');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Language');
    }

    public function mount(): void
    {
        $this->form->fill(['locale' => auth()->user()->locale ?: array_key_first(config('languages.locales'))]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description(__('Choose the language you would like to use.'))
                ->schema([
                    Select::make('locale')->label(__('Language'))
                        ->options(config('languages.locales'))->required(),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $locale = $this->form->getState()['locale'];

        abort_unless(array_key_exists($locale, config('languages.locales')), 422);

        auth()->user()->update(['locale' => $locale]);
        app()->setLocale($locale);

        Notification::make()->title(__('Language saved'))->success()->send();

        $this->redirect(static::getUrl());
    }
}