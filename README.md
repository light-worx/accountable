# Church Accounts — starter (database + basic forms)

Foundation for MCSA society / circuit accounting on **Filament 5** (Laravel 11.28+, PHP 8.2+, Livewire 4, Tailwind 4).
This folder is an **overlay**: copy it over a fresh Laravel app.

> **Status: written but not executed.** It was produced in a sandbox without PHP, so nothing here has been run.
> Run the test suite first (step 8). Expect to fix a few small things — most likely Filament method names, since it was
> written from knowledge of the Filament 4/5 API (v5 has the same API as v4).

## Install

```bash
composer create-project laravel/laravel church-accounts && cd church-accounts
composer require filament/filament:"^5.0" spatie/laravel-activitylog
php artisan filament:install --panels        # accept defaults; our AdminPanelProvider replaces the generated one
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
```

1. Copy this folder's `app/`, `database/`, `resources/`, `tests/` over the project (overwrite `app/Models/User.php` and `app/Providers/Filament/AdminPanelProvider.php`).
2. In `.env` set `APP_TIMEZONE=Africa/Johannesburg` (the hash chain records local timestamps).
3. `php artisan migrate`
4. Schedule the nightly integrity check in `routes/console.php`:
   `Schedule::command('ledger:verify')->dailyAt('02:00');`
5. Demo data (never in production): `php artisan db:seed --class=DemoSeeder`
   Login: `treasurer@example.test` / `password`
6. `php artisan serve`
7. In production use **MySQL/MariaDB or PostgreSQL**, and give the app's DB user **no UPDATE/DELETE privilege** on
   `transactions`, `ledger_lines`, `period_events`, `transaction_attachments` (the migration also adds triggers as a second lock).
8. `php artisan test` — `tests/Feature/LedgerTest.php` checks numbering, balances, overdraft block, reversal, transfers,
   period locking, permissions, the database refusing edits/deletes, and tamper detection.

## How the money model works

Every ledger line moves money in or out of **one money account** (bank/cash/investment) **and one fund** by the same
signed amount, with an optional income/expense **category**. So total-in-accounts always equals total-in-funds
(shown on the dashboard). Transfers are two lines that sum to zero. No debits/credits, no depreciation, no balance sheet.

Posted entries are **append-only**: DB triggers block UPDATE/DELETE; Eloquent models throw on update/delete; corrections
are made with `PostingService::reverse()` (opposite entry, reason required). Each entry stores a SHA-256 hash covering its
content plus the previous entry's hash (`ledger:verify` re-checks the chain). Month-end close/reopen is itself an append-only history.

## Layout

| Path | Purpose |
|---|---|
| `database/migrations` | entities, setup tables, ledger tables + append-only triggers |
| `app/Services/Ledger/PostingService.php` | the only writer to the ledger (validation, numbering, hash chain, reversal) |
| `app/Services/Ledger/{PeriodService,LedgerVerifier,LedgerHasher}.php` | month locking, integrity check |
| `app/Filament/Pages/{MoneyIn,MoneyOut,MoveMoney}.php` | the three entry screens (shared logic in `EntryPage`) |
| `app/Filament/Resources/*` | Setup lists (accounts, funds, types, people) + read-only Cash book with Reverse action |
| `app/Filament/Widgets/MoneyBalances.php` | "where the money is" + books-agree check |
| `app/Support/DefaultSetup.php` | starter fund + plain-language categories per new entity |

## Deliberately not done yet

- **`return_line_code` is blank on all default categories.** It must be loaded from the *official* 4S/4C line list, not guessed.
- Multi-factor authentication (see Filament's "Multi-factor authentication" docs; enable for every user before go-live).
- UI to create entities/users and assign roles (use tinker / the seeder for now).
- Month-close screen (service + tests exist), bank import and reconciliation, budgets, debtors/creditors/loans/assessments registers.
- Reports, email statements, 4S/4C PDFs, property register.
- Pastoral-payment rule (clergy payee + PF number required for 4.2.x / 4.5.7 categories).
