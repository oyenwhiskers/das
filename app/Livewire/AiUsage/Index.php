<?php

namespace App\Livewire\AiUsage;

use App\Models\AiUsage;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('AI Usage — DAS')]
class Index extends Component
{
    use WithPagination;

    public string $period = 'month';

    public string $on = '';

    public function mount(): void
    {
        $this->on = now()->toDateString();
    }

    public function setPeriod(string $period): void
    {
        if (! in_array($period, ['today', 'week', 'month', 'year', 'date'], true)) {
            return;
        }
        $this->period = $period;
        $this->resetPage();
    }

    public function updatedOn(): void
    {
        $this->period = 'date';
        $this->resetPage();
    }

    public function resetHistory(): void
    {
        AiUsage::query()->delete();
        $this->resetPage();
        $this->dispatch('notify', type: 'success', message: 'Usage history reset.');
    }

    public function render(): View
    {
        [$start, $end] = $this->range();
        $query = AiUsage::query()->where('model', 'gemini-3.1-flash-lite')->whereBetween('created_at', [$start, $end]);
        $totals = (clone $query)->selectRaw('COUNT(*) as requests, COUNT(DISTINCT document_scan_id) as documents, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(thought_tokens) as thought_tokens, SUM(cost_usd) as cost_usd, SUM(CASE WHEN document_scan_id IS NOT NULL THEN cost_usd ELSE 0 END) as linked_cost_usd, SUM(CASE WHEN document_scan_id IS NULL AND cost_usd IS NOT NULL THEN cost_usd ELSE 0 END) as unlinked_cost_usd, SUM(CASE WHEN document_scan_id IS NULL AND cost_usd IS NOT NULL THEN 1 ELSE 0 END) as unlinked_priced, SUM(CASE WHEN cost_usd IS NULL THEN 1 ELSE 0 END) as unpriced, SUM(CASE WHEN cost_usd IS NULL THEN 0 ELSE 1 END) as priced_requests')->first();
        $rate = (float) config('services.gemini.usd_to_myr');
        $cost = ((float) ($totals->cost_usd ?? 0)) * $rate;
        $documents = (int) $totals->documents;

        return view('livewire.ai-usage.index', [
            'totals' => $totals,
            'rate' => $rate,
            'cost' => $cost,
            'unlinkedCost' => ((float) ($totals->unlinked_cost_usd ?? 0)) * $rate,
            'averageDocument' => $documents > 0 ? ((float) ($totals->linked_cost_usd ?? 0)) * $rate / $documents : null,
            'averageRequest' => ((int) $totals->priced_requests) > 0 ? $cost / (int) $totals->priced_requests : 0,
            'requests' => $query->latest()->paginate(20),
        ])->layout('layouts.app');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(): array
    {
        $now = now();

        return match ($this->period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'date' => [$day = Carbon::parse($this->on ?: now())->startOfDay(), $day->copy()->endOfDay()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }
}
