<?php

namespace App\Services\Bimbsec;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class SigningDates
{
    /**
     * @param  list<array{name?: string, nric?: string}>  $applicants  one entry per 14-page form in the PDF
     */
    public function apply(string $bytes, array $applicants = []): string
    {
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $count = $pdf->setSourceFile(StreamReader::createByString($bytes));
        $date = now()->format('d/m/Y');
        for ($page = 1; $page <= $count; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
            if (($page - 5) % 14 === 0) {
                $index = intdiv($page - 5, 14);
                $this->writeAuthorised($pdf, $applicants[$index] ?? [], $date);
            }
        }

        return $pdf->Output('S');
    }

    /** @param  array{name?: string, nric?: string}  $applicant */
    private function writeAuthorised(Fpdi $pdf, array $applicant, string $date): void
    {
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        $name = mb_strtoupper(trim((string) ($applicant['name'] ?? '')));
        $nric = mb_strtoupper(trim((string) ($applicant['nric'] ?? '')));
        // Authorised column only; witness Name / NRIC / Date stay blank for handwriting.
        $this->writeField($pdf, 122.0, 676.0, $name);
        $this->writeField($pdf, 122.0, 694.0, $nric);
        $this->writeField($pdf, 122.0, 716.4, $date);
    }

    private function writeField(Fpdi $pdf, float $x, float $y, string $text): void
    {
        if ($text === '') {
            return;
        }
        $pdf->SetXY($x, $y);
        $pdf->Cell(180.0, 8.0, $text, 0, 0, 'L');
    }
}
