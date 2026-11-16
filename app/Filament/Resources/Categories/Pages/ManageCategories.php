<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('switchOn')
                ->label('Switch on more types')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('gray')
                ->visible(fn () => Access::can('setup') && $this->switchedOff('income')->isNotEmpty() || $this->switchedOff('expense')->isNotEmpty())
                ->modalHeading('Switch on more types')
                ->modalDescription('Tick the types you use. You can switch them off again at any time, and nothing already recorded is affected.')
                ->schema([
                    CheckboxList::make('income')->label('Money in')
                        ->options(fn () => $this->options('income'))->searchable()->bulkToggleable()
                        ->visible(fn () => $this->switchedOff('income')->isNotEmpty()),
                    CheckboxList::make('expense')->label('Money out')
                        ->options(fn () => $this->options('expense'))->searchable()->bulkToggleable()
                        ->visible(fn () => $this->switchedOff('expense')->isNotEmpty()),
                ])
                ->action(function (array $data) {
                    $ids = array_merge($data['income'] ?? [], $data['expense'] ?? []);

                    if ($ids === []) {
                        return;
                    }

                    // One by one, so each change is recorded in the audit log.
                    $switched = Category::where('entity_id', Filament::getTenant()->id)->whereIn('id', $ids)->get()
                        ->each(fn (Category $c) => $c->update(['is_active' => true]));

                    Notification::make()->title("Switched on {$switched->count()} type(s)")->success()->send();
                }),

            CreateAction::make()->label('Add type')
                ->mutateDataUsing(fn (array $data) => $data + ['entity_id' => Filament::getTenant()->id]),
        ];
    }

    private function switchedOff(string $kind)
    {
        return Category::where('entity_id', Filament::getTenant()->id)
            ->where('kind', $kind)->where('is_active', false)
            ->orderBy('sort_order')->get();
    }

    /** @return array<int,string> */
    private function options(string $kind): array
    {
        return $this->switchedOff($kind)
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->name . ($c->return_line_code ? "  (line {$c->return_line_code})" : '')])
            ->all();
    }
}