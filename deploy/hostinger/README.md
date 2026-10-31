# Deploying PollProof to Hostinger web hosting

This puts the command room and the API (`command/`) on a Hostinger **Business** or **Cloud** web hosting plan. GitHub Actions builds each release and uploads it over SSH, so nothing is built on the server.

> **Good for staging, demos and a pilot.** Web hosting can't keep a queue worker running, so a cron job sends SMS, email and notifications once a minute. For the real election night, move to a Hostinger VPS.

## How it fits together

```
~/domains/<your-domain>/
├── public_html  ->  pollproof/current/public     (the web root; the old one is kept as public_html.before-pollproof-…)
└── pollproof/
    ├── releases/<date>-<commit>/   one folder per deploy
    ├── shared/.env                 settings, written from your GitHub secrets
    ├── shared/storage/             EC8A photos, selfies, logs; kept between deploys
    └── current  ->  releases/…     the live release
```

A deploy uploads a new release, runs the database migrations, then switches `current` in one step. If anything fails before the switch, the site keeps running the old release.

## One-time set-up

### 0. Merge the pull request

GitHub only shows the **Run workflow** button for workflows on `main`, so merge [pull request #1](https://github.com/herbdukareem/pollproof/pull/1) first.

### 1. In hPanel

1. **Add the website.** Use a subdomain such as `command.yourcampaign.ng`, and wait for its DNS to point at Hostinger.
2. **Turn on SSL** for the domain (free certificate).
3. **PHP:** choose **PHP 8.3** for the domain. In **PHP Configuration → Extensions**, make sure these are on: `sodium`, `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `gd`.
4. **Database:** create a MySQL database and user under **Databases**. Note the database name, user and password; Hostinger prefixes them with your account ID (for example `u388383718_pollproof`).
5. **SSH:** open **Advanced → SSH Access**, enable it, and note the **IP**, **port** (usually `65002`) and **username** (for example `u388383718`).

### 2. A deploy key

On your computer (Windows PowerShell, macOS or Linux):

```powershell
ssh-keygen -t ed25519 -C "pollproof-deploy" -f "$HOME/.ssh/pollproof_deploy"
ssh-keyscan -p 65002 YOUR_SERVER_IP
```

When `ssh-keygen` asks for a passphrase, press **Enter** twice to leave it empty. GitHub can't type a passphrase, so a key that has one won't work.

- Add the **public** key (the contents of `pollproof_deploy.pub`) in **hPanel → SSH Access → SSH keys**.
- Keep the **private** key (`pollproof_deploy`) for the next step. Never commit it.
- Keep the lines printed by `ssh-keyscan`; they let GitHub check it's really your server.

### 3. GitHub settings

In the repository, open **Settings → Secrets and variables → Actions**.

**Secrets**

| Name | Value |
|---|---|
| `HOSTINGER_HOST` | The SSH IP from hPanel |
| `HOSTINGER_PORT` | The SSH port, usually `65002` |
| `HOSTINGER_USER` | The SSH username, e.g. `u388383718` |
| `HOSTINGER_SSH_KEY` | The whole private key file, including the `BEGIN`/`END` lines |
| `HOSTINGER_KNOWN_HOSTS` | The `ssh-keyscan` output (recommended; without it the first run trusts the server it finds) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | From step 1.4 |
| `DB_HOST` | Optional; defaults to `localhost` |
| `TERMII_API_KEY` | Optional until you send real SMS |
| `POLLPROOF_SMS_WEBHOOK_TOKEN` | Optional; a long random string, if your SMS gateway forwards replies |
| `POLLPROOF_USSD_TOKEN` | Optional; a long random string for the Africa's Talking USSD callback (see `docs/ussd.md`) |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Optional; a Hostinger mailbox for email alerts |

**Variables**

| Name | Value |
|---|---|
| `HOSTINGER_DOMAIN` | The domain from step 1.1, e.g. `command.yourcampaign.ng` |
| `POLLPROOF_SMS_DRIVER` | `log` while testing, `termii` to send real SMS |
| `TERMII_SENDER_ID` | Your Termii-approved sender ID |
| `POLLPROOF_SMS_NUMBER` | The number agents text results and YES/NO replies to |
| `POLLPROOF_USSD_CODE` | The USSD code agents dial, e.g. `*384*1234#` |
| `MAIL_HOST` | Optional; `smtp.hostinger.com` to send email alerts |
| `HOSTINGER_AUTO_DEPLOY` | `true` to deploy on every push to `main`; leave unset to deploy only when you press the button |
| `HOSTINGER_PHP_BIN` | Leave unset. Set only if SSH's `php` is older than 8.3 (see Troubleshooting) |
| `HOSTINGER_PUBLIC_MODE` | Leave unset. Set to `copy` only if the site shows a 403 or Hostinger's default page after deploying (see Troubleshooting) |

### 4. First deploy

**Actions → Deploy to Hostinger → Run workflow.** Tick **create the campaign and DG account** and fill in the campaign name, state, election date and the DG's name and email.

The run builds and uploads the release, creates the database tables, switches the site live and checks that `https://<domain>/up` answers. The **Create the campaign and DG** step prints the DG's **temporary password** in the log. The DG must change it at first sign-in, and can then require two-step sign-in for everyone under **Campaign**.

### 5. The cron job

In **hPanel → Advanced → Cron Jobs**, add a job that runs **every minute**:

```
cd /home/u388383718/domains/YOUR_DOMAIN/pollproof/current && php artisan schedule:run
```

(Use your own username and domain.) Every minute it escalates unanswered alerts, checks election-day check-in deadlines, and sends whatever is waiting in the queue: SMS, email and in-app notifications. Without it, nothing is sent.

### 6. Check

- `https://<domain>/login`: sign in as the DG.
- Under **Notifications**, use **Send test alerts to me**, then wait a minute for the cron job to send them.
- Set the GitHub variable `POLLPROOF_API_URL` to `https://<domain>` and run the **Agent app** workflow. The APK it builds then points at this server.

## Every deploy after that

Push to `main` (with `HOSTINGER_AUTO_DEPLOY=true`), or press **Run workflow** without the campaign box ticked. Settings are rewritten from the GitHub secrets on every deploy; the app key and uploaded files are kept.

Don't deploy on election day unless something is broken.

## The agent app download

Every change to the agent app is built by GitHub Actions (workflow **Agent app**) and published to the
repository's `agent-app` release as `pollproof-agent.apk`, pointed at `https://app.pollproof.online`
(override with the Actions variable `POLLPROOF_API_URL`). The server copies the latest build into
`storage/app/agent-app` on every deploy and hourly after that; the DG or situation room can also click
**Agent app → Get latest build now** in the command room, or run:

```
php artisan pollproof:fetch-agent-app
```

Signed-in staff download it from **Agent app** in the top bar, or scan its QR code with a phone (the link works
for an hour). To build without a code change, open GitHub → Actions → Agent app → Run workflow.

## Rolling back

Over SSH:

```bash
bash ~/domains/YOUR_DOMAIN/pollproof/current/.deploy/rollback.sh ~/domains/YOUR_DOMAIN/pollproof
```

This switches back to the previous release. Database changes are not undone, so roll back the code only when the last deploy had no migrations, or restore the database from a Hostinger backup as well.

## Troubleshooting

| What you see | What to do |
|---|---|
| The workflow stops at **Checking PHP** | Set PHP 8.3 for the domain and turn on the extension it names. If SSH's `php` is still older, find the PHP 8.3 binary over SSH (`ls /opt/alt/php83/usr/bin/php` or `which php83`), set it as the variable `HOSTINGER_PHP_BIN`, and use the same path in the cron job instead of `php`. |
| The workflow stops at **Connect to the server** | Its error says what's wrong: a missing setting, a key with a passphrase, a key hPanel doesn't list (compare the fingerprint in the run's notice with the key in hPanel → SSH Access), the wrong IP or port, or a `HOSTINGER_DOMAIN` that doesn't match the folder name under `domains/`. |
| **Permission denied (publickey)** | The public key isn't added in hPanel, `HOSTINGER_USER` isn't the SSH username hPanel shows, or `HOSTINGER_SSH_KEY` is missing its first or last line. |
| Hostinger's default page or a 403 after a successful deploy | The host isn't following the `public_html` symlink. Set the variable `HOSTINGER_PUBLIC_MODE=copy` and deploy again; the web folder becomes a copy of `public/` that points at the live release. |
| **500 error** | Read `~/domains/YOUR_DOMAIN/pollproof/shared/storage/logs/laravel-*.log` over SSH. |
| SMS or notifications never arrive | Check the cron job is saved and runs every minute; `php artisan schedule:run` by hand should print what it ran. |

## Backups

Hostinger backs up web hosting accounts and databases automatically; check how often in hPanel under **Files → Backups**. Before election week, also download a copy of the database from hPanel and of `pollproof/shared/storage` (the photos).
