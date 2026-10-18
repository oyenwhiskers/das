<?php

namespace App\Models;

use Database\Factories\CustomerBankAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBankAccount extends Model
{
    /** @use HasFactory<CustomerBankAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'bank_name', 'bank_country', 'branch_address', 'account_type', 'account_number',
        'account_holder_name', 'ownership', 'is_primary', 'is_active', 'primary_active_key', 'identity_hash',
    ];

    protected $hidden = ['account_number', 'identity_hash', 'primary_active_key'];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function maskedAccountNumber(): string
    {
        $number = preg_replace('/[^A-Za-z0-9]/', '', $this->account_number) ?? '';

        return str_repeat('*', max(4, strlen($number) - 4)).substr($number, -4);
    }

    public static function identityHash(string $bankName, string $country, string $accountNumber): string
    {
        $identity = implode('|', [
            mb_strtolower(preg_replace('/\s+/', ' ', trim($bankName)) ?? ''),
            mb_strtolower(preg_replace('/\s+/', ' ', trim($country)) ?? ''),
            strtoupper(preg_replace('/[\s-]+/', '', $accountNumber) ?? ''),
        ]);

        return hash_hmac('sha256', $identity, config('app.key'));
    }
}
