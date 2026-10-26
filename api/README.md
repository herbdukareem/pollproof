# PollProof API

The backend for PollProof: agent sign-in, geofenced check-in, signed polling-unit results, collation and IReV checks, live tallies with the 25% rule, stipend eligibility and a tamper-evident audit log.

Built with Laravel 13, PostgreSQL 16 and Sanctum tokens.

## Run it locally

Requirements: PHP 8.3+ with `pdo_pgsql` and `sodium`, Composer, PostgreSQL 16.

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
# create the databases (adjust to your setup)
createuser -P pollproof          # password: secret, or update .env
createdb -O pollproof pollproof
createdb -O pollproof pollproof_test
php artisan migrate --seed       # demo state: 2 LGAs, 4 wards, 12 units, 4 parties
php artisan serve
php artisan test
```

Demo users (password `password`): `dg@demo.test`, `situation_room@demo.test`, `verifier@demo.test`, `data_officer@demo.test`, `legal@demo.test`, `finance@demo.test`.
Field users sign in by phone: agents `08000001001`–`08000001012`, ward supervisor `08000000100`, collation agent `08000000200`. With `POLLPROOF_SMS_DRIVER=log` the one-time code is written to `storage/logs/laravel.log`.

## How it fits together

```
Agent app ──► check-in (GPS + selfie) ──► signed result (Ed25519) ─┐
SMS/USSD  ──► /sms/inbound ───────────────────────────────────────┤
                                                                   ▼
Data officers / collation agents ──► IReV + EC8B/C/D ──► Reconciler ──► Alerts
                                                                   │
                                     Tally (25% rule) ◄────────────┤
                                     Stipends         ◄────────────┤
                                     Evidence bundle  ◄────────────┘
Every write ──► hash-chained audit log
```

- **Campaign isolation.** Every row carries `campaign_id`, and every query is scoped to the signed-in user's campaign.
- **Results are never edited.** A correction is a new record that references the one it replaces (`supersedes_id`); only the newest is `is_current`. The app copy of a result first sent by SMS links to it automatically.
- **Reconciliation runs on every write.** A new unit result re-checks the ward, LGA and state sheets that cover it and the unit's IReV entry. Alerts are de-duplicated per problem, clear themselves when the problem goes away, and stay resolved if someone resolved them and nothing has changed.
- **Two people per result.** A verifier who did not submit the result approves it or sends it back with a note. Sent-back results drop out of tallies.

## Result signing (contract for the mobile app)

On first launch the phone generates an Ed25519 key pair and registers the public key with `POST /api/v1/devices`. Each result is signed over this canonical JSON:

```json
{"accredited":448,"captured_at":"2027-02-06T15:41:00Z","latitude":"9.6000000","longitude":"6.5000000","photo_sha256":"…","polling_unit":"PU004","rejected":6,"supersedes":null,"votes":{"A":214,"B":187,"C":22}}
```

- Keys sorted alphabetically at every level, no whitespace, slashes and unicode unescaped.
- Integers as integers. Coordinates as strings with exactly 7 decimals, or `null`.
- `captured_at` is the exact string the phone recorded, in UTC.
- `votes` contains exactly the parties the phone sends. Parties left out are stored as zero.
- `photo_sha256` is the hex SHA-256 of the EC8A photo file. If the photo is uploaded later (`POST /results/{id}/photo`), it must hash to the same value.

The request carries `device_id` and `signature` (base64 detached signature). The server stores `payload_hash` = SHA-256 of the canonical JSON; its first 8 hex characters are the fingerprint read back to the agent.

## SMS fallback

The aggregator posts inbound messages to `POST /api/v1/sms/inbound` with header `X-Webhook-Token: $POLLPROOF_SMS_WEBHOOK_TOKEN` and body `{"from": "<phone>", "text": "<message>"}`. Format:

```
R <unit code> AC<accredited> <party><votes> … X<rejected>
R PU004 AC448 A214 B187 C22 D9 X6
```

The response contains the `reply` text to send back, e.g. `OK PU004 total 438 ref 7F2A9C1E`. Unit codes must not contain spaces; party codes are letters only, and `AC` and `X` are reserved.

## API

All routes are under `/api/v1`. Authenticate with `Authorization: Bearer <token>`.

| Method | Path | Who | What |
|---|---|---|---|
| POST | `auth/otp/request`, `auth/otp/verify` | field staff | SMS one-time code sign-in |
| POST | `auth/login` | command room | Email and password sign-in |
| GET | `me` | everyone | Profile, assignment and party list |
| POST | `devices` | field staff | Register the phone's signing key |
| POST | `check-ins` | agent | Geofenced check-in; `request_review=true` to ask a supervisor |
| POST | `check-ins/{id}/review` | supervisors | Approve or reject an outside-area check-in |
| POST | `results` | agent | Signed result, or a correction with `supersedes_id` |
| POST | `results/{id}/photo` | agent | Attach the EC8A photo later |
| POST | `incidents` | field staff | Report an incident with media |
| POST | `agents/{id}/assign` | supervisors | Send a reserve to a unit |
| GET | `tally` | command room | Totals, per-LGA shares, 25%-in-⅔ test |
| GET | `units` | command room | Units with reporting status; filters `status`, `lga_id`, `ward_id`, `search` |
| GET | `results-queue` · POST `results/{id}/approve` · `send-back` | verifier | Second check |
| POST | `collation-entries` · GET | data officer, collation agent | Declared EC8B/C/D and comparison |
| POST | `irev-entries` | data officer | IReV figures or "not on IReV" |
| GET | `alerts` · PATCH `alerts/{id}` | command room | Live alerts |
| GET | `incidents` · PATCH `incidents/{id}` | command room | Incident handling |
| GET | `units/{id}/evidence` | legal | Evidence bundle for one unit |
| GET | `audit-logs`, `audit-logs/verify` | legal, DG | Audit trail and chain check |
| GET | `stipends`, `stipends/export` | finance, DG | Eligibility and payout CSV |
| POST | `structure/import` | DG, situation room | Polling-unit CSV import |

## Commands

```bash
php artisan pollproof:import-structure {campaign} units.csv   # lga_code,lga_name,ward_code,ward_name,pu_code,pu_name,latitude,longitude,registered_voters
php artisan pollproof:verify-audit {campaign}
```

## Not built yet

- A real SMS aggregator driver (bind it to `App\Services\Sms\SmsSender`).
- Two-step verification for command-room sign-in.
- PDF export of evidence bundles; today the bundle is JSON.
- Encryption at rest per campaign and object storage for photos; photos use the `local` disk.
- The web dashboard and the Flutter agent app.
