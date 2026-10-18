<?php

$approvalPath = __DIR__.'/APPROVED';
if (! is_file($approvalPath)) {
    fwrite(STDOUT, "Stopped before the first Gemini request. Approval file is absent.\n");
    exit(2);
}

$approval = [];
foreach (file($approvalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    [$name, $value] = array_pad(explode('=', trim($line), 2), 2, '');
    $approval[$name] = $value;
}

$key = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/round1/answer-key.json'), true, 512, JSON_THROW_ON_ERROR);
$pdfPath = dirname(__DIR__, 2).'/tests/Fixtures/round1/synthetic-application.pdf';
$pdfBytes = (string) file_get_contents($pdfPath);
$sha = hash('sha256', $pdfBytes);
if (($approval['sha256'] ?? '') !== $sha || $sha !== ($key['sha256'] ?? '')) {
    fwrite(STDERR, "Stopped before the first Gemini request. The approved file hash does not match the prepared PDF.\n");
    exit(2);
}

$httpCeiling = (int) ($approval['http_ceiling'] ?? 0);
$spendCeiling = (float) ($approval['spend_ceiling_usd'] ?? -1);
if ($httpCeiling < 1 || $spendCeiling < 0) {
    fwrite(STDERR, "Stopped before the first Gemini request. HTTP ceiling or spending ceiling is missing.\n");
    exit(2);
}

require __DIR__.'/bootstrap.php';
require __DIR__.'/HttpCeiling.php';

use App\Enums\DocumentScanStatus;
use App\Models\AiUsage;
use App\Models\Customer;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\GeminiDocumentReader;
use App\Services\SinglePassExtraction;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

if ((string) config('services.gemini.model') !== 'gemini-3.1-flash-lite') {
    fwrite(STDERR, "Stopped before the first Gemini request. The configured model is not gemini-3.1-flash-lite.\n");
    exit(2);
}
if ((int) config('document_scanner.whole_document_max_pages') !== 0 || (int) config('document_scanner.pages_per_batch') !== 2) {
    fwrite(STDERR, "Stopped before the first Gemini request. Shared scanner settings are not the 2-page default.\n");
    exit(2);
}
if (Customer::query()->count() !== 0) {
    fwrite(STDERR, "Stopped before the first Gemini request. The isolated database has customer rows.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
config(['filesystems.disks.local.root' => $root.'/storage/round1/files']);
Storage::forgetDisk('local');
Queue::fake();

$state = ['stop' => null, 'spent' => 0.0];
$ceiling = new Round1HttpCeiling($root.'/storage/round1/http-ledger.json', $httpCeiling);
Http::globalRequestMiddleware(function ($request) use ($ceiling, $state) {
    if ($state['stop'] !== null) {
        throw new RuntimeException('Round 1 stopped before this request: '.$state['stop']);
    }
    $ceiling->before((string) $request->getUri());

    return $request;
});
Http::globalResponseMiddleware(function ($response) use ($state, $spendCeiling) {
    if ($response->getStatusCode() >= 400) {
        $state['stop'] = 'HTTP '.$response->getStatusCode();

        return $response;
    }
    $contents = (string) $response->getBody();
    $body = json_decode($contents, true);
    $response = $response->withBody(Utils::streamFor($contents));
    $result = (new Round1HttpCeiling('', 1))->assess(
        is_array($body) ? ($body['usage'] ?? null) : null,
        $state['spent'],
        $spendCeiling,
        (string) config('services.gemini.model'),
    );
    $state['spent'] = $result['spent'];
    if ($result['stop'] !== null) {
        $state['stop'] = $result['stop'];
    }

    return $response;
});

$user = User::query()->where('email', 'round1-staff@example.net')->firstOrFail();
$schema = CustomerProfileSchema::snapshot();

$runMode = function (string $label, int $pageLimit) use ($user, $schema, $pdfBytes, $state): ?DocumentScan {
    if ($state['stop'] !== null) {
        echo "Skipped {$label}: {$state['stop']}\n";

        return null;
    }
    config(['document_scanner.whole_document_max_pages' => $pageLimit]);
    $path = 'document-scans/'.$label.'.pdf';
    Storage::disk('local')->put($path, $pdfBytes);
    $scan = DocumentScan::query()->create([
        'uploaded_by' => $user->id,
        'original_filename' => 'synthetic-application.pdf',
        'storage_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($pdfBytes),
        'status' => DocumentScanStatus::Pending,
        'gemini_model' => (string) config('services.gemini.model'),
        'profile_schema_hash' => $schema->hash,
    ]);
    try {
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
    } catch (Throwable $exception) {
        $state['stop'] = $exception->getMessage();
        echo "Stopped during {$label}: {$state['stop']}\n";
    }

    return $scan->fresh();
};

$startedAttempts = $ceiling->attempts();
$scanA = $runMode('mode-a', 0);
$scanB = $runMode('mode-b', 4);

$reportPath = $root.'/storage/round1/score-report.json';
$sheet = json_decode((string) file_get_contents($root.'/tests/Fixtures/round1/score-sheet.json'), true, 512, JSON_THROW_ON_ERROR);
$sheet['document_sha256'] = $sha;
$sheet['model'] = (string) config('services.gemini.model');
$sheet['http_ceiling'] = $httpCeiling;
$sheet['spend_ceiling_usd'] = $spendCeiling;
$sheet['http_attempts'] = $ceiling->attempts();
$sheet['stopped'] = $state['stop'];
$sheet['modes']['A'] = array_merge($sheet['modes']['A'], score_mode($scanA, $key));
$sheet['modes']['B'] = array_merge($sheet['modes']['B'], score_mode($scanB, $key));
file_put_contents($reportPath, json_encode($sheet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

if (Customer::query()->count() !== 0) {
    fwrite(STDERR, "Customer rows appeared during the trial.\n");
    exit(1);
}
Queue::assertNothingPushed();
config(['document_scanner.whole_document_max_pages' => 0]);
echo 'score report written, attempts='.($ceiling->attempts() - $startedAttempts)."\n";

/** @param array<string, mixed> $key @return array<string, mixed> */
function score_mode(?DocumentScan $scan, array $key): array
{
    if (! $scan instanceof DocumentScan) {
        return ['errors' => ['mode did not run']];
    }
    $scan->load('extractedProfiles', 'findings');
    $got = [];
    $profiles = 0;
    foreach ($scan->extractedProfiles as $profile) {
        if (($profile->reviewed_projection['grouping_status'] ?? 'active') === 'withdrawn') {
            continue;
        }
        $profiles++;
        foreach ($profile->original_projection['values'] ?? [] as $value) {
            if (! is_array($value) || ! is_string($value['field_id'] ?? null)) {
                continue;
            }
            $got[$value['field_id']][] = $value;
        }
    }
    $correct = [];
    $incorrect = [];
    $missing = [];
    $evidence = [];
    foreach ($key['values'] as $expected) {
        $matches = $got[$expected['field_id']] ?? [];
        $found = null;
        foreach ($matches as $match) {
            if (($match['value'] ?? null) === $expected['value']) {
                $found = $match;
                break;
            }
        }
        if ($found === null) {
            $missing[] = ['field_id' => $expected['field_id'], 'expected' => $expected['value'], 'page' => $expected['page']];
            if ($matches !== []) {
                $incorrect[] = ['field_id' => $expected['field_id'], 'expected' => $expected['value'], 'got' => $matches[0]['value'] ?? null];
                array_pop($missing);
            }

            continue;
        }
        $correct[] = $expected['field_id'];
        $evidence[] = [
            'field_id' => $expected['field_id'],
            'page_ok' => ($found['source_page'] ?? null) === $expected['page'],
            'source_page' => $found['source_page'] ?? null,
            'expected_page' => $expected['page'],
            'has_quote' => is_string($found['evidence_text'] ?? null) && trim($found['evidence_text']) !== '',
        ];
    }
    $unanswered = [];
    foreach ($key['unanswered'] as $blank) {
        if (isset($got[$blank['field_id']])) {
            $unanswered[] = ['field_id' => $blank['field_id'], 'got' => $got[$blank['field_id']][0]['value'] ?? null];
        }
    }
    $names = array_map(
        fn (array $value): mixed => $value['value'] ?? null,
        $got['customer.full_legal_name'] ?? [],
    );
    $wrong = [];
    if (in_array('Hakim Iskandar bin Rahman', $names, true)) {
        $wrong[] = 'Related person name was stored as the applicant name.';
    }
    if (in_array('Northwind Ledger Sdn Bhd', $names, true)) {
        $wrong[] = 'Employer name was stored as the applicant name.';
    }
    if ($profiles !== 1) {
        $wrong[] = 'Primary applicant count is '.$profiles.', expected 1.';
    }
    $usage = AiUsage::query()->where('document_scan_id', $scan->id)->get();
    $processing = $scan->findings?->record_ownership['processing'] ?? [];
    $fragments = $scan->findings?->record_ownership['fragments'] ?? [];
    $held = collect($fragments)->where('state', 'held')->count();

    return [
        'scan_id' => $scan->id,
        'extracted_profiles' => $profiles,
        'correct' => $correct,
        'incorrect' => $incorrect,
        'missing' => $missing,
        'wrong_person' => $wrong,
        'unanswered_populated' => $unanswered,
        'evidence' => $evidence,
        'held_fragments' => $held,
        'duration_ms' => $processing['duration_ms'] ?? null,
        'gemini_requests_recorded' => $processing['requests'] ?? null,
        'input_tokens' => $usage->sum('input_tokens'),
        'output_tokens' => $usage->sum('output_tokens'),
        'thought_tokens' => $usage->sum('thought_tokens'),
        'cached_tokens' => $usage->sum('cached_tokens'),
        'recorded_cost_usd' => $usage->contains(fn ($row) => $row->cost_usd === null) ? null : $usage->sum('cost_usd'),
        'fallback' => (bool) ($processing['subdivided'] ?? false),
        'errors' => $scan->profile_unresolved ?? [],
    ];
}
