<?php

require_once __DIR__ . '/../config/database.php';
if (isset($_SESSION['admin_id'])) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role('superadmin', 'finance');
} elseif (empty($_SESSION['employee_id'])) {
    http_response_code(403);
    exit('Access denied.');
} else {
    require_once __DIR__ . '/../employee/auth.php';
}
require_once __DIR__ . '/../includes/pdf.php';

if (!function_exists('employee_name')) {
    function employee_name(array $employee): string
    {
        return trim($employee['first_name'] . ' ' . ($employee['middle_name'] ?? '') . ' ' . $employee['last_name']);
    }
}

$isAdmin = !empty($_SESSION['admin_id']);
$sessionEmployeeId = (int)($_SESSION['employee_id'] ?? 0);
if (!$isAdmin && $sessionEmployeeId <= 0) {
    header('Location: ../login.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    exit('Payroll record not found.');
}

if ($isAdmin) {
    $stmt = $conn->prepare('SELECT p.*, e.employee_no, e.first_name, e.middle_name, e.last_name, e.department, e.position FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id WHERE p.payroll_id=? LIMIT 1');
    $stmt->bind_param('i', $id);
} else {
    $stmt = $conn->prepare("SELECT p.*, e.employee_no, e.first_name, e.middle_name, e.last_name, e.department, e.position FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id WHERE p.payroll_id=? AND p.employee_id=? AND p.status IN ('Approved','Paid') LIMIT 1");
    $stmt->bind_param('ii', $id, $sessionEmployeeId);
}
$stmt->execute();
$payroll = $stmt->get_result()->fetch_assoc();

if (!$payroll) {
    exit('Payroll record not found.');
}
$payDate = null;
if (!empty($payroll['period_id'])) {
    $periodStmt=$conn->prepare('SELECT pay_date FROM payroll_periods WHERE period_id=?');
    $periodStmt->bind_param('i',$payroll['period_id']);$periodStmt->execute();
    $payDate=$periodStmt->get_result()->fetch_assoc()['pay_date']??null;
}
$lineStmt=$conn->prepare('SELECT kind,label,amount FROM payroll_lines WHERE payroll_id=? ORDER BY line_id');
$lineStmt->bind_param('i',$id);$lineStmt->execute();$itemized=$lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);

class PayslipPDF extends FPDF
{
    public function Footer()
    {
        pdf_footer($this, 'Payroll Statement - Private and Confidential');
    }
}

$pdf = new PayslipPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(15, 12, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

pdf_header($pdf, 'PAYROLL STATEMENT', 'Employee payroll record');

$pdf->SetFillColor(6, 59, 70);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(180, 9, pdf_text('PAYSLIP'), 0, 1, 'C', true);
$pdf->SetTextColor(23, 50, 59);

$pdf->Ln(5);
pdf_field_row($pdf, 'Employee ID', $payroll['employee_no'], 'Status', $payroll['status']);
pdf_field_row($pdf, 'Employee Name', employee_name($payroll), 'Date Prepared', pdf_date($payroll['created_at']));
pdf_field_row($pdf, 'Department', $payroll['department'] ?: 'Not assigned', 'Position', $payroll['position'] ?: 'Not assigned');
pdf_field_row($pdf, 'Pay Period', pdf_date($payroll['period_start']) . ' - ' . pdf_date($payroll['period_end']), 'Pay Date', $payDate ? pdf_date($payDate) : 'Unknown (legacy)');

$pdf->Ln(7);

$pdf->SetFillColor(236, 247, 246);
$pdf->SetTextColor(8, 127, 123);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(90, 8, pdf_text('EARNINGS'), 1, 0, 'L', true);
$pdf->Cell(90, 8, pdf_text('DEDUCTIONS'), 1, 1, 'L', true);
$pdf->SetTextColor(23, 50, 59);

$startY = $pdf->GetY();
$leftRows = [['Base Pay', $payroll['basic_salary']]];
$rightRows = [];
if ((float)$payroll['allowances']>0) $leftRows[]=['Allowances', $payroll['allowances']];
if ((float)$payroll['other_earnings']>0 && (($payroll['compensation_basis_snapshot'] ?? '') === 'manual' || !array_filter($itemized, static fn($line) => $line['kind'] === 'Income'))) {
    $leftRows[]=['Other Earnings', $payroll['other_earnings']];
}
if ($itemized) {
    foreach ($itemized as $line) {
        if ($line['kind']==='Income') $leftRows[]=[$line['label'],$line['amount']];
        else $rightRows[]=[$line['label'],$line['amount']];
    }
} else {
    // Historical aggregate-only records have no invented line details.
    if ((float)$payroll['deductions']>0) $rightRows[]=['Deductions (legacy total)',$payroll['deductions']];
}

$maxRows = max(count($leftRows), count($rightRows));
for ($i = 0; $i < $maxRows; $i++) {
    $left = $leftRows[$i] ?? ['', null];
    $right = $rightRows[$i] ?? ['', null];

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetDrawColor(215, 225, 227);
    $pdf->Cell(62, 8, pdf_text($left[0]), 1, 0, 'L');
    $pdf->Cell(28, 8, $left[1] === null ? '' : pdf_money($left[1]), 1, 0, 'R');
    $pdf->Cell(62, 8, pdf_text($right[0]), 1, 0, 'L');
    $pdf->Cell(28, 8, $right[1] === null ? '' : pdf_money($right[1]), 1, 1, 'R');
}

$pdf->SetFillColor(247, 249, 249);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(62, 8, pdf_text('TOTAL EARNINGS'), 1, 0, 'L', true);
$pdf->Cell(28, 8, pdf_money($payroll['gross_pay']), 1, 0, 'R', true);
$pdf->Cell(62, 8, pdf_text('TOTAL DEDUCTIONS'), 1, 0, 'L', true);
$pdf->Cell(28, 8, pdf_money($payroll['deductions']), 1, 1, 'R', true);

$pdf->Ln(9);
$pdf->SetFillColor(6, 59, 70);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(120, 14, pdf_text('NET PAY'), 1, 0, 'L', true);
$pdf->Cell(60, 14, pdf_money($payroll['net_pay']), 1, 1, 'R', true);
$pdf->SetTextColor(23, 50, 59);

if (!empty($payroll['notes'])) {
    $pdf->Ln(8);
    pdf_compact_section($pdf, 'Notes');
    pdf_compact_multiline($pdf, 'Payroll Notes', $payroll['notes']);
}

$pdf->Ln(22);
$pdf->SetFont('Arial', '', 8);
$pdf->SetDrawColor(160, 170, 173);
$pdf->Cell(82, 6, pdf_text('Prepared by: __________________________'), 0, 0, 'L');
$pdf->Cell(16, 6, '', 0, 0);
$pdf->Cell(82, 6, pdf_text('Received by: __________________________'), 0, 1, 'L');
$pdf->Ln(7);
$pdf->SetFont('Arial', '', 7.5);
$pdf->SetTextColor(110, 123, 128);
$pdf->Cell(0, 5, pdf_text('This document is an internal payroll record. Please keep it confidential.'), 0, 1, 'C');

$pdf->Output('I', 'payslip-' . $payroll['employee_no'] . '.pdf');
