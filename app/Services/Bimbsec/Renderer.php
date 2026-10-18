<?php

namespace App\Services\Bimbsec;

use RuntimeException;
use setasign\Fpdi\Fpdi;

class Renderer
{
    public function __construct(private Catalog $catalog) {}

    /**
     * Names, identification numbers, addresses and bank account numbers
     * cannot be treated as complete when the form cuts them off.
     *
     * @param  array<string, mixed>  $field
     */
    public static function overflowBlocks(array $field): bool
    {
        $key = (string) ($field['source']['reference_key'] ?? '');
        $item = (string) ($field['printed_item'] ?? '');
        $criticalKeys = [
            'personal.legal_name', 'identification.current_nric_or_passport', 'identification.old_nric',
            'addresses.residential', 'addresses.mailing', 'relationships.spouse_or_next_of_kin',
            'employment.business_address', 'banking.references', 'tax.malaysian_tin', 'tax.foreign_residencies',
            'compliance.pep_people',
        ];
        if (! in_array($key, $criticalKeys, true)) {
            return false;
        }
        if ($key === 'banking.references') {
            return $item === 'account_number';
        }
        if ($key === 'tax.foreign_residencies') {
            return $item === 'tin';
        }
        if ($key === 'compliance.pep_people') {
            return in_array($item, ['name', 'nric'], true);
        }
        if ($key === 'relationships.spouse_or_next_of_kin') {
            return in_array($item, ['legal_name', 'nric_or_passport', 'employer_name_and_address', 'employer_city', 'employer_state', 'employer_postcode', 'employer_country'], true);
        }

        return true;
    }

    /**
     * @param  list<array{field: array<string, mixed>, kind: string, text: ?string, on: bool, choice: ?string, rows: list<string>}>  $draws
     * @return array{bytes: string, pages: int, warnings: list<array{level: string, label: string, message: string, field_id: ?string}>}
     */
    public function render(array $draws): array
    {
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 7);
        $count = $pdf->setSourceFile($this->catalog->templatePath());
        if ($count !== $this->catalog->pageCount()) {
            throw new RuntimeException('The BIMBSEC template page count does not match the mapping file.');
        }

        $byPage = [];
        foreach ($draws as $draw) {
            $byPage[(int) $draw['field']['page']][] = $draw;
        }
        $warnings = [];
        for ($page = 1; $page <= $count; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
            if ($page > 8) {
                continue;
            }
            foreach ($byPage[$page] ?? [] as $draw) {
                $warnings = array_merge($warnings, $this->draw($pdf, $draw));
            }
        }

