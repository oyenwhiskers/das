<?php

namespace App\Models;

use App\Enums\ContactType;
use Database\Factories\CustomerRelationshipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerRelationship extends Model
{
    /** @use HasFactory<CustomerRelationshipFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'customer_id', 'related_person_id', 'related_customer_id',
        'relationship', 'other_relationship', 'is_next_of_kin',
        'is_primary_next_of_kin', 'active_identity_key',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_next_of_kin' => 'boolean',
            'is_primary_next_of_kin' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function relatedPerson(): BelongsTo
    {
        return $this->belongsTo(RelatedPerson::class);
    }

    public function relatedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'related_customer_id');
    }

    public function displayName(): string
    {
        return $this->relatedCustomer?->full_legal_name ?? $this->relatedPerson?->full_legal_name ?? '';
    }

    public function contactNumber(): ?string
    {
        if ($this->relatedCustomer) {
            return $this->relatedCustomer->primary_mobile
                ?: $this->relatedCustomer->contacts->firstWhere('type', ContactType::Home)?->value;
        }

        return $this->relatedPerson?->mobile ?: $this->relatedPerson?->home_telephone;
    }

    public function relationshipLabel(): string
    {
        return $this->relationship === 'other' ? ($this->other_relationship ?: 'Other') : ucfirst($this->relationship);
    }
}
