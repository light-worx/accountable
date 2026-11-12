<?php

namespace App\Filament\Pages;

use App\Enums\TransactionType;
use App\Models\Entity;
use App\Services\Ledger\PostingException;
use App\Services\Ledger\PostingService;
use App\Support\Access;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Shared behaviour for the three entry screens (Money In / Money Out / Move Money).
 * Each screen builds its form and turns the answers into ledger lines; PostingService does the rest.
 */
abstract class EntryPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.entry';

    protected static string|UnitEnum|null $navigationGroup = 'Record';

    public ?array $data = [];

    abstract public function form(Schema $schema): Schema;

    /** @return array{0: TransactionType, 1: array, 2: ?string, 3: ?int} type, lines, description, payee id */
    abstract protected function buildEntry(array $data, Entity $entity): array;

    abstract public function submitLabel(): string;

    public static function canAccess(): bool
    {
        return Access::can('capture');
    }

    public function mount(): void
    {
        $this->form->fill($this->defaults());
    }

    protected function defaults(): array
    {
        $entity = Filament::getTenant();
        $accounts = $entity->moneyAccounts()->where('is_active', true)->pluck('id');

        return [
            'date' => today()->toDateString(),
            'money_account_id' => $accounts->count() === 1 ? $accounts->first() : null,
            'lines' => [['fund_id' => $entity->generalFund()?->id]],
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $entity = Filament::getTenant();

        try {
            [$type, $lines, $description, $payeeId] = $this->buildEntry($data, $entity);

            $tx = app(PostingService::class)->post(
                $entity, auth()->user(), $type, $data['date'], $lines, $description, $payeeId, $data['attachments'] ?? [],
            );
        } catch (PostingException $e) {
            Notification::make()->title('Not saved')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title("Saved as {$tx->reference}")->success()->send();
        $this->form->fill($this->defaults());
    }

    // ---- helpers shared by the forms ----

    protected static function accountOptions(): array
    {
        return Filament::getTenant()->moneyAccounts()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    protected static function fundOptions(): array
    {
        return Filament::getTenant()->funds()->where('is_active', true)->orderByDesc('is_general')->orderBy('name')->pluck('name', 'id')->all();
    }

    protected static function categoryOptions(string $kind): array
    {
        return Filament::getTenant()->categories()->where('kind', $kind)->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all();
    }

    protected static function attachmentField(): FileUpload
    {
        return FileUpload::make('attachments')
            ->label('Photo or scan of the slip / invoice (optional)')
            ->multiple()->maxFiles(5)->maxSize(8192)
            ->acceptedFileTypes(['application/pdf', 'image/*'])
            ->disk('local')->directory('attachments/' . Filament::getTenant()->id)->visibility('private')
            ->columnSpanFull();
    }

    /** Turns the "amounts" repeater into ledger lines. $sign: +1 money in, -1 money out. */
    protected function linesFromRepeater(array $data, int $sign, Entity $entity): array
    {
        $generalFundId = $entity->generalFund()?->id;

        return collect($data['lines'] ?? [])->map(fn (array $row) => [
            'money_account_id' => $data['money_account_id'],
            'fund_id' => $row['fund_id'] ?? $generalFundId,
            'category_id' => $row['category_id'] ?? null,
            'amount_cents' => $sign * Money::toCents($row['amount'] ?? 0),
        ])->all();
    }
}
