<?php

use setasign\Fpdi\Fpdi;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(14, 12, 14);

$line = function (string $label, string $value) use ($pdf): void {
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(68, 5, $label, 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 5, $value, 0, 1);
};

$blank = function (string $label) use ($pdf): void {
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(68, 5, $label, 0, 0);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 5, '____________________', 0, 1);
};

$header = function (int $page) use ($pdf): void {
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 7, 'Individual Client Application', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 5, 'Application No. APP-441908', 0, 1);
    $pdf->Cell(0, 5, 'Page '.$page.' of 4', 0, 1);
    $pdf->Ln(1);
    $pdf->Cell(0, 0, '', 'T', 1);
    $pdf->Ln(2);
};

$header(1);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Applicant', 0, 1);
$line('Salutation', 'Ms');
$line('Full legal name', 'Farah Nadia binti Zulkifli');
$line('Date of birth', '14 March 1991');
$line('Gender', 'Female');
$line('Nationality', 'Malaysian');
$line('Country of residence', 'Malaysia');
$line('Race', 'Malay');
$line('Marital status', 'Married');
$line('Identification type', 'NRIC');
$line('NRIC number', '910314-10-5528');
$blank('Old NRIC number');
$pdf->Ln(2);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Contact', 0, 1);
$line('Mobile telephone', '012-8834419');
$blank('Home telephone');
$line('Email address', 'farah.nadia@example.net');
$pdf->Ln(2);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Residential address', 0, 1);
$line('Address line 1', '18 Jalan Teratai 3');
$line('Address line 2', 'Taman Seri Maya');
$line('City', 'Petaling Jaya');
$line('State', 'Selangor');
$line('Postcode', '47301');
$line('Country', 'Malaysia');
$line('Occupancy', '[X] Owned    [ ] Rented    [ ] Other');
$pdf->Ln(2);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Mailing address', 0, 1);
$line('Same as residential address', '[X] Yes    [ ] No');
if ($pdf->GetY() > 280) {
    throw new RuntimeException('Page 1 overflow');
}

$header(2);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Employment', 0, 1);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, 'Employment status', 0, 1);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 4.5, '[X] Employed    [ ] Self-employed    [ ] Unemployed    [ ] Retired    [ ] Other', 0, 1);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, 'Arrangement', 0, 1);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 4.5, '[X] Employee    [ ] Self-employed    [ ] Contractor    [ ] Business owner    [ ] Other', 0, 1);
$line('Occupation', 'Accounts Executive');
$line('Employer name', 'Northwind Ledger Sdn Bhd');
$line('Registration number', '202204418833');
$line('Industry', 'Professional Services');
$line('Sub-industry', 'Accounting');
$line('Start date', '2 May 2019');
$line('Current employment', '[X] Yes    [ ] No');
$line('Primary employment', '[X] Yes    [ ] No');
$line('Employer telephone', '03-77214400');
$blank('Direct telephone');
$blank('Fax');
$pdf->Ln(2);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Employer address', 0, 1);
$line('Address line 1', 'Level 8, Menara Nadi');
$line('Address line 2', '22 Jalan Ampang');
$line('City', 'Kuala Lumpur');
$line('State', 'Kuala Lumpur');
$line('Postcode', '50450');
$line('Country', 'Malaysia');
if ($pdf->GetY() > 280) {
    throw new RuntimeException('Page 2 overflow');
}

