<?php

namespace App\Models;

use App\Enums\IdentificationType;
use Database\Factories\RelatedPersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelatedPerson extends Model
{
    /** @use HasFactory<RelatedPersonFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'full_legal_name', 'identification_type', 'identification_number',
        'normalized_identification_number', 'date_of_birth', 'gender',
        'nationality', 'mobile', 'home_telephone', 'email', 'occupation',
        'employer_name', 'employer_address', 'employer_address_line_1',
        'employer_address_line_2', 'employer_city', 'employer_state',
        'employer_postcode', 'employer_country',
    ];

    /** @var list<string> */
    protected $hidden = ['identification_number', 'normalized_identification_number'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'identification_type' => IdentificationType::class,
            'identification_number' => 'encrypted',
            'date_of_birth' => 'date',
        ];
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(CustomerRelationship::class);
    }
}
