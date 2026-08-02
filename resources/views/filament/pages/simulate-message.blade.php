<x-filament-panels::page>
    <form wire:submit="send">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-6">
            Enviar
        </x-filament::button>
    </form>
</x-filament-panels::page>
