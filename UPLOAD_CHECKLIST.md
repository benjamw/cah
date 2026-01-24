# Upload Checklist - Quick Deployment Guide

This is a quick reference guide for uploading files to your production server.

## Pre-Upload: Build Client

On your local machine:

```bash
cd client
npm install
npm run build
```

This creates `client/dist/` with production files.

## Upload Structure

Upload files to your server in this structure:

```
public_html/ (or www/)
├── index.html              ← FROM client/dist/index.html
├── assets/                 ← FROM client/dist/assets/
├── .htaccess               ← FROM root/.htaccess
├── .env                    ← CREATE on server (see below)
├── api/
│   ├── .htaccess           ← FROM api/.htaccess
│   └── index.php           ← FROM api/index.php
├── admin/
│   ├── .htaccess           ← FROM admin/.htaccess
│   ├── index.html          ← FROM admin/index.html
│   ├── app.js              ← FROM admin/app.js
│   ├── styles.css          ← FROM admin/styles.css
│   └── example-import.csv  ← FROM admin/example-import.csv (optional)
├── config/
│   ├── database.php        ← FROM config/database.php
│   └── game.php            ← FROM config/game.php
├── src/                    ← FROM src/ (entire folder)
├── vendor/                 ← FROM vendor/ (entire folder, after composer install)
├── data/                   ← FROM data/ (entire folder)
└── composer.json           ← FROM composer.json
```

## Important Notes

### ✅ DO Upload:
- `index.html` and `assets/` from `client/dist/` to root level
- All `.htaccess` files (they're ready to use as-is)
- `api/`, `admin/`, `config/`, `src/`, `vendor/`, `data/` folders
- `composer.json`

### ❌ DO NOT Upload:
- `client/` folder (only upload the built files from `dist/`)
- `node_modules/`
- `tests/`
- `.git/`
- `.env.example`

## .htaccess Files - Ready to Use

All three `.htaccess` files are production-ready and work as-is:

1. **Root `.htaccess`**: Routes everything correctly
   - `/` → Client app
   - `/api/*` → API endpoints  
   - `/admin/*` → Admin panel
   - Removes trailing slashes
   - Security headers included

2. **api/.htaccess**: Routes API requests to `index.php`

3. **admin/.htaccess**: Handles admin panel SPA routing

**No modifications needed!** Just upload them.

## After Upload Checklist

- [ ] Create `.env` file (see DEPLOYMENT.md Step 3.1)
- [ ] Set file permissions: `chmod -R 755` for directories, `chmod 600 .env`
- [ ] Create MySQL database
- [ ] Import schema: `src/Database/migrations/schema.sql`
- [ ] Import data: `data/tags.sql` and `data/cards.sql`
- [ ] Test API: Visit `https://yourdomain.com/api/health`
- [ ] Test Client: Visit `https://yourdomain.com/`
- [ ] Test Admin: Visit `https://yourdomain.com/admin/`
- [ ] Enable SSL/TLS and uncomment HSTS headers in `.htaccess` files

## Testing URLs

After deployment, test these URLs:

1. **API Health Check**: `https://yourdomain.com/api/health`
   - Should return JSON with database status

2. **Client App**: `https://yourdomain.com/`
   - Should show the game interface

3. **Admin Panel**: `https://yourdomain.com/admin/`
   - Should show admin login

4. **All paths work without trailing slash**:
   - ✅ `/api` works
   - ✅ `/admin` works
   - ✅ No redirects needed

## HTTPS Configuration

After enabling SSL/TLS certificate on your server:

1. In **root `.htaccess`**, **api/.htaccess`**, and **admin/.htaccess**:
   ```apache
   # Uncomment this line:
   Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
   ```

2. In `.env`:
   ```env
   CORS_ALLOWED_ORIGINS=https://yourdomain.com
   ```

## Quick Troubleshooting

**Admin showing client app?**
- Verify `admin/.htaccess` is uploaded
- Check that `admin/index.html` exists
- Clear browser cache

**API 404 errors?**
- Verify `api/.htaccess` is uploaded
- Check mod_rewrite is enabled on server
- Check `api/index.php` exists

**Client 404 on refresh?**
- Verify root `.htaccess` is uploaded
- Check mod_rewrite is enabled

**500 Internal Server Error?**
- Check PHP version (needs 8.1+)
- Check error logs
- Verify `.env` file exists and is readable

For detailed deployment instructions, see `DEPLOYMENT.md`.
