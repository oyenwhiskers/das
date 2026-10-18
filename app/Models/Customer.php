<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\IdentificationType;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            $defaultSalutation = self::defaultSalutationForGender($customer->gender, $customer->marital_status);

            if ($defaultSalutation === null) {
                return;
            }

            $driverChanged = ($customer->isDirty('gender') || $customer->isDirty('marital_status')) && ! $customer->isDirty('salutation');
            $automatic = in_array(strtolower((string) $customer->salutation), array_map(strtolower(...), self::AUTOMATIC_SALUTATIONS), true);
            if (blank($customer->salutation) || ($driverChanged && $automatic)) {
                $customer->salutation = $defaultSalutation;
            }
        });
    }

    /** @var list<string> */
    public const AUTOMATIC_SALUTATIONS = ['Encik', 'Cik', 'Puan', 'Mr.', 'Ms.'];

    public static function defaultSalutationForGender(?string $gender, ?string $maritalStatus = null): ?string
    {
        return match (strtolower(trim((string) $gender))) {
            'male' => 'Encik',
            'female' => strcasecmp(trim((string) $maritalStatus), 'Married') === 0 ? 'Puan' : 'Cik',
            default => null,
        };
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'type',
        'status',
        'salutation',
        'full_legal_name',
        'date_of_birth',
        'gender',
        'nationality',
        'country_of_residence',
        'race',
        'marital_status',
        'primary_identification_type',
        'primary_identification_number',
        'primary_mobile',
        'primary_email',
        'employment_status',
        'employment_status_other',
        'retirement_date',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
            'primary_identification_type' => IdentificationType::class,
            'date_of_birth' => 'date',
            'retirement_date' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function identifications(): HasMany
    {
        return $this->hasMany(CustomerIdentification::class);
    }

    public function primaryIdentification(): HasOne
    {
        return $this->hasOne(CustomerIdentification::class)->where('is_primary', true);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function residentialAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('type', 'residential');
    }

    public function mailingAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('type', 'mailing');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CustomerActivity::class)->latest('created_at');
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(CustomerRelationship::class);
    }

    public function complianceDeclarations(): HasMany
    {
        return $this->hasMany(CustomerComplianceDeclaration::class);
    }

    public function pepAssociations(): HasMany
    {
        return $this->hasMany(CustomerPepAssociation::class);
    }

    public function employments(): HasMany
    {
        return $this->hasMany(CustomerEmployment::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CustomerBankAccount::class);
    }

    public function financialDeclarations(): HasMany
    {
        return $this->hasMany(CustomerFinancialDeclaration::class);
    }

    public function brokerageDeclarations(): HasMany
    {
        return $this->hasMany(CustomerBrokerageDeclaration::class);
    }

    public function investmentProfiles(): HasMany
    {
        return $this->hasMany(CustomerInvestmentProfile::class);
    }

    public function latestInvestmentProfile(): HasOne
    {
        return $this->hasOne(CustomerInvestmentProfile::class)->latestOfMany();
    }

    public function brokerageAccounts(): HasMany
    {
        return $this->hasMany(CustomerBrokerageAccount::class);
    }

    public function bimbsecApplications(): HasMany
    {
        return $this->hasMany(BimbsecApplication::class);
    }

    public function currentBimbsecApplication(): ?BimbsecApplication
    {
        $existing = $this->bimbsecApplications()->with('document')->orderBy('id')->get();

        return $existing->first(fn (BimbsecApplication $row): bool => $row->document !== null) ?? $existing->first();
    }

    public function taxIdentification(): HasOne
    {
        return $this->hasOne(CustomerTaxIdentification::class);
    }

    public function taxResidencies(): HasMany
    {
        return $this->hasMany(CustomerTaxResidency::class);
    }

    public function fatcaDeclarations(): HasMany
    {
        return $this->hasMany(CustomerFatcaDeclaration::class);
    }

    public function isArchived(): bool
    {
        return $this->status === CustomerStatus::Archived;
    }

    public function isActive(): bool
    {
        return $this->status === CustomerStatus::Active;
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where('full_legal_name', 'like', $like)
                ->orWhere('reference', 'like', $like)
                ->orWhere('primary_identification_number', 'like', $like)
                ->orWhere('primary_email', 'like', $like)
                ->orWhere('primary_mobile', 'like', $like);
        });
    }
}
