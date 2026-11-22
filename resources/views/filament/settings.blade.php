<x-filament-panels::page>
    @if ($this->pin)
        <div wire:poll.2s="checkPlexLogin" class="hidden"></div>
    @endif

    {{ $this->content }}
</x-filament-panels::page>
