<?php

namespace Database\Factories;

use App\Enums\AttachmentScanStatus;
use App\Models\AttachmentScan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttachmentScan>
 */
class AttachmentScanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uploaded_by' => User::factory(),
            'original_filename' => 'attachment.pdf',
            'storage_path' => 'attachment-scans/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => AttachmentScanStatus::Pending,
        ];
    }
}
