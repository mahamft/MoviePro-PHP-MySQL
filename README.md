# MoviePro — Full Revised 3D Movie Booking System

MoviePro is a complete PHP 8 + MySQL movie-booking platform with a cinematic 3D interface, animated background on every page, full customer profile system, smart seat reservations, multi-movie cart, checkout, QR tickets, loyalty rewards, concessions, notifications, analytics and an administrative control panel.

The project has **no preloader**. Pages render immediately.

## Fresh installation — recommended

1. Extract the project into:

   `C:\xampp\htdocs\MoviePro_PHP_MySQL_Complete`

2. Start **Apache** and **MySQL** in XAMPP.
3. Open `http://localhost/phpmyadmin`.
4. Choose **Import**.
5. Import only:

   `database/00_FRESH_INSTALL_FROM_SCRATCH.sql`

6. Open:

   `http://localhost/MoviePro_PHP_MySQL_Complete/`

7. Sign in as the administrator. Successful admin login opens:

   `http://localhost/MoviePro_PHP_MySQL_Complete/admin/index.php`

The fresh SQL deletes any existing `moviepro_db` database, then creates a consistent schema and demo content. Back up important data first.

## Existing advanced MoviePro database

When your current advanced database already contains orders, carts, seat holds, coupons and concessions:

1. Back up the database.
2. Replace project files.
3. Import only:

   `database/upgrade_profile_system.sql`

4. Hard-refresh the browser so the new PWA cache activates.

Older migrations are stored in `database/legacy_migrations/` and are not required for a fresh installation.

## Demo accounts

### Administrator

- Email: `admin@moviepro.com`
- Password: `password`

### Customer

- Email: `user@moviepro.com`
- Password: `user123`

## Customer features

- Registration and secure login
- Guided profile setup after registration
- Profile picture upload and removal
- Full name, username, email, phone, bio and city
- Date of birth, gender and language preferences
- Favorite movie genres
- Marketing preference
- Password change with current-password verification
- Profile completion indicator
- Loyalty balance, booking/order/review statistics
- Recent booking activity inside Profile
- Movie catalogue and filters
- Trailers, details, cast, ratings and reviews
- Date/cinema/showtime selection
- Gold, Platinum and Box seat classes
- Live perspective seat map
- Automatic centered contiguous seat selection
- Database-backed seat holds and countdown
- Multi-movie reservation cart
- Snacks and combo ordering
- Promo codes and CinePoints redemption
- Card, JazzCash, Easypaisa and cash demo methods
- Grouped order processing
- QR e-tickets and print views
- Booking history and cancellation
- Notifications
- PWA static-asset cache

## Administrator features

- Animated dashboard and live statistics
- Movie management
- Cinema management
- Showtime and class-pricing management
- Orders and invoices
- Booking monitoring
- Active reservation monitoring/release
- User roles, account status and profile-completion view
- Review moderation
- Contact-message management
- Promo-code management
- Concession inventory
- Commerce and loyalty settings
- Revenue, payment, movie and operational analytics

## Visual and interaction system

- Luxury black, cinema-red and gold theme
- Animated background on public and admin pages
- Floating particles, fog, aurora, film grain and perspective grid
- Full-page custom cursor on precise-pointer devices
- Cursor hover states and click ripples
- Magnetic buttons
- 3D card tilt and poster depth
- Hero parallax and floating cinema objects
- Cinematic page transitions
- Perspective theatre seat map
- Animated payment and success scenes
- Mobile and low-power fallbacks
- `prefers-reduced-motion` support

## Profile image security

- Allowed formats: JPG, PNG and WebP
- Maximum size: 3 MB
- Uploaded filenames are randomized
- Executable extensions are blocked inside `uploads/avatars/`
- Previous avatar files are removed when replaced

Ensure Apache can write to:

`uploads/avatars/`

On Windows/XAMPP this normally works automatically.

## Database configuration

Defaults are located in `includes/config.php`:

```text
DB_HOST = 127.0.0.1
DB_NAME = moviepro_db
DB_USER = root
DB_PASS = empty
APP_TIMEZONE = Asia/Karachi
```

Environment variables with the same names override these defaults.

## Requirements

- PHP 8.1 or newer
- MySQL 8 or compatible MariaDB
- Apache/XAMPP, WAMP or Laragon
- PHP extensions: `mysqli`, `fileinfo`; `mbstring` recommended
- Modern browser with ES6 and CSS custom-property support

## Important payment note

Payment interfaces are demonstrations. No live payment provider SDK, merchant account or secret key is included. Do not enter real financial credentials.

## Production checklist

- Change demo passwords.
- Use HTTPS and secure session-cookie settings.
- Store database credentials in environment variables.
- Integrate official server-side payment gateways.
- Add transactional email/SMS providers.
- Schedule expired-cart cleanup.
- Add rate limiting and account recovery.
- Convert large imagery to AVIF/WebP.
- Test one complete registration → profile → reservation → cart → checkout → QR ticket → cancellation workflow on the production database.
