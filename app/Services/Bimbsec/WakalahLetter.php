<?php

namespace App\Services\Bimbsec;

use App\Models\Customer;
use RuntimeException;
use setasign\Fpdi\Fpdi;

class WakalahLetter
{
    public function templatePath(): string
    {
        return storage_path('templates/Wakalah_Letter_V2.pdf');
    }

    public function render(Customer $customer): string
    {
        $path = $this->templatePath();
        if (! is_file($path)) {
            throw new RuntimeException('The Wakalah Letter template is missing.');
        }

        $customer->loadMissing(['residentialAddress', 'primaryIdentification']);
        $name = mb_strtoupper(trim((string) $customer->full_legal_name));
        $nric = mb_strtoupper(trim((string) ($customer->primaryIdentification?->number ?: $customer->primary_identification_number ?: '')));
        $address = $this->addressLines($customer);
        $date = now()->format('d/m/Y');
        $designation = mb_strtoupper(trim((string) ($customer->wakalah_designation ?? '')));
        $witnessName = mb_strtoupper(trim((string) ($customer->wakalah_witness_name ?? '')));
        $witnessNric = mb_strtoupper(trim((string) ($customer->wakalah_witness_nric ?? '')));
        $witnessDate = $customer->wakalah_witness_date?->format('d/m/Y') ?? '';

        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $count = $pdf->setSourceFile($path);
        for ($page = 1; $page <= $count; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
            $pdf->SetTextColor(0, 0, 0);
            if ($page === 1) {
                $this->writeTop($pdf, $name, $address);
            }
            if ($page === 2) {
                $this->writeAcceptance($pdf, $name, $nric, $designation, $date, $witnessName, $witnessNric, $witnessDate);
            }
        }

        return $pdf->Output('S');
    }

    /** @param  list<string>  $address */
    private function writeTop(Fpdi $pdf, string $name, array $address): void
    {
        // Cover docx placeholders so filled values are not layered over "[name of client]".
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(36.0, 190.0, 520.0, 55.0, 'F');

        $pdf->SetFont('Helvetica', '', 11);
        $this->writeField($pdf, 41.5, 192.8, 500.0, $name);
        $y = 207.7;
        foreach ($address as $line) {
            $this->writeField($pdf, 41.5, $y, 500.0, $line);
            $y += 13.0;
        }
    }

    private function writeAcceptance(
        Fpdi $pdf,
        string $name,
        string $nric,
        string $designation,
        string $date,
        string $witnessName,
        string $witnessNric,
        string $witnessDate,
    ): void {
        $pdf->SetFont('Helvetica', '', 11);
        // X matches each label's colon edge (FPDI adds a small cell inset).
        $this->writeField($pdf, 79.8, 204.3, 400.0, $name);
        $this->writeField($pdf, 79.8, 218.1, 400.0, $nric);
        $this->writeField($pdf, 118.0, 232.3, 360.0, $designation);
        $this->writeField($pdf, 79.8, 246.5, 200.0, $date);
        $this->writeField($pdf, 79.8, 347.3, 400.0, $witnessName);
        $this->writeField($pdf, 79.8, 361.1, 400.0, $witnessNric);
        $this->writeField($pdf, 76.5, 376.4, 200.0, $witnessDate);
    }

    private function writeField(Fpdi $pdf, float $x, float $y, float $width, string $text): void
    {
        if ($text === '') {
            return;
        }
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, 12.0, $text, 0, 0, 'L');
    }

    /** @return list<string> */
    private function addressLines(Customer $customer): array
    {
        $address = $customer->residentialAddress;
        if ($address === null) {
            return [];
        }
        // Three lines fit between the title and the appointment heading.
        $cityLine = trim(implode(', ', array_filter([
            trim(implode(' ', array_filter([
                trim((string) ($address->postcode ?? '')),
                trim((string) ($address->city ?? '')),
            ]))),
            trim((string) ($address->state ?? '')),
        ])));
        $lines = [];
        foreach ([
            trim((string) ($address->line_1 ?? '')),
            trim((string) ($address->line_2 ?? '')),
            $cityLine,
        ] as $line) {
            if ($line !== '') {
                $lines[] = mb_strtoupper($line);
            }
        }

        return $lines;
    }
}
