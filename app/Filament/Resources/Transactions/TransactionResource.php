<?php

namespace App\Filament\Resources\Transactions;

use App\Enums\TransactionType;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Transaction;
use App\Services\Ledger\PostingException;
use App\Services\Ledger\PostingService;
use App\Support\Access;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The cash book. Read-only by design: entries can be reversed, never edited or deleted. */
class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;
    protected static ?string $navigationLabel = 'Cash book';
    protected static ?string $modelLabel = 'entry';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;
    protected static ?int $navigationSort = 1;

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lines.category', 'lines.fund', 'lines.moneyAccount', 'payee', 'reversedBy']))
            ->defaultSort('seq', 'desc')
            ->columns([
                TextColumn::make('date')->date('d M Y')->sortable(),
                TextColumn::make('reference')->label('Ref')->searchable()->sortable(),
                TextColumn::make('description')->label('Details')->wrap()->searchable()
                    ->state(fn (Transaction $r) => collect([$r->payee?->name, $r->description])->filter()->implode(' — ') ?: '—')
                    ->description(fn (Transaction $r) => $r->type->isTransfer() ? 'Moved ' . Money::format($r->movedCents()) : null),
                TextColumn::make('categories')->label('Type')->wrap()
                    ->state(fn (Transaction $r) => $r->lines->map(fn ($l) => $l->category?->name)->filter()->unique()->implode(', ') ?: $r->type->getLabel()),
                TextColumn::make('funds')->label('Fund')->wrap()
                    ->state(fn (Transaction $r) => $r->lines->map(fn ($l) => $l->fund->name)->unique()->implode(', ')),
                TextColumn::make('accounts')->label('Account')->wrap()
                    ->state(fn (Transaction $r) => $r->lines->map(fn ($l) => $l->moneyAccount->name)->unique()->implode(', ')),
                TextColumn::make('money_in')->label('Money in')->alignEnd()->color('success')
                    ->state(fn (Transaction $r) => $r->netCents() > 0 ? Money::format($r->netCents()) : null),
                TextColumn::make('money_out')->label('Money out')->alignEnd()->color('danger')
                    ->state(fn (Transaction $r) => $r->netCents() < 0 ? Money::format(-$r->netCents()) : null),
                TextColumn::make('status')->badge()
                    ->state(fn (Transaction $r) => $r->reversedBy ? 'Reversed' : ($r->reverses_transaction_id ? 'Reversal' : null))
                    ->color('warning'),
            ])
            ->filters([
                SelectFilter::make('type')->options(TransactionType::class),
                Filter::make('date_range')
                    ->schema([DatePicker::make('from')->label('From'), DatePicker::make('until')->label('Until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))),
            ])
            ->recordActions([
                Action::make('reverse')
                    ->label('Reverse')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->visible(fn (Transaction $r) => Access::can('reverse') && ! $r->reverses_transaction_id && ! $r->reversedBy)
                    ->modalHeading('Reverse this entry?')
                    ->modalDescription('The original stays on record and a matching reversing entry is added. You can then enter the correct figures as a new entry.')
                    ->schema([Textarea::make('reason')->label('Why is it being reversed?')->required()->minLength(5)])
                    ->action(function (Transaction $record, array $data) {
                        try {
                            $reversal = app(PostingService::class)->reverse($record, auth()->user(), $data['reason']);
                        } catch (PostingException $e) {
                            Notification::make()->title('Could not reverse')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title("Reversed — see {$reversal->reference}")->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTransactions::route('/')];
    }
}