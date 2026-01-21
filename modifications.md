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
- `wallet.php`: wallet page with simulated card form and credit update.

Updated
- `header.php`: session-aware navigation and corrected CSS paths.
- `header.php`: added wallet access link for logged-in users.
- `admin.php`: full CRUD for vehicles, status filter, and change logging.
- `admin.php`: delete vehicles with dependent rentals/issues and add a bulk delete action.
- `admin.php`: improved mobile layout with responsive table and stacked action buttons.
- `admin.php`: added a dedicated mobile card layout for the vehicle list.
- `admin.php`: constrained vehicle list width on desktop for a tighter layout.
- `index.php`: user filter is All/Available only, admin booking disabled, and English-only identifiers.
- `profile.php`: admin change log view and user rental history using English table/column names.
- `profile.php`: added wallet access button in the user dashboard.
- `auth.php`: case-insensitive admin checks and redirect for unauthorized access.
- `login.php`: uses `users` table and English column names.
- `login.php`: added removable quick-fill buttons for demo credentials.
- `register.php`: uses `users` table and English form fields.
- `seed.php`: auto-creates schema, uses English demo data, and removes auto images.
- `booking.php`: wallet-only booking flow with credit checks, warning modal, and wallet CTA.
- `booking.php`: logs rental charges in the transactions history.
- `CSS/style.css`: added placeholder style for missing images.
- `db/db_config.php`: English connection error message.
- `db/schema.sql`: adds a `transactions` table for wallet activity logging.
- `header.php`, `footer.php`, `index.php`, `login.php`, `register.php`, `booking.php`, `profile.php`, `seed.php`, `admin.php`: UI copy translated to English.
- `topup.php`: renamed to `wallet.php`.
- `wallet.php`: added quick amount buttons and a removable card autofill button.
- `wallet.php`: logs wallet credits in the transactions history.
- `profile.php`: added user-facing transactions list and admin issue management UI.
- `seed.php`: includes `transactions` in required tables.
- `db/schema.sql`: issues now include admin notes and review metadata.
