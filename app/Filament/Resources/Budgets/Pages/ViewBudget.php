<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use App\Models\Budget;
use App\Services\Budgets\BudgetException;
use App\Services\Budgets\BudgetService;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewBudget extends ViewRecord
{
    protected static string $resource = BudgetResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->status === 'draft' && Access::can('setup')) {
            app(BudgetService::class)->ensureLines($this->record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve this budget')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn () => $this->record->status === 'draft' && Access::can('setup'))
                ->modalHeading('Approve this budget?')
                ->modalDescription('Once approved it cannot be edited. To change it later you make a new version, with a reason, and this one stays on record.')
                ->schema([
                    TextInput::make('note')->label('Approved by / at which meeting')
                        ->placeholder('e.g. Circuit Quarterly Meeting, 18 February 2026')
                        ->required()->maxLength(255),
                ])
                ->action(function (array $data) {
                    try {
                        app(BudgetService::class)->approve($this->record, auth()->user(), $data['note']);
                    } catch (BudgetException $e) {
                        Notification::make()->title('Could not approve')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title('Budget approved')->success()->send();
                    $this->redirect(BudgetResource::getUrl('view', ['record' => $this->record]));
                }),

            Action::make('newVersion')
                ->label('Change this budget')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn () => $this->record->status === 'approved'
                    && Access::can('setup')
                    && ! Budget::where('entity_id', $this->record->entity_id)->where('year', $this->record->year)->where('version', '>', $this->record->version)->exists())
                ->modalHeading('Make a new version of this budget?')
                ->modalDescription('This starts a draft copy that you can edit and then approve. The current approved budget stays in force until you approve the new one.')
                ->schema([
                    Textarea::make('reason')->label('Why is it being changed?')->required()->minLength(5),
                ])
                ->action(function (array $data) {
                    try {
                        $draft = app(BudgetService::class)->newVersion($this->record, auth()->user(), $data['reason']);
                    } catch (BudgetException $e) {
                        Notification::make()->title('Could not start a new version')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $this->redirect(BudgetResource::getUrl('view', ['record' => $draft]));
                }),

            Action::make('discard')
                ->label('Discard draft')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn () => $this->record->status === 'draft' && Access::can('setup'))
                ->requiresConfirmation()
                ->modalHeading('Discard this draft?')
                ->modalDescription('The draft and everything entered in it will be removed. Approved budgets are never affected.')
                ->action(function () {
                    try {
                        app(BudgetService::class)->discard($this->record, auth()->user());
                    } catch (BudgetException $e) {
                        Notification::make()->title('Could not discard')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    $this->redirect(BudgetResource::getUrl());
                }),
        ];
    }

}