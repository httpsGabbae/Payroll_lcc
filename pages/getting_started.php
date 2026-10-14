<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$canPeople = in_array(admin_role(), ['superadmin', 'hr'], true);
$canFinance = in_array(admin_role(), ['superadmin', 'finance'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" href="../assets/favicon.png">
    <title>How to use this · LCC Payroll System</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20261012-nav1">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261012-ux1">
    <script src="../assets/js/app.js?v=20261012-nav1" defer></script>
</head>
<body class="lcc-ux">
<div class="app">
    <?php sidebar('guide'); ?>
    <main class="main">
        <?php topbar('How to use this'); ?>
        <div class="content">
            <div class="page-heading">
                <div>
                    <div class="eyebrow">START HERE</div>
                    <h1>How to use the LCC payroll system</h1>
                    <p>Follow these steps in order. Open a step to go directly to that page.</p>
                </div>
            </div>

            <?php if ($canPeople): ?>
            <section class="card lcc-guide-section" aria-labelledby="people-steps">
                <div class="card-head"><div><h2 id="people-steps">People and attendance</h2><p>For HR and superadmins.</p></div></div>
                <div class="lcc-guide-grid">
                    <a class="lcc-guide-step" href="employees.php"><span class="lcc-step-number">1</span><span><strong>Add or check employees</strong><small>Keep names, departments, positions, and employment status up to date.</small><em>Open Employees →</em></span></a>
                    <a class="lcc-guide-step" href="attendance.php"><span class="lcc-step-number">2</span><span><strong>Record daily attendance</strong><small>Check time in and time out. Give a reason when correcting a record.</small><em>Open Attendance →</em></span></a>
                    <a class="lcc-guide-step" href="attendance_payroll.php"><span class="lcc-step-number">3</span><span><strong>Review hours</strong><small>Check late, undertime, and overtime minutes before Finance prepares payroll.</small><em>Open Review hours →</em></span></a>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($canFinance): ?>
            <section class="card lcc-guide-section" aria-labelledby="payroll-steps">
                <div class="card-head"><div><h2 id="payroll-steps">Prepare and pay employees</h2><p>For Finance and superadmins. Payroll is entered on one form.</p></div></div>
                <div class="lcc-guide-grid">
                    <a class="lcc-guide-step" href="compensation.php"><span class="lcc-step-number">1</span><span><strong>Set base pay</strong><small>Choose the employee and enter the amount for one payroll period.</small><em>Open Set base pay →</em></span></a>
                    <div class="lcc-guide-step"><span class="lcc-step-number">2</span><span><strong>Set up automatic deductions</strong><small>Enter <a href="recurring_deductions.php">SSS, Pag-IBIG, PhilHealth and other recurring amounts</a>. Register <a href="loans.php">loans</a> with an amount and number of payroll deductions. Add <a href="loan_types.php">loan types</a> as needed.</small></span></div>
                    <a class="lcc-guide-step" href="payroll.php?add=1#new-payroll"><span class="lcc-step-number">3</span><span><strong>Add New Payroll</strong><small>Choose one employee, enter the period dates, pay date, and any extra earnings or deductions. Scheduled amounts are added automatically.</small><em>Open Payroll form →</em></span></a>
                    <a class="lcc-guide-step" href="payroll.php"><span class="lcc-step-number">4</span><span><strong>Check, approve, and record payment</strong><small>Review the Draft and payslip. Approve it, then record payment after the employee has actually been paid.</small><em>Open Payroll →</em></span></a>
                    <a class="lcc-guide-step" href="reports.php"><span class="lcc-step-number">5</span><span><strong>Print and review reports</strong><small>See payroll records and monthly totals.</small><em>Open Reports →</em></span></a>
                </div>
            </section>
            <?php endif; ?>

            <section class="card lcc-guide-section" aria-labelledby="word-guide">
                <div class="card-head"><div><h2 id="word-guide">What the words mean</h2></div></div>
                <dl class="lcc-glossary">
                    <div><dt>Pay period</dt><dd>The days of work covered by one payroll run.</dd></div>
                    <div><dt>Pay date</dt><dd>The day employees are actually due to receive payment.</dd></div>
                    <div><dt>Draft</dt><dd>Amounts have been saved for review before approval.</dd></div>
                    <div><dt>Approved</dt><dd>The amounts are checked and locked. Payment has not been recorded yet.</dd></div>
                    <div><dt>Paid</dt><dd>Payment has been recorded. Any loan repayment is posted to the loan balance.</dd></div>
                </dl>
            </section>
        </div>
    </main>
</div>
</body>
</html>
