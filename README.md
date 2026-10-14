# Lipa City Colleges Payroll System

This is an independent, school-specific edition based on the original Paywise Payroll System. It is for academic demonstration and evaluation. Lipa City Colleges has not been represented as having approved, adopted, or deployed it. The original Paywise checkout at `D:\Xampp\htdocs\new_payroll` is separate and remains unchanged.

The application is plain PHP 8.2, MariaDB/MySQL, server-rendered HTML, the existing Paywise CSS and JavaScript, and FPDF under `vendor/`. It keeps the existing admin dashboard, employee portal, responsive navigation, dark mode, and print conventions.

## Location and isolation

- School application: `D:\Xampp\htdocs\paywise-lcc`
- School database: `paywise_lcc_edition`. The suggested `lcc_payroll` name was already in use and was not changed.
- School URL: `http://localhost/paywise-lcc/`
- School cookie: `LCC_PAYROLL_SESSID`, scoped to `/paywise-lcc/`; session files use a distinct `lcc-payroll-sessions` temporary directory.
- Employee uploads resolve under this application's own `uploads/` directory.
- `LCC_DB_NAME` is intentionally independent of `DB_NAME`; the application refuses the known original database names and checks an LCC edition marker before serving data.

**Privacy item before deployment:** the Git clone included three tracked employee photo files in `uploads/employee_photos/`. An attempted removal was rejected by the execution approval system, so they remain in the copy. That directory has a temporary Apache deny rule, which also prevents newly uploaded photos from displaying. Remove and review the copied files with authorized access, then remove that deny rule when the directory contains only approved school-edition uploads. No live employee or payroll database was copied.

## Fresh XAMPP setup

The database on this machine has already been initialized. On another host:

1. Verify that `paywise_lcc_edition` does not exist and that you have a backup of any existing payroll databases. Never import these files into the original Paywise database.
2. Obtain the SQL setup files from the private local deployment copy. They are excluded from Git and blocked from HTTP access. Import `paywise_schema.sql`. It creates `paywise_lcc_edition` and fails if that database already exists. Alternatively, create a **new empty** LCC database with your chosen name and import `schema.sql` into it.
3. In the school database, import `migrate_to_lcc.sql` to seed the college departments and positions, then import `lcc_migration.sql` once. Do not run `migrate_to_lcc.sql` on an existing populated school database without reviewing its position replacement and employee department remapping.
4. Set `LCC_DB_HOST`, `LCC_DB_USER`, `LCC_DB_PASS`, and `LCC_DB_NAME` in the Apache/PHP environment if the defaults are unsuitable. `.env.example` documents the names but is not automatically loaded. The default MySQL host/user/password are `localhost` / `root` / empty, as in local XAMPP.
5. Run `D:\Xampp\php\php.exe D:\Xampp\htdocs\paywise-lcc\setup_admin.php` in a local terminal. It creates the first independent superadmin and prompts for a password of at least 12 characters. Password input is visible in that terminal, so use a private local session. This one-time script refuses to run over HTTP and refuses a second administrator.
6. Open `http://localhost/paywise-lcc/login.php`. Employees use `http://localhost/paywise-lcc/employee/login.php` after HR assigns a portal password.

No default administrator or employee credentials are shipped in the LCC fresh-install schema.

## Upgrade of an existing LCC edition database

Back up the **school database only**, for example with `D:\Xampp\mysql\bin\mysqldump.exe -uroot --single-transaction --routines paywise_lcc_edition > lcc_backup.sql` from a trusted local terminal. Verify the backup file and keep a second copy. Check for duplicate `(employee_id, period_start, period_end)` payroll rows and reconcile them where possible. Run `lcc_migration.sql` once against the school database. It adds the period, loan, adjustment, schedule and audit tables; archives employees instead of deleting them; and links one historical payroll row per employee/date pair to a matching legacy period. Duplicate legacy rows remain untouched and unlinked for manual review. It does not change historical financial totals or invent historical pay dates. MariaDB DDL commits independently; restore the verified backup to a newly created school database if migration fails. Never rerun a partly applied migration.

