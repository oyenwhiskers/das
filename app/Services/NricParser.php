<?php

namespace App\Services;

use App\Models\CustomerIdentification;
use Carbon\Carbon;

class NricParser
{
    /**
     * @return array{date_of_birth: string, gender: string, nationality: string}|null
     */
    public function parse(?string $nric): ?array
    {
        $normalized = $this->normalize($nric);

        if ($normalized === null || ! preg_match('/^\d{12}$/', $normalized)) {
            return null;
        }

        $year = (int) substr($normalized, 0, 2);
        $month = (int) substr($normalized, 2, 2);
        $day = (int) substr($normalized, 4, 2);
        $fullYear = $this->resolveCentury($year);

        if (! checkdate($month, $day, $fullYear)) {
            return null;
        }

        $dateOfBirth = Carbon::createSafe($fullYear, $month, $day);

        if ($dateOfBirth === null || $dateOfBirth->isFuture()) {
            return null;
        }

        $maxAgeYears = 120;
        if ($dateOfBirth->lt(now()->subYears($maxAgeYears))) {
            return null;
        }

        $lastDigit = (int) substr($normalized, 11, 1);

        return [
            'date_of_birth' => $dateOfBirth->format('Y-m-d'),
            'gender' => $lastDigit % 2 === 1 ? 'Male' : 'Female',
            'nationality' => 'Malaysian',
        ];
    }

    public function normalize(?string $nric): ?string
    {
        return CustomerIdentification::normalizeNumber($nric);
    }

    protected function resolveCentury(int $twoDigitYear): int
    {
        $currentTwoDigitYear = (int) now()->format('y');

        return $twoDigitYear > $currentTwoDigitYear
            ? 1900 + $twoDigitYear
            : 2000 + $twoDigitYear;
    }
}
