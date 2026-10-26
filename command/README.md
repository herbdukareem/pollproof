# PollProof Command

One Laravel app that serves both halves of PollProof:

- **The API** (`/api/v1`) for the agent app and SMS fallback: agent sign-in, geofenced check-in, signed polling-unit results, collation and IReV checks, live tallies with the 25% rule, stipend eligibility and a tamper-evident audit log.
- **The command-room web app**, a Vue 3 single-page app served from the same app, that calls the same `/api/v1` endpoints.

Built with Laravel 13, PostgreSQL 16, Sanctum, Vue 3, Vue Router and Tailwind CSS 4 (via Vite).

Phones sign in with Sanctum tokens. The browser signs in at `/session` and uses the Laravel session cookie; Sanctum treats the host that serves the app as its own frontend, so no extra configuration is needed when the app and API share a domain.

## Run it locally

Requirements: PHP 8.3+ with `pdo_pgsql` and `sodium`, Composer, Node 20+, PostgreSQL 16.

```bash
cd command
composer install
npm install
npm run build                    # or `npm run dev` while working on the web app
cp .env.example .env
php artisan key:generate
# create the databases (adjust to your setup)
createuser -P pollproof          # password: secret, or update .env
createdb -O pollproof pollproof
createdb -O pollproof pollproof_test
php artisan migrate --seed       # demo state: 2 LGAs, 4 wards, 12 units, 4 parties
php artisan db:seed --class=ElectionDaySeeder   # optional: plays a short election day through the API
php artisan serve                # open http://localhost:8000 and sign in
php artisan db:seed --class=AfterElectionSeeder # optional: a declaration and an open petition
php artisan queue:work           # sends SMS, email and in-app notifications (all go through the queue)
php artisan schedule:work        # every minute: escalations, check-in deadline, verify backlog
php artisan test
```

Command-room users sign in on the web at `/login` (password `password`): `dg@demo.test`, `situation_room@demo.test`, `verifier@demo.test`, `data_officer@demo.test`, `legal@demo.test`, `finance@demo.test`. Each role sees only its screens.

`ElectionDaySeeder` gives the screens something to show: check-ins (one outside the area, two absent), eight signed results with one correction, three approvals, incidents, a ward collation sheet with B inflated by 40, and IReV checks (one match, one difference, one missing). Run it once on a fresh `migrate --seed`.
Field users sign in by phone: agents `08000001001`–`08000001012`, ward supervisor `08000000100`, collation agent `08000000200`. With `POLLPROOF_SMS_DRIVER=log` the one-time code is written to `storage/logs/laravel.log`.

## Web app

The Vue app lives in `resources/js` and is served by a catch-all route to `resources/views/command.blade.php`.

"Command room" below means DG, situation room, verifier, data officer, legal and LGA/ward supervisors.

| Screen | Path | Roles |
|---|---|---|
| Overview: tallies, 25% tracker, live alerts | `/overview` | command room |
| Units, with evidence links | `/units` | command room |
| Evidence pack for one unit (printable) | `/units/{id}/evidence` | legal, DG |
| Verify results against the EC8A photo | `/verify` | verifier, situation room, DG |
| Collation checks and EC8B/C/D entry | `/collation` | command room |
| IReV entry and comparison | `/irev` | data officer, situation room, DG |
| Incidents | `/incidents` | command room (situation room, legal and DG can update) |
| Agents, check-ins to confirm, reserves | `/agents` | supervisors, situation room, DG |
| Stipends and payment export | `/stipends` | finance, DG |
| Audit log and chain check | `/audit` | legal, DG |
| **Setup:** readiness funnel, per-LGA readiness, reminders | `/setup/readiness` | DG, situation room |
| **Setup:** LGAs, wards, units, GPS gaps, check-in rules | `/setup/structure` | DG, situation room (rules: DG) |
| **Setup:** agent roster, CSV upload, invites, auto-assign | `/setup/roster` | DG, situation room |
| **Setup:** LGA and ward supervisors, open wards | `/setup/supervisors` | DG, situation room |
| **Admin:** users and roles, temporary passwords, deactivation | `/admin/users` | DG |
| **Admin:** election date, freeze date, parties | `/admin/campaign` | DG |
| Change password (forced after a temporary one) | `/account/password` | everyone |
| Two-step sign-in set-up, recovery codes | `/account/security` | everyone |
| **After:** declared result vs our records, records lock, data retention, printable report | `/after/closeout` | DG (legal and situation room read) |
| **After:** petition grounds and answers, tasks, reply bundle | `/after/tribunal` | legal, DG |
| **Admin:** who is told about each alert, by which channel, and escalation | `/admin/notifications` | DG |
| Notification bell (every screen) | header | everyone |

