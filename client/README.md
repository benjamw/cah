# Cards API Hub - Client

This is the mobile-first React front-end for the Cards API Hub game.

## Features

- **Mobile-First Design**: Optimized for portrait mode on phones
- **Swipeable Cards**: Touch-friendly card swiping with visual indicators
- **Real-time Updates**: Polls server every 2 seconds for game state
- **Persistent Storage**: Uses both localStorage and cookies to maintain session
- **Conditional Requests**: Supports If-Modified-Since headers to reduce bandwidth

## Setup

### Install Dependencies

```bash
cd client
npm install
```

### Development

Run the development server with hot reloading:

```bash
npm run dev
```

This will start the Vite dev server on `http://localhost:3000` with API proxying to your PHP backend.

### Production Build

Build for production:

```bash
npm run build
```

This creates an optimized build in the `dist/` directory that can be served by your web server.

### Preview Production Build

Preview the production build locally:

```bash
npm run preview
```

## Game Flow

1. **Join/Create Game**: Players can join an existing game or create a new one
2. **Waiting Room**: Shows all players and game settings, host can start the game
3. **Playing**: 
   - Regular players see their white cards (swipeable), select cards, and submit
   - Card Czar sees all submissions (swipeable) and picks a winner
4. **Persistent State**: Game state is saved to localStorage/cookies

## Tech Stack

- **React 18**: UI framework
- **Vite**: Fast build tool and dev server
- **Vanilla CSS**: Mobile-first responsive design
- **Marked**: Markdown parsing for card text

## Browser Support

- Modern mobile browsers (iOS Safari, Chrome, Firefox)
- Touch events for swiping
- Responsive design for portrait orientation
- Fallback to cookies if localStorage is unavailable
