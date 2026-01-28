# Admin API Documentation

## Authentication

All admin endpoints (except `/api/admin/login`) require an admin token in the `Authorization` header.

### Login

**POST** `/api/admin/login`

Request:
```json
{
  "password": "your_admin_password"
}
```

Response:
```json
{
  "success": true,
  "data": {
    "token": "abc123...",
    "expires_at": "2024-01-22 12:00:00"
  }
}
```

### Logout

**POST** `/api/admin/logout`

Headers:
```
Authorization: Bearer abc123...
```

Response:
```json
{
  "success": true,
  "data": {
    "message": "Logged out successfully"
  }
}
```

## Card Management

### List Cards

**GET** `/api/admin/cards/list`

Query Parameters:
- `type` (optional): Filter by card type (`response` or `prompt`)
- `tag_id` (optional): Filter by tag ID
- `active` (optional): Filter by active status (1 or 0, default: 1)
- `limit` (optional): Number of cards to return (default: 100, max: 10000, 0 = no limit)
- `offset` (optional): Pagination offset (default: 0)

Headers:
```
Authorization: Bearer abc123...
```

Response:
```json
{
  "success": true,
  "data": {
    "cards": [
      {
        "card_id": 1,
        "type": "response",
        "copy": "Card text here",
        "choices": null,
        "active": 1,
        "tags": [
          {
            "tag_id": 1,
            "name": "Profanity",
            "description": null,
            "active": 1
          }
        ]
      }
    ],
    "total": 748,
    "limit": 100,
    "offset": 0
  }
}
```

Examples:
```bash
# List all response cards
GET /api/admin/cards/list?type=response

# List cards with a specific tag
GET /api/admin/cards/list?tag_id=1

# List inactive cards
GET /api/admin/cards/list?active=0

# Pagination
GET /api/admin/cards/list?limit=50&offset=100

# Get all cards (no limit)
GET /api/admin/cards/list?limit=0
```

### Import Cards from CSV

**POST** `/api/admin/cards/import?type=response`

Headers:
```
Authorization: Bearer abc123...
Content-Type: multipart/form-data
```

Form Data:
- `file`: CSV file with cards (first column is card text, columns 2-11 are tags)

Query Parameters:
- `type`: `response` or `prompt`

Response:
```json
{
  "success": true,
  "data": {
    "imported": 100,
    "failed": 2,
    "errors": ["Row 5: Invalid card text", ...]
  }
}
```

### Edit Card

**PUT** `/api/admin/cards/edit/{cardId}`

Headers:
```
Authorization: Bearer abc123...
Content-Type: application/json
```

Request:
```json
{
  "copy": "Updated card text",
  "type": "response",
  "choices": 1,
  "active": true
}
```

Response:
```json
{
  "success": true,
  "data": {
    "card_id": 123,
    "updated": true
  }
}
```

### Delete Card

**DELETE** `/api/admin/cards/delete/{cardId}`

Headers:
```
Authorization: Bearer abc123...
```

Response:
```json
{
  "success": true,
  "data": {
    "card_id": 123,
    "deleted": true
  }
}
```

## Tag Management

### Create Tag

**POST** `/api/admin/tags/create`

Headers:
```
Authorization: Bearer abc123...
Content-Type: application/json
```

Request:
```json
{
  "name": "New Tag",
  "description": "Tag description",
  "active": true
}
```

Response:
```json
{
  "success": true,
  "data": {
    "tag_id": 5,
    "name": "New Tag"
  }
}
```

### Edit Tag

**PUT** `/api/admin/tags/edit/{tagId}`

Headers:
```
Authorization: Bearer abc123...
Content-Type: application/json
```

Request:
```json
{
  "name": "Updated Tag Name",
  "description": "Updated description",
  "active": true
}
```

Response:
```json
{
  "success": true,
  "data": {
    "tag_id": 5,
    "updated": true
  }
}
```

### Delete Tag

**DELETE** `/api/admin/tags/delete/{tagId}`

Headers:
```
Authorization: Bearer abc123...
```

Response:
```json
{
  "success": true,
  "data": {
    "tag_id": 5,
    "deleted": true
  }
}
```

## Game Management

### List Games

**GET** `/api/admin/games/list`

Query Parameters:
- `state` (optional): Filter by game state (`waiting`, `playing`, `round_end`, `game_over`)
- `limit` (optional): Number of games to return (default: 50, max: 10000, 0 = no limit)
- `offset` (optional): Pagination offset (default: 0)

Headers:
```
Authorization: Bearer abc123...
```

Response:
```json
{
  "success": true,
  "data": {
    "games": [
      {
        "game_id": "ABCD",
        "tags": [1, 2, 3],
        "draw_pile": {
          "response": [1, 2, 3, ...],
          "prompt": [101, 102, 103, ...]
        },
        "discard_pile": {
          "response": [],
          "prompt": []
        },
        "player_data": {
          "state": "playing",
          "creator_id": "player-123",
          "players": [...],
          "current_czar_id": "player-123",
          "current_prompt_card": {...},
          "current_round": 3,
          "submissions": [...],
          "settings": {...}
        },
        "created_at": "2024-01-21 12:00:00",
        "updated_at": "2024-01-21 12:30:00"
      }
    ],
    "total": 15,
    "limit": 50,
    "offset": 0
  }
}
```

Examples:
```bash
# List all games
GET /api/admin/games/list

# List only active games
GET /api/admin/games/list?state=playing

# List games waiting for players
GET /api/admin/games/list?state=waiting

# Pagination
GET /api/admin/games/list?limit=20&offset=40

# Get all games (no limit)
GET /api/admin/games/list?limit=0
```

### Delete Game

**DELETE** `/api/admin/games/delete/{gameId}`

Headers:
```
Authorization: Bearer abc123...
```

Response:
```json
{
  "success": true,
  "data": {
    "game_id": "ABCD",
    "deleted": true
  }
}
```

## Public Endpoints

### View Game

**GET** `/api/games/view/{gameId}`

No authentication required.

Response:
```json
{
  "success": true,
  "data": {
    "game": {
      "game_id": "ABCD",
      "state": "playing",
      "players": [...],
      ...
    }
  }
}
```

## Configuration

Set the admin password in your `.env` file:

```
ADMIN_PASSWORD=your_secure_password_here
```

**Important:** Change the default password before deploying to production!
