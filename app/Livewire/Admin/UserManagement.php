<?php

namespace App\Livewire\Admin;

use App\Exceptions\DerivApiException;
use App\Models\User;
use App\Services\DerivApiService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class UserManagement extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $masterAccountUserId = null;

    public ?string $selectedMasterAccountId = null;

    public ?string $masterAccountError = null;

    /** Runs on mount and on every update, so a demoted admin loses access mid-session. */
    public function boot(): void
    {
        abort_unless((bool) auth()->user()?->is_admin, 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleAdmin(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            return;
        }

        $user->is_admin = ! $user->is_admin;
        $user->save();
    }

    public function toggleMaster(int $userId): void
    {
        $connection = User::findOrFail($userId)->derivConnection;

        if (! $connection) {
            return;
        }

        $newType = $connection->isMaster() ? 'follower' : 'master';

        if ($newType === 'follower') {
            $connection->followers()->delete();
            $connection->update(['type' => $newType, 'master_account_id' => null]);
        } else {
            $connection->update(['type' => $newType]);
        }
    }

    public function openMasterAccountSelector(int $userId): void
    {
        $connection = User::findOrFail($userId)->derivConnection;

        if (! $connection) {
            return;
        }

        $this->masterAccountUserId = $userId;
        $this->selectedMasterAccountId = $connection->master_account_id;
        $this->masterAccountError = null;

        $this->js('document.dispatchEvent(new CustomEvent("modal-show", { detail: { name: "master-account-selector" } }))');
    }

    public function saveMasterAccount(): void
    {
        $connection = User::findOrFail($this->masterAccountUserId)->derivConnection;

        if (! $connection) {
            return;
        }

        $validIds = array_column($this->masterAccountOptions, 'account_id');

        if ($this->selectedMasterAccountId && ! in_array($this->selectedMasterAccountId, $validIds, true)) {
            $this->masterAccountError = 'Invalid account selected.';

            return;
        }

        $connection->update(['master_account_id' => $this->selectedMasterAccountId]);

        $this->masterAccountUserId = null;
        $this->selectedMasterAccountId = null;
        $this->masterAccountError = null;

        $this->js('document.dispatchEvent(new CustomEvent("modal-close", { detail: { name: "master-account-selector" } }))');
    }

    public function closeMasterAccountModal(): void
    {
        $this->masterAccountUserId = null;
        $this->selectedMasterAccountId = null;
        $this->masterAccountError = null;

        $this->js('document.dispatchEvent(new CustomEvent("modal-close", { detail: { name: "master-account-selector" } }))');
    }

    #[Computed]
    public function masterAccountOptions(): array
    {
        if (! $this->masterAccountUserId) {
            return [];
        }

        $connection = User::find($this->masterAccountUserId)?->derivConnection;

        if (! $connection || $connection->isExpired()) {
            return [];
        }

        try {
            return app(DerivApiService::class)->getAccounts($connection);
        } catch (DerivApiException) {
            return [];
        }
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->with('derivConnection')
            ->when($this->search, fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
                ->orWhere('account_no', 'like', "%{$this->search}%")
            )
            ->latest()
            ->paginate(15);
    }

    public function render(): View
    {
        return view('livewire.admin.user-management');
    }
}
