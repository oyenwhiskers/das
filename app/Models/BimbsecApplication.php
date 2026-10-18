<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class BimbsecApplication extends Model
{
    protected $fillable = [
        'customer_id', 'choices', 'overflow_attachment_approved', 'overflow_attachment_note', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
            'overflow_attachment_approved' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function document(): HasOne
    {
        return $this->hasOne(BimbsecDocument::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  array<string, mixed>  $input */
    public static function normalizeChoices(array $input): array
    {
        $account = $input['account_type'] ?? null;
        $verification = $input['verification_mode'] ?? null;
        $dealings = $input['dealings_capacity'] ?? null;
        $lth = $input['lth_group_employee'] ?? null;
        $online = $input['online_trading'] ?? null;
        $statement = $input['estatement'] ?? null;
        $payment = $input['payment'] ?? null;
        $relationship = $input['relationship_id'] ?? null;
        $bank = $input['bank_account_id'] ?? null;

        return [
            'account_type' => in_array($account, ['trading', 'margin'], true) ? $account : 'trading',
            'verification_mode' => in_array($verification, ['non_face', 'face'], true) ? $verification : 'non_face',
            'dealings_capacity' => in_array($dealings, ['principal', 'on_behalf'], true) ? $dealings : 'principal',
            'margin_credit_limit' => filled($input['margin_credit_limit'] ?? null) ? mb_substr(trim((string) $input['margin_credit_limit']), 0, 40) : null,
            'lth_group_employee' => in_array($lth, ['yes', 'no'], true) ? $lth : null,
            'online_trading' => in_array($online, ['yes', 'no'], true) ? $online : 'no',
            'estatement' => in_array($statement, ['email', 'bisonline'], true) ? $statement : 'email',
            'payment' => in_array($payment, ['cheque', 'epayment'], true) ? $payment : 'epayment',
            'relationship_id' => $relationship === 'none' ? 'none' : (is_numeric($relationship) ? (int) $relationship : null),
            'bank_account_id' => $bank === 'none' ? 'none' : (is_numeric($bank) ? (int) $bank : null),
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (BimbsecApplication $application): void {
            $path = $application->document?->storage_path;
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
        });
    }
}
