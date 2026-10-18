<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPepAssociation extends Model
{
    /** @var list<string> */
    protected $fillable = ['customer_id', 'customer_relationship_id', 'related_person_id', 'relationship', 'occupation', 'position', 'position_meaning', 'organization', 'country', 'position_status', 'remarks', 'active_identity_key', 'started_on', 'ended_on', 'superseded_at', 'recorded_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['started_on' => 'date', 'ended_on' => 'date', 'superseded_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerRelationship(): BelongsTo
    {
        return $this->belongsTo(CustomerRelationship::class);
    }

    public function relatedPerson(): BelongsTo
    {
        return $this->belongsTo(RelatedPerson::class);
    }

    public function displayName(): string
    {
        return $this->customerRelationship?->displayName() ?? $this->relatedPerson?->full_legal_name ?? $this->customer?->full_legal_name ?? '';
    }
}
