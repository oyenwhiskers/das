<?php

namespace App\Services\Bimbsec;

class CorporateCatalog extends Catalog
{
    public function __construct()
    {
        parent::__construct(
            storage_path('templates/BIMBSEC_Corporate_Feb2025_PDF_Template_Mapping.json'),
            storage_path('templates/BIMBSEC_Corporate_Feb2025.pdf'),
        );
    }
}
