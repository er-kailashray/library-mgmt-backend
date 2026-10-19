# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

LibraryPro backend: a Laravel 12 (PHP 8.2+) JSON API for owners of self-study libraries (reading rooms) to manage seats, time-slot batches, member admissions and payments. It is API-only; the frontend lives outside this directory (`D:\LibraryPro\backend` is one part of a larger project). Authentication uses Laravel Sanctum bearer tokens; SMS/WhatsApp OTPs go through Twilio.

## Commands

```bash
composer setup                 # first-time install: deps, .env, key, migrate, npm build
composer dev                   # serve + queue:listen + pail logs + vite, concurrently
php artisan serve              # API only
php artisan migrate            # local .env uses MySQL (.env.example defaults to sqlite)

composer test                  # config:clear + full test suite
php artisan test --filter=MemberApiTest                         # one test class
php artisan test --filter=test_can_list_members_with_payment_status  # one test method
php artisan test tests/Feature/MemberApiTest.php

vendor/bin/pint                # code style (Laravel Pint)
vendor/bin/pint --test         # check only
```

Tests run against in-memory SQLite with a sync queue and array mail/cache (see `phpunit.xml`), so they don't need MySQL or a queue worker. Feature tests use `RefreshDatabase`.

## Architecture

### Multi-tenancy by library
Every domain table carries a `library_id`. A `User` (library owner) `hasMany` `Library`, but controllers currently assume one library per owner and resolve it with `auth()->user()->libraries()->first()`, returning an error if none exists. Every query and every ID received in a request must be scoped to that library — e.g. `MemberController::admission` re-checks that the submitted `batch_id`/`seat_id` belong to the owner's library even though validation already checked `exists:`. Keep this pattern for new endpoints.

### Domain model
- `Library` → `Seat` (numbered 1..`total_seats`, bulk-created at registration), `Batch` (named time slot with `start_time`/`end_time`/`monthly_fee`; batches in a library may not overlap in time), `Member`, `Payment`.
- `SeatAllocation` is the core join: a member holds a seat **in a specific batch**, with `start_date`, `renewal_date` (start + 1 month) and `status` (`active` = occupied). The same seat can be held by different members in different batches. Availability = no `active` allocation for that (seat, batch) pair — this drives `/seat-matrix` and admission conflict checks.
- Payment status for a member is derived, not stored: "Due" if any active allocation's `renewal_date` is in the past.
- `MemberController::admission` re-uses an existing `Member` by phone (within the library) instead of creating a duplicate — covers both "add another batch to an existing student" and "a previously inactive member rejoins" (which also flips their `status` back to `active`).
- A member's lifecycle: `MemberController::deactivate` ("mark as left") sets `Member.status = inactive` and ends (`status = ended`) all their active `SeatAllocation`s in one transaction, freeing the seats immediately. `MemberController::index` only lists `active` members.
- `Attendance` (one row per member/batch/day, unique on that triple) is separate from `SeatAllocation` — allocation means "currently enrolled in this batch", attendance means "showed up on this specific day". `AttendanceService::roster()` builds the day's checklist from each batch's active allocations, left-joined against that day's `Attendance` rows (unmarked = no row yet); `mark()` is an upsert keyed on the same triple, so re-marking a day corrects it rather than duplicating.

### Auth flow
`POST /register` creates the user, their library and all seats inside a DB transaction, then stores a hashed 6-digit OTP in `otp_verifications` (5-minute expiry, typed `register`/`login`/`forgot_password`). SMS delivery is currently disabled: the OTP is only written to the log (`storage/logs/laravel.log`, "Registration OTP generated"). `POST /verify-otp` checks the latest unexpired, unverified OTP of that type; wrong guesses increment `attempts` and the OTP locks (429) at `OtpVerification::MAX_ATTEMPTS`. On success it sets `is_verified` (so it can't be reused) and for `register`/`login` issues a Sanctum token. `POST /login` is phone + password and issues a token directly. Phone numbers are 10-digit Indian mobiles (`/^[6-9]\d{9}$/`), stored without the `+91` prefix.

### Notifications (`app/Notifications`)
This directory is a custom messaging layer, **not** Laravel's built-in Notification classes. `NotificationService::send($channel, MessageDTO)` → `ProviderFactory::make($channel)` resolves a provider (`sms` → `TwilioSmsProvider`, `whatsapp` → `TwilioWhatsappProvider`) implementing `Contracts/*ProviderInterface`. Add new channels/providers by implementing the interface and adding a case to the factory. Twilio credentials come from `config('services.twilio.*')` (`TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_SMS_FROM`, `TWILIO_WHATSAPP_FROM`). Tests that hit registration should mock `NotificationService` to avoid real Twilio calls.

### Layering (target architecture)
The API contract is driven by the Expo app in `../mobile-app` (see its `API_CONTRACTS.md` and `src/features/*/types/`). Newer endpoints (dashboard, payments) follow the layering required by `../ai-rules/architecture.md` — thin controllers, business logic in services, data access in repositories; older controllers (members, batches, seats, auth) still query Eloquent inline and are candidates for migration:

- **`library` middleware** (`app/Http/Middleware/ResolveLibrary.php`) resolves the owner's library once and 404s if missing; controllers read it via `$this->library($request)` from the base `Controller`. Use it instead of `auth()->user()->libraries()->first()` in new code.
- **Repositories** (`app/Repositories/Contracts` interfaces → `Eloquent` implementations, bound in `DomainServiceProvider::$bindings`). Services depend on the interfaces only.
- **Services** (`app/Services`): `Dues\MemberDueService` is the single source of truth for overdue calculations (one batch fee owed per missed monthly cycle since `renewal_date`), registered as `scoped` so it computes once per request. `Payments\PaymentService` lists payments (synthesised PENDING rows from dues + recorded payments) and records payments: amount ≥ member's total monthly fee → `paid` and renewal dates move forward one month; otherwise `partial`.
- **Dashboard** (`GET /api/dashboard`): `DashboardService` merges the output of every `DashboardMetric` tagged `dashboard.metrics` in `DomainServiceProvider`. Add a widget by writing a new metric class and adding it to `DASHBOARD_METRICS` — don't grow existing metrics.
- Business thresholds (critical-due days, renewal window, recent-payments limit, payment-mode labels) live in `config/library.php`, not in code.
- Tests share fixtures through `tests/Concerns/BuildsLibrary.php` and pin time with `$this->travelTo(...)`.

### Conventions
- All routes are in `routes/api.php` (prefixed `/api`); everything except register/verify-otp/login sits behind `auth:sanctum`, and library-scoped endpoints additionally sit in the `library` middleware group.
- Validation: new endpoints use FormRequest classes (`app/Http/Requests`); older controllers validate inline with `$request->validate()`.
- Run `vendor/bin/pint` only on files you touch — much of the pre-existing code is not Pint-formatted and a repo-wide run produces unrelated churn.
- JSON responses use the envelope `{ "status": bool, "message"?: string, "data"?: ... }` (AuthController responses are the exception and omit `status`).
- `LibraryController` and `SeatAllocationController` are stubs.
