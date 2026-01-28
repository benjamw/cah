# CAH Admin Panel

A simple, clean admin interface for managing the Cards API Hub game.

## Features

### Cards Management
- View all cards (response and prompt)
- Filter by card type and active status
- Pagination support (50 cards per page)
- Import cards from CSV files
- Delete individual cards
- View card tags and metadata

### Tags Management
- View all tags with card counts
- Create new tags
- Activate/deactivate tags
- Delete tags
- See response and prompt card counts per tag

### Games Management
- View all active games
- See game state and player count
- Delete games
- View game start time

## Setup

### 1. Configure Admin Password

Make sure you have set the `ADMIN_PASSWORD` environment variable in your `.env` file:

```env
ADMIN_PASSWORD=your_secure_password_here
```

### 2. Access the Admin Panel

Navigate to `/admin/` in your browser:

```
http://localhost:8080/admin/
```

Or if deployed:

```
https://yourdomain.com/admin/
```

### 3. Login

Enter your admin password to access the panel.

## Usage

### Importing Cards

1. Click the "Import Cards" button in the Cards section
2. Select a CSV file with the following format:

```csv
type,text,tags
response,"Card text here","tag1,tag2"
prompt,"Black card with _ blank","tag1"
```

**CSV Format:**
- `type`: Either "response" or "prompt"
- `text`: The card text (use `_` for blanks in prompt cards)
- `tags`: Comma-separated list of tag names (optional)

3. Click "Upload" to import the cards

### Managing Tags

1. Navigate to the Tags section
2. Click "Create Tag" to add a new tag
3. Use the Activate/Deactivate button to toggle tag status
4. Delete tags that are no longer needed

### Managing Games

1. Navigate to the Games section
2. View all active games with their status
3. Delete games that are stuck or need to be removed

## Security

- All admin endpoints require authentication
- Tokens expire after 24 hours
- Session tokens are stored securely
- CORS protection enabled
- Rate limiting applied

## API Endpoints Used

The admin panel connects to these API endpoints:

- `POST /api/admin/login` - Authenticate
- `POST /api/admin/logout` - Logout
- `GET /api/admin/cards/list` - List cards
- `POST /api/admin/cards/import` - Import cards
- `DELETE /api/admin/cards/delete/{cardId}` - Delete card
- `GET /api/tags/list` - List tags
- `POST /api/admin/tags/create` - Create tag
- `PUT /api/admin/tags/edit/{tagId}` - Edit tag
- `DELETE /api/admin/tags/delete/{tagId}` - Delete tag
- `GET /api/admin/games/list` - List games
- `DELETE /api/admin/games/delete/{gameId}` - Delete game

## Technology Stack

- **Frontend**: Vanilla JavaScript (ES6+)
- **Styling**: Custom CSS with CSS Variables
- **Authentication**: Bearer token (stored in localStorage)
- **API**: RESTful JSON API

## Browser Support

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers supported

## Development

The admin panel is a single-page application (SPA) with no build process required. Simply edit the files:

- `index.html` - HTML structure
- `styles.css` - Styling
- `app.js` - Application logic

Changes are immediately reflected when you refresh the page.

## Troubleshooting

### Can't login
- Check that `ADMIN_PASSWORD` is set in your `.env` file
- Verify the API is running
- Check browser console for errors

### Cards not loading
- Verify you're logged in
- Check that the API endpoint is accessible
- Look for CORS errors in the console

### Import fails
- Verify CSV format is correct
- Check file encoding (should be UTF-8)
- Ensure tags exist or will be created
- Check API logs for detailed errors

## Future Enhancements

Potential features to add:

- [ ] Edit cards inline
- [ ] Bulk card operations
- [ ] Advanced filtering and search
- [ ] Card preview
- [ ] Export cards to CSV
- [ ] Statistics dashboard
- [ ] User activity logs
- [ ] Batch tag assignment
