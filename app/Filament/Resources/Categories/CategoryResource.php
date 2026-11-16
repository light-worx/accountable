<?php

namespace App\Filament\Resources\Categories;

use App\Enums\CategoryKind;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Support\SetupResource;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use App\Enums\EntityType;
use App\Support\FormState;
use App\Support\Returns\ReturnForms;
use Filament\Schemas\Components\Utilities\Get;

class CategoryResource extends SetupResource
{
    protected static ?string $model = Category::class;
    protected static ?string $navigationLabel = 'Income & expense types';
    protected static ?string $modelLabel = 'type';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->label('Is this money coming in or going out?')
                ->options(CategoryKind::class)->required()->disabledOn('edit'),
            TextInput::make('name')->label('Name')->required()->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('entity_id', Filament::getTenant()->id)),
            ($returnForm = ReturnForms::for(Filament::getTenant()))
                ? Select::make('return_line_code')->label('Line on the annual return (' . $returnForm::code() . ')')
                    ->options(fn (Get $get) => $returnForm::options(FormState::value($get('kind'))))
                    ->searchable()
                    ->helperText('Where this appears on Schedule ' . $returnForm::code() . '. Leave blank if unsure.')
                : TextInput::make('return_line_code')->label('Annual return line')->maxLength(20),
            Toggle::make('is_active')->label('In use')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->label('Direction')->badge(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('return_line_code')->label('Return line')->placeholder('— not set —')
                    ->description(fn ($record) => ($f = ReturnForms::for(Filament::getTenant())) ? $f::label($record->return_line_code) : null),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->filters([SelectFilter::make('kind')->options(CategoryKind::class)])
            ->recordActions([EditAction::make()->modalHeading('Edit type')])
            ->defaultSort('sort_order')
            ->defaultGroup('kind');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCategories::route('/')];
    }
}
