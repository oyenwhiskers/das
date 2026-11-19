<?php

namespace App\Services\Bimbsec;

use App\Enums\AddressType;
use App\Models\BimbsecApplication;
use App\Models\BimbsecDocument;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class CorporateGenerator
{
    private CorporateCatalog $catalog;

    private CorporateResolver $resolver;

    private Renderer $renderer;

    public function __construct(?CorporateCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? new CorporateCatalog;
        $this->resolver = new CorporateResolver($this->catalog);
        $this->renderer = new Renderer($this->catalog);
    }

    /**
     * @param  array<string, mixed>  $choices
     * @return array{source_hash: string, warnings: list<array{level: string, label: string, message: string, field_id: ?string}>, blocked: bool}
     */
    public function assess(Customer $customer, array $choices = []): array
    {
        $resolved = $this->resolver->resolve($customer, BimbsecApplication::normalizeChoices($choices));
        $warnings = $this->withoutMirroredMailing(
            $customer,
            $this->merge($resolved['warnings'], $this->renderer->inspect($resolved['draws'])),
        );

        return [
            'source_hash' => $resolved['source_hash'],
            'warnings' => $warnings,
            'blocked' => collect($warnings)->contains(fn (array $warning): bool => ($warning['level'] ?? '') === 'blocking'),
        ];
    }

    public function mailingMirrorsRegistered(Customer $customer): bool
    {
        $customer->loadMissing(['addresses']);
        $mailing = $customer->addresses->firstWhere('type', AddressType::Mailing);

        return $mailing === null || ($mailing->same_as_residential ?? true);
    }

    public function refresh(BimbsecApplication $application, User $user): ?BimbsecDocument
    {
        $document = $application->document;
        if ($document === null) {
            return null;
        }
        $application->unsetRelation('customer');
        $application->load('customer');
        $resolved = $this->resolver->resolve($application->customer, $application->choices ?? []);
        if (hash_equals($document->source_hash, $resolved['source_hash']) && Storage::disk('local')->exists($document->storage_path)) {
            return $document;
        }

        return $this->generate($application, $user);
    }

    public function generate(BimbsecApplication $application, User $user): BimbsecDocument
    {
        abort_unless($application->form_kind === 'corporate', 404);
        $application->loadMissing('customer');
        abort_unless($application->customer->isCorporate(), 404);
        $resolved = $this->resolver->resolve($application->customer, $application->choices ?? []);
        $rendered = $this->renderer->render($resolved['draws']);
        $warnings = $this->merge($resolved['warnings'], $rendered['warnings']);
        $blocked = collect($warnings)->contains(fn (array $warning): bool => $warning['level'] === 'blocking');
        $path = 'bimbsec-corporate/applications/'.$application->id.'.pdf';
        Storage::disk('local')->put($path, $rendered['bytes']);

        return BimbsecDocument::query()->updateOrCreate(
            ['bimbsec_application_id' => $application->id],
            [
                'customer_id' => $application->customer_id,
                'source_hash' => $resolved['source_hash'],
                'template_sha256' => $this->catalog->templateHash,
                'mapping_sha256' => $this->catalog->mappingHash,
                'storage_path' => $path,
                'page_count' => $rendered['pages'],
                'warnings' => $warnings,
                'print_blocked' => $blocked,
                'generated_by' => $user->id,
                'generated_at' => now(),
            ],
        );
    }

    /**
     * @param  list<array{level: string, label: string, message: string, field_id: ?string}>  $left
     * @param  list<array{level: string, label: string, message: string, field_id: ?string}>  $right
     * @return list<array{level: string, label: string, message: string, field_id: ?string}>
     */
    private function merge(array $left, array $right): array
    {
        $warnings = [];
        foreach ([...$left, ...$right] as $warning) {
            $key = $warning['level'].'|'.$warning['message'];
            $warnings[$key] = $warning;
        }

        return array_values($warnings);
    }

    /**
     * @param  list<array{level: string, label: string, message: string, field_id: ?string}>  $warnings
     * @return list<array{level: string, label: string, message: string, field_id: ?string}>
     */
    private function withoutMirroredMailing(Customer $customer, array $warnings): array
    {
        if (! $this->mailingMirrorsRegistered($customer)) {
            return $warnings;
        }

        return array_values(array_filter($warnings, function (array $warning): bool {
            $reference = $warning['reference_key'] ?? null;
            if (! is_string($reference) && is_string($warning['field_id'] ?? null)) {
                $mapped = collect($this->catalog->fields())->firstWhere('id', $warning['field_id']);
                $reference = $mapped['source']['reference_key'] ?? null;
            }

            return $reference !== 'corporate.mailing_address' && $reference !== 'company.mailing_address';
        }));
    }
}
