# Production checklist

Settings that live on the server, not in code. Go through them before each launch.

## PHP
- Latest **PHP 8.3.x or 8.4.x**, kept patched. Older builds have known remote-code-execution bugs
  (e.g. CVE-2024-4577, PHP-CGI argument injection, fixed in 8.3.8).
- Run PHP through **PHP-FPM** (nginx/Apache + FPM), never as CGI.
- `expose_php = Off` and `display_errors = Off` in php.ini.

## .env
- `APP_ENV=production`, `APP_DEBUG=false` (debug pages show code and secrets).
- `APP_KEY` set (`php artisan key:generate` once) and never shared.
- `APP_URL=https://your-domain`. In production every generated link is forced to https
  (`AppServiceProvider`).
- `SANCTUM_EXPIRATION` - minutes a sign-in lasts (default 90 days).
- `OTP_SMS_ENABLED=true` with real Twilio credentials; codes are never logged in production.
- Real Razorpay `key_id`, `key_secret` and `webhook_secret`. Without a webhook secret, every
  webhook is refused.
- Database user with access to this database only.

## Web server
- HTTPS only (redirect http to https), with a valid certificate.
- Point the web root at `public/` - never the project folder, so `.env` and `storage/` aren't reachable.
- `php artisan storage:link` for library and profile photos. ID proofs stay on the private
  `local` disk and are only served through the logged-in API.

## Running
- `composer install --no-dev --optimize-autoloader`, then `php artisan migrate --force`,
  `php artisan config:cache route:cache view:cache`.
- Cron: `* * * * * php /path/to/artisan schedule:run` (reminders, alerts, token pruning).
- A queue worker if `QUEUE_CONNECTION` isn't `sync`.

## Keeping it safe
- `composer audit` before each release; update with `composer update` and run `php artisan test`.
- Admin accounts are made with `php artisan librum:make-admin` - use a number that isn't an owner's.

## The app
- Build with `EXPO_PUBLIC_API_URL=https://...`. Release builds refuse a plain `http://` address.

## The web app (PWA, for iPhone owners)
- Build it in the app repo: `EXPO_PUBLIC_API_URL=https://... npm run build:web` → static files in `dist/`.
- Host `dist/` over HTTPS on its own address, e.g. `https://app.your-domain`:
  - **Cloudflare Pages / Netlify** (free): upload `dist/`; `_redirects` and `_headers` in it are
    picked up automatically.
  - **Your own nginx**: serve `dist/` as the root with
    `location / { try_files $uri $uri.html $uri/index.html =404; }`,
    `location ~ ^/members/[^/]+$ { try_files "/members/[id].html" =404; }`,
    `error_page 404 /+not-found.html;`, and `Cache-Control: no-cache` on `/sw.js`.
- Set `WEB_APP_URL=https://app.your-domain` in the backend `.env`, so plan payments started
  from the web app can return to it (any other web address is refused).
- Owners install it from Safari: Share → Add to Home Screen.
