# Admin UI Quick Start Guide

## Getting Started in 3 Steps

### Step 1: Set Admin Password

Edit your `.env` file in the project root:

```env
ADMIN_PASSWORD=your_secure_password_here
```

### Step 2: Start the Server

```bash
cd C:\Users\welker\Development\cah
composer start
```

The server will start at `http://localhost:8080`

### Step 3: Access Admin Panel

Open your browser and navigate to:

```
http://localhost:8080/admin/
```

Login with the password you set in Step 1.

## What You Can Do

### Cards Section
- View all cards with filtering
- Import cards from CSV
- Delete unwanted cards
- See card tags and metadata

### Tags Section
- Create new tags
- Activate/deactivate tags
- View card counts per tag
- Delete unused tags

### Games Section
- View active games
- Monitor game status
- Delete stuck games

## File Structure

```
admin/
├── index.html          # Main UI
├── styles.css          # Styling
├── app.js              # JavaScript logic
├── .htaccess           # Apache config
├── README.md           # Full documentation
├── QUICKSTART.md       # This file
└── example-import.csv  # Sample CSV
```

## Tips

1. **Import Cards**: Use the example CSV as a template
2. **Filters**: Combine type and status filters for better results
3. **Pagination**: Navigate through large card sets easily
4. **Session**: Your login lasts 24 hours

## Security Notes

- Never commit your `.env` file
- Use a strong admin password
- Tokens expire after 24 hours
- All API calls are authenticated

## Troubleshooting

**Can't access admin panel?**
- Make sure the server is running
- Check that you're using the correct URL
- Verify `.htaccess` is enabled in Apache

**Login fails?**
- Double-check your password in `.env`
- Restart the server after changing `.env`
- Check browser console for errors

**API errors?**
- Verify the backend API is running
- Check CORS settings
- Look at network tab in browser dev tools

## Need Help?

Check the full documentation in `README.md` for detailed information about all features and API endpoints.