        return ['bytes' => $pdf->Output('S'), 'pages' => $count, 'warnings' => $warnings];
    }

    /**
     * @param  array{field: array<string, mixed>, kind: string, text: ?string, on: bool, choice: ?string, rows: list<string>}  $draw
     * @return list<array{level: string, label: string, message: string, field_id: ?string}>
     */
    private function draw(Fpdi $pdf, array $draw): array
    {
        $field = $draw['field'];
        $placement = $field['placement'] ?? [];
        $size = (float) ($field['print_setting']['font_size_pt'] ?? 7);
        $pdf->SetFont('Helvetica', '', $size);
        $pdf->SetTextColor(0, 0, 0);

        return match ($draw['kind']) {
            'text' => $this->drawText($pdf, $field, $placement, (string) $draw['text'], $size),
            'cells' => $this->drawCells($pdf, $field, $placement, (string) $draw['text'], $size),
            'date' => $this->drawDate($pdf, $field, $placement, (string) $draw['text'], $size),
            'check' => $draw['on'] ? $this->drawMark($pdf, $placement['rect'] ?? [], $size) : [],
            'choice' => $this->drawChoice($pdf, $placement, (string) $draw['choice'], $size),
            'rows' => $this->drawRows($pdf, $field, $placement, $draw['rows'], $size),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $placement
     * @return list<array{level: string, label: string, message: string, field_id: ?string}>
     */
    private function drawText(Fpdi $pdf, array $field, array $placement, string $text, float $size): array
    {
        $rect = $placement['rect'] ?? null;
        if (! is_array($rect) || count($rect) !== 4 || $text === '') {
            return [];
        }
        $width = (float) $rect[2] - (float) $rect[0];
        $height = (float) $rect[3] - (float) $rect[1];
        $lineHeight = max($size + 1, 8);
        $lines = $this->wrap($pdf, $text, $width);
        $capacity = max(1, (int) floor($height / $lineHeight));
        $warnings = [];
        if (count($lines) > $capacity || $this->widest($pdf, $lines) > $width + 0.5) {
            $warnings[] = $this->overflow($field, count($this->chars($text)), $capacity * max(1, (int) floor($width / max($size * 0.5, 1))));
            $lines = array_slice($lines, 0, $capacity);
        }
        $y = (float) $rect[1];
        if (count($lines) === 1 && ($placement['vertical_align'] ?? '') === 'middle') {
            $y += max(0, ($height - $lineHeight) / 2);
        }
        foreach ($lines as $line) {
            $pdf->SetXY((float) $rect[0], $y);
            $pdf->Cell($width, $lineHeight, $line, 0, 0, 'L');
            $y += $lineHeight;
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $placement
     * @return list<array{level: string, label: string, message: string, field_id: ?string}>
     */
    private function drawCells(Fpdi $pdf, array $field, array $placement, string $text, float $size): array
    {
        $rows = $placement['rows'] ?? [];
        if (! is_array($rows) || $rows === [] || $text === '') {
            return [];
        }
        if (! empty($placement['uppercase']) || ($field['print_setting']['input_uppercase'] ?? false)) {
            $text = mb_strtoupper($text);
        }
        $chars = $this->chars($text);
        $capacity = 0;
        foreach ($rows as $row) {
            $capacity += (int) ($row['count'] ?? 0);
        }
        $warnings = [];
        if (count($chars) > $capacity) {
            $warnings[] = $this->overflow($field, count($chars), $capacity);
            $chars = array_slice($chars, 0, $capacity);
        }
        $offset = 0;
        foreach ($rows as $row) {
            $count = (int) ($row['count'] ?? 0);
            $step = (float) ($row['box_step_pt'] ?? 11);
            $boxHeight = (float) ($row['box_height_pt'] ?? 10);
            $x = (float) ($row['x_pt'] ?? 0);
            $y = (float) ($row['y_pt'] ?? 0);
            for ($i = 0; $i < $count && isset($chars[$offset]); $i++) {
                $pdf->SetXY($x + ($i * $step), $y);
                $pdf->Cell($step, $boxHeight, $chars[$offset], 0, 0, 'C');
                $offset++;
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $placement
     * @return list<array{level: string, label: string, message: string, field_id: ?string}>
     */
    private function drawDate(Fpdi $pdf, array $field, array $placement, string $digits, float $size): array
    {
        $centers = $placement['digit_cell_centers_pt'] ?? [];
        if (! is_array($centers) || $digits === '') {
            return [];
        }
        $chars = $this->chars($digits);
        $warnings = [];
        if (count($chars) > count($centers)) {
            $warnings[] = $this->overflow($field, count($chars), count($centers));
            $chars = array_slice($chars, 0, count($centers));
        }
        $height = (float) ($placement['box_height_pt'] ?? 10);
        $step = count($centers) > 1 ? abs((float) $centers[1] - (float) $centers[0]) : $height;
        $y = (float) ($placement['y_pt'] ?? 0);
        foreach ($chars as $index => $char) {
            $center = (float) $centers[$index];
            $pdf->SetXY($center - ($step / 2), $y);
            $pdf->Cell($step, $height, $char, 0, 0, 'C');
        }

        return $warnings;
    }

    /**
     * @param  list<float|int>  $rect
     * @return list<array{level: string, label: string, message: string, field_id: ?string}>
     */
    private function drawMark(Fpdi $pdf, array $rect, float $size): array
    {
        if (count($rect) !== 4) {
            return [];
        }
        $width = (float) $rect[2] - (float) $rect[0];
        $height = (float) $rect[3] - (float) $rect[1];
        $pdf->SetFont('Helvetica', '', min($size, max(6, $height)));
        $pdf->SetXY((float) $rect[0], (float) $rect[1]);
        $pdf->Cell($width, $height, 'X', 0, 0, 'C');

        return [];
    }

    /**
     * @param  array<string, mixed>  $placement
     * @return list<array{level: string, label: string, message: string, field_id: ?string}>
     */
    private function drawChoice(Fpdi $pdf, array $placement, string $choice, float $size): array
    {
        $choices = $placement['choices'] ?? [];
        if ($choice === '' || ! is_array($choices) || ! isset($choices[$choice]['rect'])) {
            return [];
        }

        return $this->drawMark($pdf, $choices[$choice]['rect'], $size);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $placement
     * @param  list<string>  $rows
     * @return list<array{level: string, label: string, message: string, field_id: ?string}>
     */
    private function drawRows(Fpdi $pdf, array $field, array $placement, array $rows, float $size): array
    {
        $rect = $placement['rect'] ?? null;
        if (! is_array($rect) || count($rect) !== 4 || $rows === []) {
            return [];
        }
        $heights = array_values($placement['row_heights_pt'] ?? []);
        $max = (int) ($placement['max_rows'] ?? count($heights));
        $warnings = [];
        if (count($rows) > $max) {
            $warnings[] = $this->overflow($field, count($rows), $max, 'records');
        }
        $width = (float) $rect[2] - (float) $rect[0];
        $y = (float) $rect[1];
        foreach (array_slice($rows, 0, $max) as $index => $row) {
            $height = (float) ($heights[$index] ?? 10);
            if ($pdf->GetStringWidth($row) > $width + 0.5) {
                $warnings[] = $this->overflow($field, mb_strlen($row), max(1, (int) floor($width / max($size * 0.45, 1))));

                continue;
            }
            $pdf->SetXY((float) $rect[0], $y);
            $pdf->Cell($width, $height, $row, 0, 0, 'L');
            $y += $height;
        }

        return $warnings;
    }

    /** @param  list<string>  $lines */
    private function widest(Fpdi $pdf, array $lines): float
    {
        $widest = 0.0;
        foreach ($lines as $line) {
            $widest = max($widest, $pdf->GetStringWidth($line));
        }

        return $widest;
    }

    /** @return list<string> */
    private function wrap(Fpdi $pdf, string $text, float $width): array
    {
        $lines = [];
        foreach (preg_split("/\R/u", $text) ?: [] as $paragraph) {
            $words = preg_split('/\s+/u', trim((string) $paragraph)) ?: [];
            $line = '';
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($pdf->GetStringWidth($candidate) <= $width || $line === '') {
                    $line = $candidate;

                    continue;
                }
                $lines[] = $line;
                $line = $word;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? [''] : $lines;
    }

    /** @return list<string> */
    private function chars(string $text): array
    {
        return mb_str_split($text);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{level: string, label: string, message: string, field_id: ?string}
     */
    private function overflow(array $field, int $actual, int $capacity, string $unit = 'characters'): array
    {
        $level = self::overflowBlocks($field) ? 'blocking' : 'attention';
        $label = $this->plainLabel($field);
        $dropped = max(0, $actual - $capacity);
        $left = $unit === 'records'
            ? $dropped.' '.($dropped === 1 ? 'entry was' : 'entries were').' left off.'
            : $dropped.' '.($dropped === 1 ? 'letter was' : 'letters were').' left off.';

        return [
            'level' => $level,
            'label' => $label,
            'field_id' => $field['id'] ?? null,
            'reference_key' => $field['source']['reference_key'] ?? null,
            'printed_item' => $field['printed_item'] ?? null,
            'message' => $label.' is too long for the form. '.$left,
        ];
    }

    /** @param  array<string, mixed>  $field */
    private function plainLabel(array $field): string
    {
        $group = trim((string) ($field['printed_group'] ?? ''));
        $item = trim((string) ($field['printed_item'] ?? ''));
        $part = match ($item) {
            '', 'address_lines', 'employer_name_and_address' => '',
            'legal_name' => 'name',
            'nric_or_passport' => 'NRIC or passport',
            'account_number' => 'account number',
            default => str_contains($item, '_') ? str_replace('_', ' ', $item) : $item,
        };
        if ($part === '' || strcasecmp($part, $group) === 0) {
            return $group !== '' ? $group : 'This field';
        }
        if ($group === '' || str_contains(strtolower($group), strtolower($part))) {
            return $part;
        }

        return $group.' '.$part;
    }
}
