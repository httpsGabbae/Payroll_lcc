<?php
// Financial rules for the LCC edition. Money is converted to integer centavos before arithmetic.
function lcc_cents($value): int {
    $value = trim((string)$value);
    if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/', $value, $m)) {
        throw new InvalidArgumentException('Enter a non-negative amount with at most two decimal places.');
    }
    return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
}
function lcc_decimal(int $cents): string { return sprintf('%d.%02d', intdiv($cents,100), $cents % 100); }
function lcc_date(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}
function lcc_audit(mysqli $conn, string $event, string $entity, int $id, string $details = ''): void {
    $actor = (int)($_SESSION['admin_id'] ?? 0);
    $stmt = $conn->prepare('INSERT INTO financial_audit(actor_admin_id,event_type,entity_type,entity_id,details) VALUES(?,?,?,?,?)');
    $stmt->bind_param('issis', $actor, $event, $entity, $id, $details);
    if (!$stmt->execute()) throw new RuntimeException('Audit entry could not be written.');
}
function lcc_loan_balance(mysqli $conn, int $loanId, int $principalCents): int {
    $stmt = $conn->prepare('SELECT COALESCE(SUM(amount),0) paid FROM loan_repayments WHERE loan_id=?');
    $stmt->bind_param('i', $loanId); $stmt->execute();
    return $principalCents - lcc_cents($stmt->get_result()->fetch_assoc()['paid']);
}

function lcc_scheduled_deductions(mysqli $conn, int $employeeId, string $payDate): array {
    if ($employeeId < 1 || !lcc_date($payDate)) return [[], 0];
    $lines = []; $automatic = 0;
    $stmt = $conn->prepare('SELECT deduction_id,name,amount FROM recurring_deductions WHERE employee_id=? AND is_active=1 AND start_date<=? AND (end_date IS NULL OR end_date>=?) ORDER BY deduction_id FOR UPDATE');
    $stmt->bind_param('iss', $employeeId, $payDate, $payDate); $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $deduction) {
        $amount = lcc_cents($deduction['amount']);
        $automatic += $amount;
        $lines[] = ['Recurring', (int)$deduction['deduction_id'], $deduction['name'], $amount];
    }
    $stmt = $conn->prepare("SELECT l.loan_id,l.principal,l.installment,l.term_periods,t.name FROM employee_loans l JOIN loan_types t ON t.loan_type_id=l.loan_type_id WHERE l.employee_id=? AND l.status='Active' AND l.repayment_start<=? ORDER BY l.loan_id FOR UPDATE");
    $stmt->bind_param('is', $employeeId, $payDate); $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $loan) {
        $loanId = (int)$loan['loan_id'];
        $balance = lcc_loan_balance($conn, $loanId, lcc_cents($loan['principal']));
        $stmt = $conn->prepare("SELECT COALESCE(SUM(pl.amount),0) AS reserved, COUNT(*) AS installments FROM payroll_lines pl JOIN payroll_records p ON p.payroll_id=pl.payroll_id WHERE pl.source_type='Loan' AND pl.source_id=? AND p.status='Approved'");
        $stmt->bind_param('i', $loanId); $stmt->execute();
        $reserved = $stmt->get_result()->fetch_assoc();
        $balance -= lcc_cents($reserved['reserved']);
        $stmt = $conn->prepare('SELECT COUNT(*) AS installments FROM loan_repayments WHERE loan_id=?');
        $stmt->bind_param('i', $loanId); $stmt->execute();
        $paidCount = (int)$stmt->get_result()->fetch_assoc()['installments'];
        $used = $paidCount + (int)$reserved['installments'];
        if ($balance <= 0) continue;
        if ($loan['term_periods'] !== null && $used >= (int)$loan['term_periods']) {
            throw new DomainException($loan['name'] . ' has reached its scheduled number of deductions but still has a balance. Review its schedule.');
        }
        $amount = min($balance, lcc_cents($loan['installment']));
        $automatic += $amount;
        $lines[] = ['Loan', $loanId, $loan['name'] . ' loan', $amount];
    }
    return [$lines, $automatic];
}

