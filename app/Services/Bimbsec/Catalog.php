<?php

namespace App\Services\Bimbsec;

use RuntimeException;

class Catalog
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct()
    {
        $raw = file_get_contents($this->mappingPath());
        if ($raw === false) {
            throw new RuntimeException('The BIMBSEC mapping file could not be read.');
        }
        $this->mappingHash = hash('sha256', $raw);
        $this->data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->templateHash = hash_file('sha256', $this->templatePath());
        if (! is_string($this->templateHash) || ! hash_equals((string) ($this->data['template']['sha256'] ?? ''), $this->templateHash)) {
            throw new RuntimeException('The BIMBSEC template does not match the mapping file.');
        }
    }

    public string $templateHash;

    public string $mappingHash;

    public function templatePath(): string
    {
        return storage_path('templates/BIMBSEC_Feb2025.pdf');
    }

    public function mappingPath(): string
    {
        return storage_path('templates/BIMBSEC_Feb2025_PDF_Template_Mapping.json');
    }

    public function pageCount(): int
    {
        return (int) $this->data['template']['page_count'];
    }

    /** @return list<array<string, mixed>> */
    public function fields(): array
    {
        return $this->data['fields'];
    }

    /**
     * Exact character-cell capacity for a form box (same count Renderer uses).
     * Null when the field is free-text, a table row, or not character cells.
     */
    public function cellCapacity(string $referenceKey, string $printedItem): ?int
    {
        return $this->cellCapacities()[$referenceKey.'|'.$printedItem] ?? null;
    }

    /** @return array<string, int> */
    public function cellCapacities(): array
    {
        return once(function (): array {
            $caps = [];
            foreach ($this->fields() as $field) {
                if (($field['placement']['type'] ?? '') !== 'character_cells') {
                    continue;
                }
                $capacity = 0;
                foreach ($field['placement']['rows'] ?? [] as $row) {
                    $capacity += (int) ($row['count'] ?? 0);
                }
                if ($capacity < 1) {
                    continue;
                }
                $key = ($field['source']['reference_key'] ?? '').'|'.($field['printed_item'] ?? '');
                $caps[$key] = $capacity;
            }

            return $caps;
        });
    }
}