The router enforces roles for navigation; the API enforces them for data.

## Before election day

1. **Campaign** (DG): election date, setup freeze date and the parties on the ballot.
2. **Structure**: import the official polling-unit list (`lga_code, lga_name, ward_code, ward_name, pu_code, pu_name, latitude, longitude, registered_voters`). Units without GPS are listed; supervisors drop a pin from the app at the unit (`PATCH polling-units/{id}` with a GPS fix within the accuracy limit).
3. **Supervisors**: upload `name, phone, level (lga|ward), lga_code, ward_code, backup`, or fill one post at a time. A new holder replaces the old one.
4. **Agent roster**: upload `name, phone, pu_code` (or `lga_code, ward_code, reserve=yes` for reserves). Rows are matched on phone, so a corrected file updates people. Bad rows are skipped and can be downloaded. Auto-assign fills free units with agents registered in the same ward.
5. **Invites**: agents get the app link and their agent ID by SMS. In the app they register a signing key, send a practice result, pass the 10-question quiz (8 to pass) and confirm they will be there.
6. **Readiness**: the funnel from assigned to confirmed, by LGA, with one-tap SMS reminders. Agents can reply `YES` or `NO` to the confirmation SMS; a NO raises an alert so a reserve is sent.
7. **Freeze**: from the freeze date, structure, roster and supervisors are locked (HTTP 423). Sending reserves still works.

## Two-step sign-in

Staff can turn on authenticator-app codes (TOTP, RFC 6238) under the key icon; the DG can require it for everyone under Campaign, after which anyone without it is sent to set it up before they can do anything else (the API enforces this too, as it does for temporary passwords). Each person gets eight one-time recovery codes. A lost phone is handled by the DG's "Reset two-step" on Users and roles. Secrets are stored encrypted with `APP_KEY`; codes can't be reused. For token sign-in (`POST auth/login`) send `code` as well; without it the response is 422 with `two_factor_required: true`. Three failed sign-ins for one account in 15 minutes alert the DG.

## Notifications and escalation

Every alert type has a rule: who is told first, by which channels (in-app, SMS, email), and who is told if it is still open, not acknowledged, after N minutes. Supervisors and collation agents only hear about their own LGA or ward. New and reopened alerts notify; a refresh of an open alert doesn't repeat the message. The scheduler (`pollproof:watch`, every minute) escalates, raises "no agent checked in by 8:30 am" for each unit on election day (deadline set under Campaign, in `POLLPROOF_TIMEZONE`, default Africa/Lagos), and raises a verification-backlog alert above 20 waiting results. The agent app reads its notices from `GET me/notices`.

## After the election

The DG records the official declaration under Close-out. From then on results, collation, IReV, check-ins, incidents and reserve moves are read-only (HTTP 423), and the declared figures are compared with our signed records. The DG chooses what happens to the data; deletion is refused while a tribunal case is open.

The tribunal workspace holds each petition's grounds, our answer to each, the polling units it relies on, and the legal team's tasks. **Build reply bundle** downloads a ZIP with `grounds.md`, each cited unit's evidence (`evidence.json`, EC8A photos, IReV screenshots), the hash-chained `audit-log.csv`, and `MANIFEST.sha256` so counsel can check nothing changed (`sha256sum -c MANIFEST.sha256`).

## SMS

