<?php

namespace App\Filament\System\Resources\Translations;

use App\Filament\System\Resources\Translations\Pages\ManageTranslationOverrides;
use App\Models\TranslationOverride;
use App\Support\Translation\Strings;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Corrections to translations, made on screen. They take effect straight away and win over the translation files. */
class TranslationOverrideResource extends Resource
{
    protected static ?string $model = TranslationOverride::class;
    protected static ?string $navigationLabel = 'Translations';
    protected static ?string $modelLabel = 'translation';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;
    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('locale')->label('Language')
                ->options(fn () => collect(config('languages.locales'))->except(array_key_first(config('languages.locales')))->all())
                ->required()->disabledOn('edit'),
            Select::make('source')->label('English text')
                ->options(fn () => Strings::options())->searchable()->required()->disabledOn('edit')
                ->columnSpanFull(),
            Textarea::make('translation')->label('Translation')->required()->rows(3)->columnSpanFull()
                ->helperText('Keep any word that starts with a colon, such as :reference or :name, exactly as it is. It is replaced with a real value.')
                ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                    if (Strings::placeholders((string) $get('source')) !== Strings::placeholders((string) $value)) {
                        $fail('The translation must keep the same words that start with a colon as the English text.');
                    }
                }),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('locale')->label('Language')->badge()
                    ->formatStateUsing(fn ($state) => config("languages.locales.{$state}", $state)),
                TextColumn::make('source')->label('English')->searchable()
                    ->formatStateUsing(fn ($state) => Str::limit($state, 70))->wrap(),
                TextColumn::make('translation')->label('Translation')->searchable()
                    ->formatStateUsing(fn ($state) => Str::limit($state, 70))->wrap(),
                TextColumn::make('updated_at')->label('Changed')->dateTime('d M Y H:i'),
            ])
            ->filters([
                SelectFilter::make('locale')->label('Language')
                    ->options(fn () => collect(config('languages.locales'))->except(array_key_first(config('languages.locales')))->all()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()->modalHeading('Remove this correction?')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTranslationOverrides::route('/')];
    }
}