/** The familiar single-employee payroll form, with scheduled deductions added automatically. */
function lcc_create_simple_payroll(
    mysqli $conn, int $employeeId, string $start, string $end, string $payDate,
    string $allowancesInput, string $otherInput, string $manualDeductionInput, string $notes
): int {
    if ($employeeId < 1 || !lcc_date($start) || !lcc_date($end) || !lcc_date($payDate) || $end < $start) {
        throw new InvalidArgumentException('Choose an employee and valid payroll dates.');
    }
    if ($payDate < $end) throw new InvalidArgumentException('Pay date must be on or after the period end date.');
    if (strlen($notes) > 1000) throw new InvalidArgumentException('Keep notes under 1,000 characters.');
    $allowances = lcc_cents($allowancesInput);
    $other = lcc_cents($otherInput);
    $manual = lcc_cents($manualDeductionInput);

    $locked = $conn->query("SELECT GET_LOCK('lcc_payroll_periods',10) AS acquired")->fetch_assoc();
    if ((int)($locked['acquired'] ?? 0) !== 1) throw new RuntimeException('Could not reserve the payroll period. Try again.');
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT employee_id,basic_salary,compensation_basis,employment_status FROM employees WHERE employee_id=? FOR UPDATE');
        $stmt->bind_param('i', $employeeId); $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        if (!$employee || !is_active_employment_status($employee['employment_status'])) {
            throw new DomainException('The selected employee is inactive or missing.');
        }
        if ($employee['compensation_basis'] !== null && $employee['compensation_basis'] !== 'per_period') {
            throw new DomainException('Set this employee’s base pay to Per payroll period before creating payroll.');
        }
        $basic = lcc_cents($employee['basic_salary']);
        if ($basic <= 0) throw new DomainException('Set a positive basic salary on the employee record first.');

        $stmt = $conn->prepare('SELECT payroll_id FROM payroll_records WHERE employee_id=? AND period_start=? AND period_end=? LIMIT 1');
        $stmt->bind_param('iss', $employeeId, $start, $end); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) throw new DomainException('A payroll record already exists for this employee and period.');

        $stmt = $conn->prepare('SELECT period_id,period_start,period_end,pay_date,status FROM payroll_periods WHERE period_start<=? AND period_end>=? FOR UPDATE');
        $stmt->bind_param('ss', $end, $start); $stmt->execute();
        $periods = $stmt->get_result(); $periodId = 0;
        while ($period = $periods->fetch_assoc()) {
            if ($period['period_start'] !== $start || $period['period_end'] !== $end) {
                throw new DomainException('These dates overlap another pay period. Use the same start and end dates for employees in that period.');
            }
            if ($period['status'] === 'Legacy' || $period['pay_date'] !== $payDate) {
                throw new DomainException('This pay period already exists with a different pay date or is historical.');
            }
            $periodId = (int)$period['period_id'];
        }
        if (!$periodId) {
            $label = date('M d', strtotime($start)) . ' – ' . date('M d, Y', strtotime($end));
            $actor = (int)$_SESSION['admin_id'];
            $stmt = $conn->prepare("INSERT INTO payroll_periods(label,period_start,period_end,pay_date,status,created_by) VALUES(?,?,?,?,'Draft',?)");
            $stmt->bind_param('ssssi', $label, $start, $end, $payDate, $actor);
            if (!$stmt->execute()) throw new RuntimeException('Could not save the pay period.');
            $periodId = $conn->insert_id;
        }

        [$lines, $automatic] = lcc_scheduled_deductions($conn, $employeeId, $payDate);

        $gross = $basic + $allowances + $other;
        $deductions = $manual + $automatic;
        if ($deductions > $gross) throw new DomainException('Deductions exceed gross pay. Reduce the entered deduction or change a loan schedule.');
        $net = $gross - $deductions;
        $basicSql = lcc_decimal($basic); $allowancesSql = lcc_decimal($allowances);
        $otherSql = lcc_decimal($other); $deductionsSql = lcc_decimal($deductions);
        $grossSql = lcc_decimal($gross); $netSql = lcc_decimal($net);
        $stmt = $conn->prepare("INSERT INTO payroll_records(employee_id,period_id,period_start,period_end,basic_salary,allowances,other_earnings,deductions,gross_pay,net_pay,status,notes,compensation_basis_snapshot) VALUES(?,?,?,?,?,?,?,?,?,?,'Draft',?,'manual')");
        $stmt->bind_param('iisssssssss', $employeeId, $periodId, $start, $end, $basicSql, $allowancesSql, $otherSql, $deductionsSql, $grossSql, $netSql, $notes);
        if (!$stmt->execute()) throw new RuntimeException('Could not save the payroll record.');
        $payrollId = $conn->insert_id;
        if ($manual > 0) $lines[] = ['Manual', 0, 'Other deductions', $manual];
        foreach ($lines as [$source, $sourceId, $label, $amount]) {
            $amountSql = lcc_decimal($amount);
            $stmt = $conn->prepare("INSERT INTO payroll_lines(payroll_id,kind,source_type,source_id,label,amount) VALUES(?,'Deduction',?,?,?,?)");
            $stmt->bind_param('isiss', $payrollId, $source, $sourceId, $label, $amountSql);
            if (!$stmt->execute()) throw new RuntimeException('Could not save the deduction breakdown.');
        }
        $stmt = $conn->prepare("UPDATE payroll_periods SET status='Draft' WHERE period_id=?");
        $stmt->bind_param('i', $periodId); $stmt->execute();
        lcc_audit($conn, 'payroll_created', 'payroll', $payrollId, 'Simple entry; net ' . $netSql);
        $conn->commit();
        return $payrollId;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    finally { $conn->query("SELECT RELEASE_LOCK('lcc_payroll_periods')"); }
}
function lcc_create_period(mysqli $conn, string $label, string $start, string $end, string $payDate): int {
    if (!lcc_date($start) || !lcc_date($end) || !lcc_date($payDate) || $start > $end) {
        throw new InvalidArgumentException('Enter valid start, end and pay dates.');
    }
    $label = trim($label);
    if ($label === '' || strlen($label) > 100) throw new InvalidArgumentException('Enter a period label of up to 100 characters.');
    $conn->begin_transaction();
    try {
        // A named lock serializes concurrent period creation, including an empty table.
        $locked = $conn->query("SELECT GET_LOCK('lcc_payroll_periods',10) AS acquired")->fetch_assoc();
        if ((int)$locked['acquired'] !== 1) throw new RuntimeException('Could not lock payroll periods.');
        $stmt = $conn->prepare('SELECT period_id FROM payroll_periods WHERE period_start<=? AND period_end>=? LIMIT 1');
        $stmt->bind_param('ss', $end, $start); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) throw new DomainException('This payroll period overlaps an existing period.');
        $actor = (int)$_SESSION['admin_id'];
        $stmt = $conn->prepare("INSERT INTO payroll_periods(label,period_start,period_end,pay_date,status,created_by) VALUES(?,?,?,?,'Draft',?)");
        $stmt->bind_param('ssssi', $label, $start, $end, $payDate, $actor);
        if (!$stmt->execute()) throw new RuntimeException('Could not create payroll period.');
        $id = $conn->insert_id;
        lcc_audit($conn, 'period_created', 'period', $id, "$start to $end; pay $payDate");
        $conn->commit();
        return $id;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    finally { $conn->query("SELECT RELEASE_LOCK('lcc_payroll_periods')"); }
}
function lcc_edit_period(mysqli $conn, int $periodId, string $label, string $start, string $end, string $payDate): void {
    if (!lcc_date($start) || !lcc_date($end) || !lcc_date($payDate) || $start > $end || trim($label)==='' || strlen($label)>100) {
        throw new InvalidArgumentException('Enter a valid label and dates.');
    }
    $conn->begin_transaction();
    try {
        $locked=$conn->query("SELECT GET_LOCK('lcc_payroll_periods',10) AS acquired")->fetch_assoc();
        if ((int)$locked['acquired']!==1) throw new RuntimeException('Could not lock payroll periods.');
        $stmt=$conn->prepare('SELECT status FROM payroll_periods WHERE period_id=? FOR UPDATE');
        $stmt->bind_param('i',$periodId); $stmt->execute(); $period=$stmt->get_result()->fetch_assoc();
        if (!$period || $period['status']!=='Draft') throw new DomainException('Only Draft periods can be edited.');
        $stmt=$conn->prepare('SELECT COUNT(*) AS total FROM payroll_records WHERE period_id=?');
        $stmt->bind_param('i',$periodId); $stmt->execute();
        if ((int)$stmt->get_result()->fetch_assoc()['total']>0) throw new DomainException('A period with payroll records cannot change its dates.');
        $stmt=$conn->prepare('SELECT period_id FROM payroll_periods WHERE period_id<>? AND period_start<=? AND period_end>=? LIMIT 1');
        $stmt->bind_param('iss',$periodId,$end,$start); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) throw new DomainException('This period overlaps an existing period.');
        $stmt=$conn->prepare('UPDATE payroll_periods SET label=?,period_start=?,period_end=?,pay_date=? WHERE period_id=?');
        $stmt->bind_param('ssssi',$label,$start,$end,$payDate,$periodId);
        if (!$stmt->execute()) throw new RuntimeException('Could not update period.');
        lcc_audit($conn,'period_updated','period',$periodId,"$start to $end; pay $payDate");
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    finally { $conn->query("SELECT RELEASE_LOCK('lcc_payroll_periods')"); }
}
function lcc_generate_employee(mysqli $conn, int $periodId, int $employeeId): int {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM payroll_periods WHERE period_id=? FOR UPDATE');
        $stmt->bind_param('i', $periodId); $stmt->execute(); $period = $stmt->get_result()->fetch_assoc();
        if (!$period || $period['status'] !== 'Draft' || !$period['pay_date']) throw new DomainException('Choose a Draft period with a pay date.');
        $stmt = $conn->prepare('SELECT employee_id,basic_salary,compensation_basis,employment_status FROM employees WHERE employee_id=? FOR UPDATE');
        $stmt->bind_param('i', $employeeId); $stmt->execute(); $employee = $stmt->get_result()->fetch_assoc();
        if (!$employee || !is_active_employment_status($employee['employment_status'])) throw new DomainException('Employee is inactive or missing.');
        if ($employee['compensation_basis'] !== 'per_period') throw new DomainException('Configure this employee with an explicit per-period compensation basis. Other bases require an approved conversion policy.');
        $basic = lcc_cents($employee['basic_salary']);
        if ($basic <= 0) throw new DomainException('A positive per-period base pay is required.');
        $stmt = $conn->prepare('SELECT payroll_id,status FROM payroll_records WHERE employee_id=? AND period_id=? FOR UPDATE');
        $stmt->bind_param('ii', $employeeId, $periodId); $stmt->execute(); $existing = $stmt->get_result()->fetch_assoc();
        if ($existing && $existing['status'] !== 'Draft') throw new DomainException('Approved and Paid payroll records are locked.');
        $lines = []; $income = 0; $deductions = 0;
        $stmt = $conn->prepare("SELECT a.adjustment_id,a.amount,t.kind,t.name FROM payroll_adjustments a JOIN adjustment_types t ON t.type_id=a.type_id WHERE a.employee_id=? AND a.period_id=? AND a.status='Active' ORDER BY a.adjustment_id FOR UPDATE");
        $stmt->bind_param('ii', $employeeId, $periodId); $stmt->execute(); $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $amount = lcc_cents($row['amount']);
            if ($row['kind'] === 'Income') $income += $amount; else $deductions += $amount;
            $lines[] = [$row['kind'], 'Adjustment', (int)$row['adjustment_id'], $row['name'], $amount];
        }
        $stmt = $conn->prepare('SELECT deduction_id,name,amount FROM recurring_deductions WHERE employee_id=? AND is_active=1 AND start_date<=? AND (end_date IS NULL OR end_date>=?) ORDER BY deduction_id FOR UPDATE');
        $stmt->bind_param('iss', $employeeId, $period['pay_date'], $period['pay_date']); $stmt->execute();
        $recurringRows = $stmt->get_result();
        while ($row = $recurringRows->fetch_assoc()) {
            $amount = lcc_cents($row['amount']);
            $deductions += $amount;
            $lines[] = ['Deduction', 'Recurring', (int)$row['deduction_id'], $row['name'], $amount];
        }
        $stmt = $conn->prepare("SELECT l.loan_id,l.principal,l.installment,l.term_periods,t.name FROM employee_loans l JOIN loan_types t ON t.loan_type_id=l.loan_type_id WHERE l.employee_id=? AND l.status='Active' AND l.repayment_start<=? ORDER BY l.loan_id FOR UPDATE");
        $stmt->bind_param('is', $employeeId, $period['pay_date']); $stmt->execute(); $loans = $stmt->get_result();
        while ($loan = $loans->fetch_assoc()) {
            $balance = lcc_loan_balance($conn, (int)$loan['loan_id'], lcc_cents($loan['principal']));
            $reserved = $conn->prepare("SELECT COALESCE(SUM(l.amount),0) reserved,COUNT(*) installments FROM payroll_lines l JOIN payroll_records p ON p.payroll_id=l.payroll_id WHERE l.source_type='Loan' AND l.source_id=? AND p.status='Approved'");
            $reserved->bind_param('i', $loan['loan_id']); $reserved->execute();
            $reservedRow = $reserved->get_result()->fetch_assoc();
            $balance -= lcc_cents($reservedRow['reserved']);
            $countStmt = $conn->prepare('SELECT COUNT(*) AS installments FROM loan_repayments WHERE loan_id=?');
            $countStmt->bind_param('i', $loan['loan_id']); $countStmt->execute();
            $used = (int)$countStmt->get_result()->fetch_assoc()['installments'] + (int)$reservedRow['installments'];
            if ($balance > 0 && $loan['term_periods'] !== null && $used >= (int)$loan['term_periods']) {
                throw new DomainException($loan['name'] . ' has reached its scheduled number of deductions but still has a balance.');
            }
            $installment = min($balance, lcc_cents($loan['installment']));
            if ($installment > 0) { $deductions += $installment; $lines[] = ['Deduction','Loan',(int)$loan['loan_id'],$loan['name'] . ' loan',$installment]; }
        }
        $gross = $basic + $income;
        if ($deductions > $gross) throw new DomainException('Deductions exceed gross pay. Review loan installments and adjustments.');
        $net = $gross - $deductions;
        $basicSql = lcc_decimal($basic); $incomeSql = lcc_decimal($income); $deductionsSql = lcc_decimal($deductions);
        $grossSql = lcc_decimal($gross); $netSql = lcc_decimal($net);
        if ($existing) {
            $id = (int)$existing['payroll_id'];
            $stmt = $conn->prepare('DELETE FROM payroll_lines WHERE payroll_id=?'); $stmt->bind_param('i',$id);
            if (!$stmt->execute()) throw new RuntimeException('Could not replace Draft breakdown.');
            $stmt = $conn->prepare("UPDATE payroll_records SET basic_salary=?,allowances=0,other_earnings=?,deductions=?,gross_pay=?,net_pay=?,compensation_basis_snapshot='per_period',needs_recalculation=0 WHERE payroll_id=? AND status='Draft'");
            $stmt->bind_param('sssssi',$basicSql,$incomeSql,$deductionsSql,$grossSql,$netSql,$id);
        } else {
            $stmt = $conn->prepare("INSERT INTO payroll_records(employee_id,period_id,period_start,period_end,basic_salary,allowances,other_earnings,deductions,gross_pay,net_pay,status,compensation_basis_snapshot) VALUES(?,?,?,?,?,0,?,?,?,?,'Draft','per_period')");
            $stmt->bind_param('iisssssss',$employeeId,$periodId,$period['period_start'],$period['period_end'],$basicSql,$incomeSql,$deductionsSql,$grossSql,$netSql);
        }
        if (!$stmt->execute()) throw new RuntimeException('Could not save Draft payroll.');
        if (!$existing) $id = $conn->insert_id;
        foreach ($lines as [$kind,$source,$sourceId,$label,$amount]) {
            $amountSql = lcc_decimal($amount);
            $stmt = $conn->prepare('INSERT INTO payroll_lines(payroll_id,kind,source_type,source_id,label,amount) VALUES(?,?,?,?,?,?)');
            $stmt->bind_param('ississ',$id,$kind,$source,$sourceId,$label,$amountSql);
            if (!$stmt->execute()) throw new RuntimeException('Could not save payroll breakdown.');
        }
        lcc_audit($conn, $existing ? 'payroll_recalculated' : 'payroll_created', 'payroll', $id, 'Net '.$netSql);
        $conn->commit(); return $id;
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
function lcc_transition_payroll(mysqli $conn, int $payrollId, string $target): void {
    if (!in_array($target, ['Approved','Paid'], true)) throw new InvalidArgumentException('Invalid payroll transition.');
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT p.*,pp.status AS period_status FROM payroll_records p JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE p.payroll_id=? FOR UPDATE');
        $stmt->bind_param('i',$payrollId); $stmt->execute(); $payroll=$stmt->get_result()->fetch_assoc();
        if (!$payroll) throw new DomainException('Only current LCC payroll records can change status.');
        if ($payroll['period_status']==='Legacy') throw new DomainException('Historical payroll is locked.');
        if ($target==='Approved' && (int)$payroll['needs_recalculation']===1) throw new DomainException('This Draft is stale. Regenerate it before approval.');
        $expected = $target === 'Approved' ? 'Draft' : 'Approved';
        if ($payroll['status'] !== $expected) throw new DomainException("Only $expected payroll can become $target.");
        if ($target === 'Approved') {
            // Drafts do not reserve a loan balance. Reserve it when the admin approves.
            $stmt = $conn->prepare("SELECT source_id,amount FROM payroll_lines WHERE payroll_id=? AND source_type='Loan' ORDER BY source_id");
            $stmt->bind_param('i',$payrollId); $stmt->execute();
            $loanLines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($loanLines as $line) {
                $loanId = (int)$line['source_id'];
                $stmt = $conn->prepare('SELECT principal,status,term_periods FROM employee_loans WHERE loan_id=? FOR UPDATE');
                $stmt->bind_param('i',$loanId); $stmt->execute(); $loan = $stmt->get_result()->fetch_assoc();
                if (!$loan || $loan['status'] !== 'Active') throw new DomainException('A linked loan is no longer active. Create a new Draft payroll.');
                $balance = lcc_loan_balance($conn,$loanId,lcc_cents($loan['principal']));
                $stmt = $conn->prepare("SELECT COALESCE(SUM(pl.amount),0) AS reserved,COUNT(*) AS installments FROM payroll_lines pl JOIN payroll_records p ON p.payroll_id=pl.payroll_id WHERE pl.source_type='Loan' AND pl.source_id=? AND p.status='Approved'");
                $stmt->bind_param('i',$loanId); $stmt->execute(); $reserved = $stmt->get_result()->fetch_assoc();
                $available = $balance - lcc_cents($reserved['reserved']);
                if (lcc_cents($line['amount']) > $available) throw new DomainException('The loan balance is lower than this Draft deduction. Create a new Draft payroll.');
                if ($loan['term_periods'] !== null) {
                    $stmt = $conn->prepare('SELECT COUNT(*) AS installments FROM loan_repayments WHERE loan_id=?');
                    $stmt->bind_param('i',$loanId); $stmt->execute();
                    $used = (int)$stmt->get_result()->fetch_assoc()['installments'] + (int)$reserved['installments'];
                    if ($used >= (int)$loan['term_periods']) throw new DomainException('The loan has reached its scheduled number of deductions. Create a new Draft payroll.');
                }
            }
        }
        if ($target === 'Paid') {
            $stmt = $conn->prepare("SELECT source_id,amount FROM payroll_lines WHERE payroll_id=? AND source_type='Loan' ORDER BY source_id");
            $stmt->bind_param('i',$payrollId); $stmt->execute(); $lines=$stmt->get_result();
            while ($line=$lines->fetch_assoc()) {
                $stmt=$conn->prepare('SELECT principal,status FROM employee_loans WHERE loan_id=? FOR UPDATE');
                $stmt->bind_param('i',$line['source_id']); $stmt->execute(); $loan=$stmt->get_result()->fetch_assoc();
                if (!$loan || $loan['status'] !== 'Active') throw new DomainException('A linked loan is no longer active.');
                $balance=lcc_loan_balance($conn,(int)$line['source_id'],lcc_cents($loan['principal']));
                if (lcc_cents($line['amount']) > $balance) throw new DomainException('Loan balance is lower than the approved deduction.');
                $actor=(int)$_SESSION['admin_id'];
                $stmt=$conn->prepare('INSERT INTO loan_repayments(loan_id,payroll_id,amount,posted_by) VALUES(?,?,?,?)');
                $stmt->bind_param('iisi',$line['source_id'],$payrollId,$line['amount'],$actor);
                if (!$stmt->execute()) throw new RuntimeException('Loan repayment could not be posted.');
                if ($balance === lcc_cents($line['amount'])) {
                    $stmt=$conn->prepare("UPDATE employee_loans SET status='Completed' WHERE loan_id=?");
                    $stmt->bind_param('i',$line['source_id']); $stmt->execute();
                }
                lcc_audit($conn,'loan_repayment_posted','loan',(int)$line['source_id'],'Payroll #'.$payrollId.'; amount '.$line['amount']);
            }
        }
        $actor=(int)$_SESSION['admin_id'];
        if ($target==='Approved') $stmt=$conn->prepare("UPDATE payroll_records SET status='Approved',approved_by=?,approved_at=NOW() WHERE payroll_id=? AND status='Draft'");
        else $stmt=$conn->prepare("UPDATE payroll_records SET status='Paid',paid_by=?,paid_at=NOW() WHERE payroll_id=? AND status='Approved'");
        $stmt->bind_param('ii',$actor,$payrollId);
        if (!$stmt->execute() || $stmt->affected_rows!==1) throw new RuntimeException('Payroll status could not be changed.');
        lcc_audit($conn,'payroll_'.$target,'payroll',$payrollId,$expected.' to '.$target);
        $periodId=(int)$payroll['period_id'];
        $stmt=$conn->prepare("SELECT COUNT(*) AS total,SUM(status='Draft') AS drafts,SUM(status<>'Paid') AS unpaid FROM payroll_records WHERE period_id=?");
        $stmt->bind_param('i',$periodId); $stmt->execute(); $counts=$stmt->get_result()->fetch_assoc();
        $periodStatus=(int)$counts['unpaid']===0 ? 'Paid' : ((int)$counts['drafts']===0 ? 'Approved' : 'Draft');
        $stmt=$conn->prepare('UPDATE payroll_periods SET status=? WHERE period_id=?');
        $stmt->bind_param('si',$periodStatus,$periodId); $stmt->execute();
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
