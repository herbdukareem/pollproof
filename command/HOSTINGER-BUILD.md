# hostinger-build branch

This branch is `main` with the parts Hostinger can't build itself already included:

- `public/build/` — the command-room front-end (`npm run build`)
- `vendor/` — the PHP packages (`composer install --no-dev --optimize-autoloader`)
- `.htaccess` — sends every request to `public/` when this folder is the web root

It is for hosts that pull straight from Git without running a build. Rebuild it from `main`
whenever `main` changes. The GitHub Actions deploy (`deploy/hostinger/README.md`) doesn't need it.

Never commit `.env`; upload it to this folder on the server.