`schema.sql` and `paywise_schema.sql` are **fresh install files**, not upgrade scripts. `migrate_to_lcc.sql` is also intended only for fresh installation in this edition unless its effects on existing school data have been reviewed.

## Workflow and calculation rules

1. HR manages employees, departments, positions, daily attendance, work schedules, attendance correction reasons, and overtime minute approvals. Finance and superadmins can view attendance summaries. Minutes alone do not create money movements.
2. Finance sets an explicit compensation basis and base amount in **Compensation**. Automatic generation currently accepts `per_period` only. Monthly, semimonthly, daily and hourly bases are stored but blocked until approved conversion policies are implemented.
3. Finance creates a payroll period with start, end and actual pay date. Overlaps are rejected. Income and deduction types, itemized entries, loan types, and employee loans can then be entered. Adjustments must be complete before that employee's Draft is generated.
4. Finance generates one or all configured employees. Draft generation recalculates the same record without posting loan repayments. It snapshots base pay, itemized adjustments and proposed loan installments. A changed compensation amount or attendance record marks an affected Draft stale; a changed loan status marks relevant Drafts stale. Regenerate before approval.
5. Allowed status flow is **Draft → Approved → Paid**. Approved and Paid records are locked from ordinary recalculation. Paid posts each linked loan installment exactly once in a transaction. Every financial status change and repayment is audited.

Amounts are calculated in integer centavos. For the supported `per_period` basis:

`gross = base pay + active itemized income`

`deductions = active itemized deductions + proposed active-loan installments`

`net = gross − deductions`

The loan installment is capped at the unposted balance after accounting for approved reservations. A payroll whose deductions exceed gross pay is rejected. Loan balance is principal less posted repayments; no interest, penalty, statutory contribution, tax rate, or wage conversion is inferred.

Attendance review calculates late minutes from expected in time plus grace, undertime from expected out time, and recorded overtime from actual out time. Overtime requires explicit approval. No lateness, leave, absence, holiday, overtime, or statutory amount is automatically turned into pay without an approved school policy and authorized adjustment. Overnight shifts and break policies need additional configuration before automated calculations.

## Reporting and printing

Monthly reports use **actual pay date**. Historical records with unknown pay dates are retained in Payroll Records and omitted from payment-month totals; they are not assigned a fabricated date. Report totals include all displayed statuses, so Draft and Approved amounts must not be treated as disbursed. Payslips and browser print previews use the payroll snapshot and show itemized lines where they exist. Legacy aggregate-only rows remain aggregate-only. The employee portal shows only the signed-in employee's payroll and loan history.

## Roles and security

- Superadmin: account management, HR and finance workflows.
- Finance: compensation, periods, adjustments, loans, payroll approval/payment, financial reports, and read-only attendance summaries.
- HR: employee records, departments/positions, attendance and schedules, and masked payroll attendance view. Financial PDF endpoints are denied.
- Employee: own profile, attendance, payroll and loan history.

All state-changing application forms use CSRF tokens. Financial writes use parameterized SQL and transactions. The admin employee preview is a dedicated read-only route and never creates an employee session. Employee archiving preserves payroll history; only a superadmin can restore an archived employee. The database foreign key restricts deletion of employees with payroll records.

## Verification

`verify_lcc.php` requires a disposable database name containing `_test_` and checks overlap rejection, the ₱15,000 + ₱500 − ₱200 − ₱1,000 = ₱14,300 example, Draft regeneration, status locking, single loan posting, balance reconciliation and negative amount rejection. `smoke_lcc.php` renders administrator and employee pages against a disposable database. PHP syntax checks, fresh MariaDB imports, the financial integration test, 20 page renders, administrator and employee payslip PDF rendering, and login-page browser inspection were run during this build. Full browser interaction, dark-mode visual regression, institutional policy validation, penetration testing, and backup restoration have not been completed.

Actual college adoption requires formal authorization, confirmed compensation and statutory rules, privacy review, backup and recovery drills, security testing, user acceptance testing, and authorized migration of legitimate data.

## Attribution

Based on the original Paywise Payroll System owned by the project developer. Third-party license notices under `vendor/` remain in place. The existing Paywise design system was reused for the LCC edition.
