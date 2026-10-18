<?php

namespace App\Services\Bimbsec;

use App\Models\BimbsecApplication;
use App\Models\BimbsecDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class Generator
{
    public function __construct(
        private Catalog $catalog,
        private Resolver $resolver,
        private Renderer $renderer,
    ) {}

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
}
