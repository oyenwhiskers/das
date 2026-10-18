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
}