`POLLPROOF_SMS_DRIVER=log` writes messages to the log. For real sending use [Termii](https://termii.com):

```
POLLPROOF_SMS_DRIVER=termii
TERMII_API_KEY=...
TERMII_SENDER_ID=PollProof      # must be approved by Termii
TERMII_CHANNEL=dnd              # reaches numbers on the Do-Not-Disturb list
```

One-time codes are sent straight away. Invites and reminders are queued, so run `php artisan queue:work` (or a supervisor process) in production. Numbers are stored as `0803…` and sent as `234803…`.

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

## Retries from the app

The app resends anything it isn't sure went through. Sending the same signed result again returns the first record (200) instead of an error, and check-ins and incidents carry a `client_ref` UUID so a repeat returns the original. `GET me` also gives the app the campaign's check-in rules, the SMS number (`POLLPROOF_SMS_NUMBER`), the agent's supervisor and today's progress.

## Result signing (contract for the mobile app)

On first launch the phone generates an Ed25519 key pair and registers the public key with `POST /api/v1/devices`. Each result is signed over this canonical JSON:

```json
{"accredited":448,"captured_at":"2027-02-06T15:41:00Z","latitude":"9.6000000","longitude":"6.5000000","photo_sha256":"…","polling_unit":"PU004","rejected":6,"supersedes":null,"votes":{"A":214,"B":187,"C":22}}
```

- Keys sorted alphabetically at every level, no whitespace, slashes and unicode unescaped.
- Integers as integers. Coordinates as strings with exactly 7 decimals, or `null`.
- `captured_at` is the exact string the phone recorded, in UTC.
- `votes` contains exactly the parties the phone sends. Parties left out are stored as zero.
- The fixture in `shared/signing-fixture.json` is checked by `tests/Unit/SigningContractTest.php` and by the Flutter tests.
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

All routes are under `/api/v1`. The phone authenticates with `Authorization: Bearer <token>`; the web app uses its session cookie.

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
| PATCH | `polling-units/{id}` | supervisors, DG, situation room | Set a unit's GPS pin or details |
| GET | `onboarding` | field staff | Onboarding step and the quiz questions (no answers) |
| POST | `onboarding/practice` | field staff | Practice result, signed exactly like a real one but for unit code `PRACTICE` |
| POST | `onboarding/quiz` | field staff | `{answers: {q1: "b", …}}`; marked on the server |
| POST | `onboarding/confirm` | field staff | `{available, note}` with `available` true or false for election day |
| GET | `check-ins/{id}/selfie` | supervisors | The selfie sent with a check-in |
| POST | `me/password` | command room | Change password |
| GET/POST/DELETE | `me/two-factor` · POST `me/two-factor/confirm`, `me/two-factor/recovery-codes` | command room | Two-step sign-in |
| GET | `me/notices` · POST `me/notices/read` | everyone | Notifications for the signed-in person |
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
| GET | `setup/structure`, `setup/roster`, `setup/supervisors`, `readiness` | DG, situation room | Setup screens |
| POST | `roster/import`, `roster/invite`, `roster/auto-assign` | DG, situation room | Agents CSV, SMS invites, ward matching |
| POST | `supervisors/import`, `supervisors` | DG, situation room | Supervisor CSV or one post |
| POST | `readiness/remind` | DG, situation room | SMS to `not_installed`, `not_trained` or `not_confirmed` |
| GET/PATCH | `campaign` · POST/PATCH/DELETE `parties` | DG | Campaign settings and the ballot |
| GET/POST/PATCH | `users` · POST `users/{id}/reset-password`, `users/{id}/reset-two-factor` | DG | Command-room accounts |
| GET/PATCH | `notification-rules` · POST `notification-rules/test` | DG | Alert routing and a test send |
| GET | `closeout` · POST `closeout/declare`, `closeout/retention` | DG (read: legal, situation room) | Declaration and data retention |
| GET/POST/PATCH | `tribunal-cases`, `tribunal-cases/{id}/grounds`, `tribunal-grounds/{id}`, `tribunal-cases/{id}/tasks`, `tribunal-tasks/{id}` | legal, DG | Tribunal workspace |
| GET | `tribunal-cases/{id}/bundle` | legal, DG | Reply bundle ZIP |

## Commands

```bash
php artisan pollproof:import-structure {campaign} units.csv   # lga_code,lga_name,ward_code,ward_name,pu_code,pu_name,latitude,longitude,registered_voters
php artisan pollproof:verify-audit {campaign}
```

## Not built yet

- WhatsApp delivery for notifications.
- A map view of units; GPS pins are typed or set from the app.
- A server-generated PDF of evidence and the campaign report; today both print to PDF from the browser.
- Encryption at rest per campaign and object storage for photos; photos use the `local` disk.
