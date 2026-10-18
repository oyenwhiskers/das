<?php

namespace App\Services;

use App\Models\CustomerIdentification;

class ApplicationFormReference
{
    /** @var list<string> */
    private const GENERIC = [
        'FORM', 'TEMPLATE', 'VERSION', 'VER', 'REV', 'REVISION', 'DOCUMENT', 'DOC',
        'KYC', 'BIMB', 'DAS', 'CDS', 'BURSA', 'APPLICATION',
    ];

    /**
     * A quoted application serial, or null when the text is generic, a version,
     * an NRIC, an organization mark, or otherwise unsafe to group on.
     */
    public static function usable(?string $reference, ?string $nric, string $evidence): ?string
    {
        if (! is_string($reference)) {
            return null;
        }
        $trimmed = trim(preg_replace('/\s+/u', ' ', $reference) ?? '');
        $compact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $trimmed) ?? '');
        if ($trimmed === '' || mb_strlen($trimmed) > 40 || strlen($compact) < 6 || ! preg_match('/\d/', $compact) || ! preg_match('/[A-Z]/', $compact)) {
            return null;
        }
        $evidenceCompact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $evidence) ?? '');
        if ($evidenceCompact === '' || ! str_contains($evidenceCompact, $compact)) {
            return null;
        }
        $nricCompact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) CustomerIdentification::normalizeNumber($nric)) ?? '');
        if ($nricCompact !== '' && $compact === $nricCompact) {
            return null;
        }
        if (preg_match('/^\d{12}$/', $compact) === 1
            || preg_match('/^(?:VERSION|VER|REV|REVISION|V)\d+$/', $compact) === 1
            || preg_match('/^\d{4}$/', $compact) === 1) {
            return null;
        }
        $words = array_values(array_filter(explode(' ', trim(preg_replace('/\d+/', ' ', $compact) ?? ''))));
        if ($words === [] || array_diff($words, self::GENERIC) === []) {
            return null;
        }

        return $compact;
    }
}
