# Deployment Guide - Cards API Hub Game

## Requirements

### Server Requirements
- **PHP**: 8.1 or higher
- **MySQL/MariaDB**: 5.7+ or MariaDB 10.2+
- **Apache**: with mod_rewrite enabled
- **PHP Extensions**: PDO, pdo_mysql, json, mbstring

### Local Development Requirements (for building)
- **Node.js**: 16+ (only needed locally to build the frontend)
- **Composer**: For installing PHP dependencies

---

## Step 1: Build Frontend Locally

Before deploying, you need to build the production frontend files on your local machine:

```bash
# Navigate to client directory
cd client

# Install dependencies (first time only)
npm install

# Build production files
npm run build
```

This creates a `client/dist` folder containing optimized HTML, CSS, and JS files.

---

## Step 2: Prepare Files for Upload

### 2.1 Install PHP Dependencies Locally

```bash
# In the project root
composer install --no-dev --optimize-autoloader
```

This installs only production dependencies and optimizes the autoloader.

### 2.2 Files to Upload

Upload these files and folders to your server:

```
your-domain.com/
├── api/
│   ├── .htaccess          ← Upload
│   └── index.php          ← Upload
├── admin/
│   ├── .htaccess          ← Upload
│   ├── index.html         ← Upload
│   ├── app.js             ← Upload
│   ├── styles.css         ← Upload
│   └── example-import.csv ← Upload (optional)
├── config/
│   ├── database.php       ← Upload
│   └── game.php           ← Upload
├── src/                   ← Upload entire folder
│   ├── Constants/
│   ├── Controllers/
│   ├── Database/
│   ├── Enums/
│   ├── Exceptions/
│   ├── Middleware/
│   ├── Models/
│   ├── Services/
│   └── Utils/
├── vendor/                ← Upload entire folder (from composer install)
├── data/                  ← Upload (contains card data CSV/SQL files)
├── assets/                ← Upload (from client/dist/assets/)
├── index.html             ← Upload (from client/dist/index.html)
├── .htaccess              ← Upload (root htaccess)
├── .env                   ← Create on server (see Step 3)
└── composer.json          ← Upload (for reference)
```

**IMPORTANT**: The client files from `client/dist/` should be uploaded to the ROOT level:
- `client/dist/index.html` → `/index.html` (root)
- `client/dist/assets/` → `/assets/` (root)

### 2.3 Files NOT to Upload

Do **NOT** upload these:
- `node_modules/` (frontend dependencies)
- `client/` folder (source files not needed, only upload the built files from `dist/`)
- `tests/` (unit tests)
- `.git/` (git repository)
- `.env.example` (example only)


---

## Step 3: Configure Server

### 3.1 Create `.env` File

Create a `.env` file in the project root with your production settings:

```env
# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password

# Application Settings
APP_DEBUG=false
APP_TIMEZONE=America/Denver

# CORS Settings (adjust for your domain)
CORS_ALLOWED_ORIGINS=https://yourdomain.com

# Admin Credentials
ADMIN_USERNAME=your_admin_username
ADMIN_PASSWORD_HASH=$2y$10$yourhashhere

# Session Settings
SESSION_LIFETIME=86400
```

**Important**: To generate the admin password hash, run locally:
```php
<?php
echo password_hash('your_secure_password', PASSWORD_DEFAULT);
```

### 3.2 Set File Permissions

```bash
# Make sure Apache can read all files
chmod -R 755 /path/to/your/app

# Protect sensitive files
chmod 600 .env
```

---

## Step 4: Database Setup

### 4.1 Create Database

Via your hosting control panel (cPanel, Plesk, etc.):
1. Create a new MySQL/MariaDB database
2. Create a database user
3. Grant the user ALL privileges on the database
4. Note the credentials for `.env`

### 4.2 Import Schema

Run the schema SQL file to create tables:

```sql
-- Option 1: Via phpMyAdmin
-- Upload and execute: database/schema.sql

-- Option 2: Via command line (if available)
mysql -u your_user -p your_database < database/schema.sql
```

### 4.3 Import Card Data

Import the card data:

```sql
-- Import tags
mysql -u your_user -p your_database < data/tags.sql

-- Import cards
mysql -u your_user -p your_database < data/cards.sql
```

---

## Step 5: Configure Apache

### 5.1 Root `.htaccess` (Production-Ready)

The root `.htaccess` file is already configured and ready to upload as-is. It handles:

- **API Routes**: `/api/*` → routes to `api/index.php`
- **Admin Panel**: `/admin/*` → serves admin panel SPA
- **Client App**: `/` (root) → serves the React client app
- **Trailing Slash Removal**: Automatically removes trailing slashes
- **Security Headers**: Includes all recommended security headers
- **Compression & Caching**: Optimizes performance
- **File Protection**: Blocks direct access to sensitive files

**No modifications needed** - just upload the `.htaccess` files as they are in the repository.

### 5.2 Directory Structure on Server

