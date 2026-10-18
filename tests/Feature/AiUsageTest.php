<?php

namespace Tests\Feature;

use App\Livewire\AiUsage\Index;
use App\Models\AiUsage;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\GeminiDocumentReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_record_tokens_cost_and_failed_responses(): void
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        $scan = DocumentScan::factory()->create();
        Storage::disk('local')->put($scan->storage_path, 'image bytes');

        Http::fakeSequence()->push([
            'status' => 'completed',
            'output_text' => json_encode(['document_type' => null, 'title' => null, 'summary' => null, 'readable' => true, 'limitations' => null, 'records' => []]),
            'usage' => ['total_input_tokens' => 1000, 'total_output_tokens' => 200, 'total_thought_tokens' => 100, 'total_cached_tokens' => 100],
        ])->push(['error' => ['status' => 'UNAVAILABLE']], 503);

        app(GeminiDocumentReader::class)->read($scan);
        try {
            app(GeminiDocumentReader::class)->read($scan);
            $this->fail('Expected the failed API response to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(503, $exception->getCode());
        }

        $this->assertDatabaseCount('ai_usages', 2);
        $this->assertDatabaseHas('ai_usages', [
            'document_scan_id' => $scan->id, 'http_status' => 200,
            'input_tokens' => 1000, 'output_tokens' => 200, 'thought_tokens' => 100,
            'cached_tokens' => 100, 'cost_usd' => '0.000678',
        ]);
        $this->assertDatabaseHas('ai_usages', ['http_status' => 503, 'cost_usd' => null]);
    }

    public function test_usage_page_is_admin_only(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff)->get(route('ai-usage.index'))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('ai-usage.index'))->assertOk()->assertSee('AI Usage')->assertSee('gemini-3.1-flash-lite');
    }

    public function test_usage_page_converts_the_selected_period_to_ringgit(): void
    {
        config()->set('services.gemini.usd_to_myr', 4);
        $admin = User::factory()->admin()->create();
        $today = DocumentScan::factory()->create();
        $earlier = DocumentScan::factory()->create();
        AiUsage::query()->create([
            'document_scan_id' => $today->id, 'model' => 'gemini-3.1-flash-lite', 'http_status' => 200,
            'input_tokens' => 10, 'output_tokens' => 1, 'thought_tokens' => 0, 'cached_tokens' => 0, 'cost_usd' => 1,
        ]);
        $old = AiUsage::query()->create([
            'document_scan_id' => $earlier->id, 'model' => 'gemini-3.1-flash-lite', 'http_status' => 200,
            'input_tokens' => 10, 'output_tokens' => 1, 'thought_tokens' => 0, 'cached_tokens' => 0, 'cost_usd' => 3,
        ]);
        $old->forceFill(['created_at' => now()->subMonth()])->save();

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('RM 4.0000')
            ->assertSee('Cost per document')
            ->assertDontSee('RM 12.0000')
            ->call('setPeriod', 'year')
            ->assertSee('RM 16.0000')
            ->call('setPeriod', 'date')
            ->set('on', now()->subMonth()->toDateString())
            ->assertSee('RM 12.0000')
            ->call('resetHistory')
            ->assertDontSee('RM 4.0000');

        $this->assertDatabaseCount('ai_usages', 0);
    }

    public function test_cost_per_document_uses_only_spend_still_linked_to_an_upload(): void
    {
        config()->set('services.gemini.usd_to_myr', 4);
        $admin = User::factory()->admin()->create();
        $scan = DocumentScan::factory()->create();
        AiUsage::query()->create([
            'document_scan_id' => $scan->id, 'model' => 'gemini-3.1-flash-lite', 'http_status' => 200,
            'input_tokens' => 10, 'output_tokens' => 1, 'thought_tokens' => 0, 'cached_tokens' => 0, 'cost_usd' => 1,
        ]);
        AiUsage::query()->create([
            'document_scan_id' => null, 'model' => 'gemini-3.1-flash-lite', 'http_status' => 200,
            'input_tokens' => 10, 'output_tokens' => 1, 'thought_tokens' => 0, 'cached_tokens' => 0, 'cost_usd' => 3,
        ]);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('RM 16.0000')
            ->assertSee('RM 8.0000')
            ->assertSee('RM 4.0000')
            ->assertSee('RM 12.0000')
            ->assertSee('Cost per document leaves that spend out');
    }
}