$header(3);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Bank account', 0, 1);
$line('Bank name', 'Horizon Trust Bank');
$line('Bank country', 'Malaysia');
$line('Branch address', '4 Jalan Bukit Bintang, Kuala Lumpur');
$line('Account type', '[X] Savings    [ ] Current    [ ] Other');
$line('Account number', '8802143391');
$line('Account holder name', 'Farah Nadia binti Zulkifli');
$line('Ownership', '[X] Sole    [ ] Joint    [ ] Other');
$line('Primary account', '[X] Yes    [ ] No');
$line('Account active', '[X] Yes    [ ] No');
$pdf->Ln(2);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Financial declaration', 0, 1);
$line('Monthly income', 'RM 6400.00');
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, 'Personal annual income (tick one)', 0, 1);
$pdf->SetFont('Helvetica', '', 9);
foreach ([
    '[ ] Up to RM30,000',
    '[ ] RM30,001 - RM60,000',
    '[X] RM60,001 - RM120,000',
    '[ ] RM120,001 - RM180,000',
    '[ ] RM180,001 - RM300,000',
    '[ ] Over RM300,000',
] as $choice) {
    $pdf->Cell(0, 4.5, $choice, 0, 1);
}
$pdf->Ln(1);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, 'Sources of wealth (tick all that apply)', 0, 1);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 4.5, '[X] Salary    [ ] Savings    [ ] Business/Investment Income', 0, 1);
$pdf->Cell(0, 4.5, '[ ] Rental Income    [ ] Inherited Wealth    [ ] Other', 0, 1);
$line('Declaration date', '12 January 2026');
if ($pdf->GetY() > 280) {
    throw new RuntimeException('Page 3 overflow');
}

$header(4);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Related person', 0, 1);
$line('Relationship', '[X] Spouse');
$line('Next of kin', '[X] Yes    [ ] No');
$line('Primary next of kin', '[X] Yes    [ ] No');
$line('Full legal name', 'Hakim Iskandar bin Rahman');
$line('Identification type', 'NRIC');
$line('NRIC number', '880621-14-3317');
$line('Date of birth', '21 June 1988');
$line('Gender', 'Male');
$line('Nationality', 'Malaysian');
$line('Mobile telephone', '019-5527781');
$blank('Home telephone');
$blank('Email address');
$blank('Occupation');
$blank('Employer name');
$pdf->Ln(1);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Declarations', 0, 1);
$line('Accounts with other brokerages', '[ ] Yes    [X] No');
$line('Politically exposed person', '[ ] Yes    [X] No');
$line('Declared bankrupt', '[ ] Yes    [ ] No');
$line('Aware of a bankruptcy petition', '[ ] Yes    [ ] No');
$line('US person for tax purposes', '[ ] Yes    [X] No');
$line('US green card holder', '[ ] Yes    [ ] No');
$line('US resident', '[ ] Yes    [ ] No');
$line('FATCA classification', '[ ] US person    [ ] Non-US person');
$blank('Malaysian tax identification number');
$pdf->Ln(1);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, 'Joint annual income (tick one)', 0, 1);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 4.5, '[ ] Up to RM50,000    [ ] RM50,001 - RM100,000    [ ] RM100,001 - RM200,000', 0, 1);
$pdf->Cell(0, 4.5, '[ ] RM200,001 - RM300,000    [ ] RM300,001 - RM400,000    [ ] Over RM400,000', 0, 1);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, 'Net assets (tick one)', 0, 1);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 4.5, '[ ] Up to RM50,000    [ ] RM50,001 - RM200,000    [ ] RM200,001 - RM500,000', 0, 1);
$pdf->Cell(0, 4.5, '[ ] RM500,001 - RM1,000,000    [ ] RM1,000,001 - RM3,000,000    [ ] Over RM3,000,000', 0, 1);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, 'Portfolio value (tick one)', 0, 1);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 4.5, '[ ] Up to RM50,000    [ ] RM50,001 - RM100,000    [ ] RM100,001 - RM300,000', 0, 1);
$pdf->Cell(0, 4.5, '[ ] RM300,001 - RM500,000    [ ] RM500,001 - RM1,000,000    [ ] Over RM1,000,000', 0, 1);
if ($pdf->GetY() > 285) {
    throw new RuntimeException('Page 4 overflow at '.$pdf->GetY());
}

$out = dirname(__DIR__, 2).'/tests/Fixtures/round1/synthetic-application.pdf';
if (! is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
$pdf->Output('F', $out);

$keyPath = dirname($out).'/answer-key.json';
$key = json_decode((string) file_get_contents($keyPath), true, 512, JSON_THROW_ON_ERROR);
$key['sha256'] = hash_file('sha256', $out);
$key['pages'] = (new Fpdi)->setSourceFile($out);
file_put_contents($keyPath, json_encode($key, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo "pages={$key['pages']} sha256={$key['sha256']} bytes=".filesize($out).PHP_EOL;
