# LCC edition implementation notes

The root [README](../README.md) is the setup and operator guide. This file summarizes the main code paths for maintainers.

- `config/database.php`: isolated MySQL connection, LCC marker check, session namespace and helpers.
- `includes/auth.php`, `employee/auth.php`: admin roles and employee ownership.
- `includes/layout.php`, `employee/layout.php`: existing Paywise navigation and UI helpers.
- `includes/lcc_payroll.php`: period validation, centavo calculation, Draft snapshots, status transitions, loan posting and audit entries.
- `lcc_migration.sql`: one-time additive school database migration. The fresh-install sequence is `paywise_schema.sql`, `migrate_to_lcc.sql`, then `lcc_migration.sql`.
- `pages/payroll_periods.php`, `pages/compensation.php`, `pages/adjustments.php`, `pages/loan_types.php`, `pages/loans.php`, `pages/attendance_payroll.php`: LCC management modules.
- `pages/payroll.php`, `pages/reports.php`, `pages/payroll_preview.php`, `print/payroll.php`, `print/monthly_payroll.php`: current and legacy payroll display.
- `employee/loans.php`: read-only, employee-scoped loan ledger.
- `verify_lcc.php`, `smoke_lcc.php`: disposable database checks.

The authoritative monetary fields remain in `payroll_records`. `payroll_lines` stores the source-linked snapshot used for current itemization. `loan_repayments` is the posted ledger and has a unique `(loan_id, payroll_id)` key. Legacy periods have `pay_date=NULL` and are excluded from payment-month reports. `financial_audit` records actor, event, entity and context. Payroll and loan changes must use server authorization; hiding a control is never the permission boundary.

The implementation deliberately blocks automatic conversion of non-period compensation bases and monetary attendance effects until the college supplies verified policies. Add such policies to a versioned migration and a tested calculation function before enabling automated amounts.
