# Hostinger: SPA in public_html + Laravel in backend

If the live site serves only the frontend from `public_html`, Laravel
lives in `backend`, which is **not** the document root, so `/api` never
runs PHP — Hostinger returns its HTML 404. The bridge below fixes that.

## Required layout

```text
domains/your-paec-site.hostingersite.com/
  public_html/                 ← document root (SPA from paec-core/dist, optional)
    index.html
    assets/
    .htaccess                  ← from hostinger/public_html/.htaccess
    api/
      index.php                ← from hostinger/public_html/api/index.php
      .htaccess                ← from hostinger/public_html/api/.htaccess
  backend/                     ← full paec-ws project
    app/
    bootstrap/
    config/
    public/
    routes/
    vendor/
    .env
    ...
```

## Upload these 3 files

From this repo, copy into Hostinger:

| Local file | Upload to |
|---|---|
| `paec-ws/hostinger/public_html/.htaccess` | `public_html/.htaccess` |
| `paec-ws/hostinger/public_html/api/index.php` | `public_html/api/index.php` |
| `paec-ws/hostinger/public_html/api/.htaccess` | `public_html/api/.htaccess` |

Create the `public_html/api` folder if it does not exist.

If the SPA is also hosted on Hostinger (same-origin), upload the build from
`paec-core/dist/` into `public_html/` (`index.html`, `assets/`, logos, favicon, etc.).
If the SPA is on Vercel, `public_html` only needs the 3 files above.

## Storage (uploads)

PAEC uploads go to DigitalOcean Spaces when `DO_SPACES_*` is set
(`FILESYSTEM_DISK=digitalocean`). If you use the local `public` disk instead:

```bash
cd backend
php artisan storage:link
```

Then either:

- Symlink / copy `backend/public/storage` → `public_html/storage`, **or**
- Keep document root as `backend/public` (see alternative below).

## backend/.env checks

### Same-origin (SPA + API on Hostinger)

```env
APP_URL=https://your-paec-site.hostingersite.com
FRONTEND_URL=https://your-paec-site.hostingersite.com
CORS_ALLOWED_ORIGINS=
JWT_SECRET=...must not be empty
```

### Split deploy (SPA on Vercel → API on Hostinger)

```env
APP_URL=https://your-paec-site.hostingersite.com
FRONTEND_URL=https://your-paec-app.vercel.app
CORS_ALLOWED_ORIGINS=https://your-paec-app.vercel.app
JWT_SECRET=...must not be empty
```

On Vercel (paec-core), set a **build** env var to the Hostinger API:

```env
VITE_API_BASE_URL=https://your-paec-site.hostingersite.com/api
VITE_FRONTEND_URL=https://your-paec-app.vercel.app
```

Then redeploy Vercel. Relative `/api` will not work on Vercel (no Laravel there).
PAEC frontend services already call `/v1/...`, so the base URL ends at `/api`.

Generate secrets / migrate on the server (SSH or temporary artisan run):

```bash
cd backend
php artisan key:generate   # only if APP_KEY is empty
php artisan jwt:secret
php artisan config:clear
php artisan migrate --force
```

## Verify

These must return **JSON**:

1. https://your-paec-site.hostingersite.com/api
2. https://your-paec-site.hostingersite.com/api/health
3. https://your-paec-site.hostingersite.com/api/v1

If you still get Hostinger’s HTML 404, the `api` folder was not created under `public_html`, or the bridge path to `backend` is wrong.

If you get a JSON error `"Laravel backend folder not found"`, rename/move the Laravel project so it sits next to `public_html` and is named exactly `backend`.

## Alternative (cleaner long-term)

In hPanel → Websites → your site → **Document root** → set to `backend/public`, then (optionally) copy the SPA build into `backend/public/`. Then you can remove the `public_html/api` bridge.
