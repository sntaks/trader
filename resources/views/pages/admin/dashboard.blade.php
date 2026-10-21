<x-layouts::app :title="__('Admin Dashboard')">
    <div class="max-w-6xl mx-auto space-y-6 py-8 px-4">

        <div>
            <flux:heading size="xl">Platform Dashboard</flux:heading>
            <flux:text class="mt-1 text-zinc-500">Platform-wide analytics and user management overview.</flux:text>
        </div>

        <livewire:admin.markup-earnings />

        <livewire:admin.platform-dashboard />

    </div>
</x-layouts::app>
