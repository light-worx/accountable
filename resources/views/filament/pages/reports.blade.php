<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-4 flex flex-wrap gap-3">
        @if ($url = $this->downloadUrl())
            <x-filament::button tag="a" :href="$url" target="_blank" icon="heroicon-o-document-arrow-down" size="lg">
                Open management accounts
            </x-filament::button>
        @endif

        @if ($url = $this->returnUrl())
            <x-filament::button tag="a" :href="$url" target="_blank" color="gray" icon="heroicon-o-document-text" size="lg">
                {{ $this->returnLabel() }}
            </x-filament::button>
        @endif
    </div>
</x-filament-panels::page>