```
public_html/ (or www/)
├── .env                   ← Create this
├── .htaccess              ← Upload from root
├── index.html             ← Upload from client/dist/index.html
├── assets/                ← Upload from client/dist/assets/
├── api/
│   ├── .htaccess          ← Upload from api/.htaccess
│   └── index.php
├── admin/
│   ├── .htaccess          ← Upload from admin/.htaccess
│   ├── index.html
│   ├── app.js
│   └── styles.css
├── config/
├── src/
├── vendor/
├── data/
└── composer.json
```

### 5.3 HTTPS Configuration

The `.htaccess` files have HSTS (HTTP Strict Transport Security) headers commented out by default. After you enable SSL/TLS on your server:

1. Uncomment the HSTS line in each `.htaccess` file:
   ```apache
   # Change this:
   # Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"

   # To this:
   Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
   ```

2. Update your `.env` file to use `https://` in `CORS_ALLOWED_ORIGINS`

---

## Step 6: Verify Installation

### 6.1 Test API Health

Visit: `https://yourdomain.com/api/health`

You should see:
```json
{
  "success": true,
  "message": "Cards API Hub is running",
  "database": "connected"
}
```

### 6.2 Test Frontend

Visit: `https://yourdomain.com/`

You should see the game interface.

### 6.3 Test Admin Panel

Visit: `https://yourdomain.com/admin/`

Login with your admin credentials.

---

## Step 7: Post-Deployment

### 7.1 Set Up Database Cleanup (Optional)

Add a cron job to clean up old games:

```bash
# Run daily at 3 AM
0 3 * * * cd /path/to/your/app && php -r "require 'vendor/autoload.php'; \$dbConfig = require 'config/database.php'; \CAH\Database\Database::init(\$dbConfig); \CAH\Models\Game::deleteOlderThan(7);"
```

### 7.2 Monitor Logs

Check PHP error logs regularly:
- Location varies by host (check cPanel or hosting docs)
- Look for any errors in `api/php_errors.log` if configured

### 7.3 Enable HTTPS

Ensure your domain has SSL/TLS enabled:
1. Most shared hosts offer free Let's Encrypt certificates
2. Enable in your control panel
3. Update `CORS_ALLOWED_ORIGINS` in `.env` to use `https://`

---

## Troubleshooting

### Common Issues

**1. 500 Internal Server Error**
- Check PHP version (must be 8.1+)
- Check `.htaccess` syntax
- Check file permissions
- Review error logs

**2. Database Connection Failed**
- Verify `.env` database credentials
- Ensure database user has correct permissions
- Check if host requires `localhost` or IP address

**3. API Returns 404**
- Ensure mod_rewrite is enabled
- Check `.htaccess` files are present
- Verify API directory structure

**4. CORS Errors**
- Update `CORS_ALLOWED_ORIGINS` in `.env`
- Ensure protocol matches (http vs https)

**5. Session Issues**
- Check PHP session configuration
- Ensure cookies are allowed
- Verify `SESSION_LIFETIME` in `.env`

---

## Directory Structure on Server

```
public_html/ (or www/)
├── .env                   ← Create this
├── .htaccess              ← Upload from root
├── index.html             ← Upload from client/dist/index.html
├── assets/                ← Upload from client/dist/assets/
├── api/
│   ├── .htaccess
│   └── index.php
├── admin/
│   ├── .htaccess
│   ├── index.html
│   ├── app.js
│   └── styles.css
├── config/
├── src/
├── vendor/
├── data/
└── composer.json
```

---

## Security Checklist

- [ ] `.env` file has restricted permissions (600)
- [ ] Database credentials are secure
- [ ] Admin password is strong and hashed
- [ ] `APP_DEBUG=false` in production
- [ ] HTTPS is enabled
- [ ] CORS is configured for your domain only
- [ ] PHP error display is disabled in production
- [ ] Regular database backups are configured

---

## Updating the Application

To deploy updates:

1. **Build frontend locally**: `cd client && npm run build`
2. **Upload changed files**: Only upload files that changed
3. **Update composer dependencies** (if needed): Run `composer install` locally, upload `vendor/`
4. **Run migrations** (if any): Execute new SQL migrations
5. **Clear browser cache**: Users may need to hard refresh (Ctrl+F5)

---

## Support

For issues or questions:
- Check logs: `api/php_errors.log` or server error logs
- Verify PHP version: `<?php phpinfo(); ?>`
- Test database connection: Visit `/api/health`

---

## Production Optimizations (Optional)

### Enable OPcache (if available)

Add to `php.ini` or `.user.ini`:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
```

### Enable Compression

Add to `.htaccess`:
```apache
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
</IfModule>
```

### Browser Caching

Add to `.htaccess`:
```apache
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpg "access plus 1 year"
  ExpiresByType image/jpeg "access plus 1 year"
  ExpiresByType image/png "access plus 1 year"
  ExpiresByType image/gif "access plus 1 year"
  ExpiresByType text/css "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```
