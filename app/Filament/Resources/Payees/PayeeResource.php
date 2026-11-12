<?php

namespace App\Filament\Resources\Payees;

use App\Enums\PayeeType;
use App\Filament\Resources\Payees\Pages\ManagePayees;
use App\Filament\Support\SetupResource;
use App\Models\Payee;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class PayeeResource extends SetupResource
{
    protected static ?string $model = Payee::class;
    protected static ?string $navigationLabel = 'People & suppliers';
    protected static ?string $modelLabel = 'person / supplier';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;
    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('entity_id', Filament::getTenant()->id)),
            Select::make('type')->label('Who are they?')->options(PayeeType::class)->required()->default('supplier')->live(),
            TextInput::make('pf_number')->label('PF number')
                ->visible(fn (Get $get) => $get('type') === 'clergy')
                ->required(fn (Get $get) => $get('type') === 'clergy')
                ->helperText('Needed for the annual return (4S Section 8).'),
            Toggle::make('in_our_circuit')->label('Based in our circuit')->default(true)
                ->visible(fn (Get $get) => $get('type') === 'clergy'),
            Toggle::make('is_active')->label('In use')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('pf_number')->label('PF no.')->placeholder('—'),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->recordActions([EditAction::make()->modalHeading('Edit')])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManagePayees::route('/')];
    }
}
