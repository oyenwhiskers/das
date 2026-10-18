<?php

namespace Database\Factories;

use App\Enums\DocumentScanStatus;
use App\Models\DocumentScan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentScan>
 */
class DocumentScanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uploaded_by' => User::factory(),
            'original_filename' => 'sample.pdf',
            'storage_path' => 'document-scans/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => DocumentScanStatus::Pending,
        ];
    }
}
