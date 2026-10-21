<?php

namespace App\Livewire\CopyTrading;

use Illuminate\Support\Facades\Redis;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class MasterOutcomesTicker extends Component
{
    #[Locked]
    public int $connectionId;

    public string $pattern = '';

    public bool $patternEnabled = false;

    /** Only the master the current user copies from may be watched. */
    public function mount(int $connectionId): void
    {
        abort_unless(auth()->user()?->copySetting?->master_connection_id === $connectionId, 403);

        $this->connectionId = $connectionId;
    }

    /** Outcomes since the last copy trade fired for the current user (chronological order). */
    #[Computed]
    public function outcomes(): array
    {
        $totalCount = (int) (Redis::get("master_outcomes_count:{$this->connectionId}") ?? 0);
        $offsetCount = (int) Redis::get("master_outcomes_offset:{$this->connectionId}:".auth()->id());
        $newCount = max(0, $totalCount - $offsetCount);

        if ($totalCount === 0) {
            // No count key yet (pre-deploy data) — show everything available
            $raw = Redis::lrange("master_outcomes:{$this->connectionId}", 0, -1);

            return array_values(array_reverse(array_map('intval', $raw)));
        }

        if ($newCount === 0) {
            return [];
        }

        $raw = Redis::lrange("master_outcomes:{$this->connectionId}", 0, min($newCount, 50) - 1);

        return array_values(array_reverse(array_map('intval', $raw)));
    }

    /** Whether the current tail of outcomes matches the configured pattern. */
    #[Computed]
    public function patternMatched(): bool
    {
        if (! $this->patternEnabled || strlen($this->pattern) === 0) {
            return false;
        }

        $patLen = strlen($this->pattern);
        $outcomes = $this->outcomes;

        if (count($outcomes) < $patLen) {
            return false;
        }

        $tail = array_slice($outcomes, -$patLen);
        $expected = array_map('intval', str_split($this->pattern));

        return $tail === $expected;
    }

    public function render(): View
    {
        return view('livewire.copy-trading.master-outcomes-ticker');
    }
}
