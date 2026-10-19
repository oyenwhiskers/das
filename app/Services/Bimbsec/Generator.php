<?php

namespace App\Services\Bimbsec;

use App\Models\BimbsecApplication;
use App\Models\BimbsecDocument;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class Generator
{
    public function __construct(
        private Catalog $catalog,
        private Resolver $resolver,
        private Renderer $renderer,
    ) {}

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

    /** Mailing uses the residential record, or the same text, so only residential needs shortening. */
    public function mailingMirrorsResidential(Customer $customer, ?string $item = null): bool
    {
        $customer->loadMissing(['mailingAddress', 'residentialAddress']);
        $mailing = $customer->mailingAddress;
        $residential = $customer->residentialAddress;
        if ($mailing === null || $residential === null) {
            return false;
        }
        if ($mailing->same_as_residential === true) {
            return true;
        }
        if ($item === null || $item === '' || $item === 'address_lines') {
            $mailLines = trim(implode(' ', array_filter([(string) $mailing->line_1, (string) $mailing->line_2])));
            $homeLines = trim(implode(' ', array_filter([(string) $residential->line_1, (string) $residential->line_2])));
            if ($item === 'address_lines') {
                return $mailLines !== '' && $mailLines === $homeLines;
            }
        }
        if ($item === null || $item === '') {
            foreach (['line_1', 'line_2', 'city', 'state', 'postcode', 'country'] as $column) {
                if (trim((string) ($mailing->{$column} ?? '')) !== trim((string) ($residential->{$column} ?? ''))) {
                    return false;
                }
            }

            return true;
        }

        return trim((string) ($mailing->{$item} ?? '')) !== ''
            && trim((string) ($mailing->{$item} ?? '')) === trim((string) ($residential->{$item} ?? ''));
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
        $application->loadMissing('customer');
        $resolved = $this->resolver->resolve($application->customer, $application->choices ?? []);
        $rendered = $this->renderer->render($resolved['draws']);
        $warnings = $this->merge($resolved['warnings'], $rendered['warnings']);
        $blocked = collect($warnings)->contains(fn (array $warning): bool => $warning['level'] === 'blocking');
        $path = 'bimbsec/applications/'.$application->id.'.pdf';
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
        return array_values(array_filter($warnings, function (array $warning) use ($customer): bool {
            $reference = $warning['reference_key'] ?? null;
            $item = (string) ($warning['printed_item'] ?? '');
            if (! is_string($reference) && is_string($warning['field_id'] ?? null)) {
                $mapped = collect($this->catalog->fields())->firstWhere('id', $warning['field_id']);
                $reference = $mapped['source']['reference_key'] ?? null;
                $item = (string) ($mapped['printed_item'] ?? $item);
            }
            if ($reference === 'addresses.mailing') {
                return ! $this->mailingMirrorsResidential($customer, $item !== '' ? $item : null);
            }
            if ($reference === 'employment.business_address') {
                $job = $this->resolver->subjects($customer, [])['employment'] ?? null;

                return ! $this->resolver->businessAddressUsesResidential($customer, $job);
            }

            return true;
        }));
    }
}
