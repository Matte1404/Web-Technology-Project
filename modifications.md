# Modifications

Added
- `auth.php`: session helpers (`require_login`, `require_admin`) with redirect support.
- `login.php`: login form with password verification and redirect handling.
- `register.php`: registration form with validation and password hashing.
- `logout.php`: session cleanup and redirect to home.
- `booking.php`: booking flow with price calculation, rental creation, and static map panel.
- `db/schema.sql`: SQL schema for `users`, `vehicles`, `rentals`, `issues`, and `change_log`.
- `CSS/style.css`: custom utility styles for cards, spacing, and form sections.
- `seed.php`: script to insert demo users, vehicles, and rentals.

Updated
- `header.php`: session-aware navigation and corrected CSS paths.
- `admin.php`: full CRUD for vehicles, status filter, and change logging.
- `index.php`: user filter is All/Available only, admin booking disabled, and English-only identifiers.
- `profile.php`: admin change log view and user rental history using English table/column names.
- `auth.php`: case-insensitive admin checks and redirect for unauthorized access.
- `login.php`: uses `users` table and English column names.
- `register.php`: uses `users` table and English form fields.
- `seed.php`: auto-creates schema, uses English demo data, and removes auto images.
- `booking.php`: admin booking block and English table/column names.
- `CSS/style.css`: added placeholder style for missing images.
- `db/db_config.php`: English connection error message.
- `db/schema.sql`: drops all existing tables in the database and recreates English-only schema.
- `header.php`, `footer.php`, `index.php`, `login.php`, `register.php`, `booking.php`, `profile.php`, `seed.php`, `admin.php`: UI copy translated to English.
