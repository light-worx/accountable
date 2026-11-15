<?php

namespace App\Filament\System\Resources\AuditLog;

use App\Filament\System\Resources\AuditLog\Pages\ListAuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Read-only view of everything recorded by the audit log. */
class AuditLogResource extends Resource
{
    protected static ?string $navigationLabel = 'Audit log';
    protected static ?string $modelLabel = 'audit entry';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;
    protected static ?int $navigationSort = 90;

    public static function getModel(): string
    {
        return config('activitylog.activity_model', \Spatie\Activitylog\Models\Activity::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

        public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('causer'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('log_name')->label('Area')->badge(),
                TextColumn::make('who')->label('Who')
                    ->state(fn ($record) => $record->causer?->name ?? 'System'),
                TextColumn::make('description')->label('What happened')->searchable(),
                TextColumn::make('subject')->label('About')
                    ->state(fn ($record) => $record->subject_type ? class_basename($record->subject_type) . ' #' . $record->subject_id : '—'),
                TextColumn::make('details')->label('Details')->wrap()
                    ->state(fn ($record) => Str::limit((string) json_encode($record->properties, JSON_UNESCAPED_UNICODE), 220)),
            ])
            ->filters([
                SelectFilter::make('log_name')->label('Area')->options([
                    'access' => 'People & access',
                    'ledger' => 'Ledger entries',
                    'sync' => 'Directory sync',
                    'default' => 'Setup changes',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLog::route('/')];
    }
}