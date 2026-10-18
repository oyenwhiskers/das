<?php

namespace App\Services\Bimbsec;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class SigningDates
{
    public function apply(string $bytes): string
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
                $this->write($pdf, 122.0, $date);
                $this->write($pdf, 347.0, $date);
            }
        }

        return $pdf->Output('S');
    }

    private function write(Fpdi $pdf, float $x, string $date): void
    {
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x, 716.4);
        $pdf->Cell(80, 8, $date, 0, 0, 'L');
    }
}
