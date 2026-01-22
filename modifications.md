# Modifications

Added
- `includes/auth.php`: session helpers (`require_login`, `require_admin`) with redirect support.
- `login.php`: login form with password verification and redirect handling.
- `register.php`: registration form with validation and password hashing.
- `logout.php`: session cleanup and redirect to home.
- `booking.php`: booking flow with price calculation, rental creation, and static map panel.
- `db/schema.sql`: SQL schema for `users`, `vehicles`, `rentals`, `issues`, and `change_log`.
- `CSS/style.css`: custom utility styles for cards, spacing, and form sections.
- `db/seed.php`: script to insert demo users, vehicles, and rentals.
- `wallet.php`: wallet page with simulated card form and credit update.

Updated
- `includes/`: moved shared layout/session helpers into a dedicated folder.
- `db/seed.php`: moved the seed script into the `db` folder.
- `includes/header.php`: session-aware navigation and corrected CSS paths.
- `includes/header.php`: added wallet access link for logged-in users.
- `admin.php`: full CRUD for vehicles, status filter, and change logging.
- `admin.php`: delete vehicles with dependent rentals/issues and add a bulk delete action.
- `index.php`: user filter is All/Available only, admin booking disabled, and English-only identifiers.
- `profile.php`: admin change log view and user rental history using English table/column names.
- `profile.php`: added wallet access button in the user dashboard.
- `includes/auth.php`: case-insensitive admin checks and redirect for unauthorized access.
- `login.php`: uses `users` table and English column names.
- `login.php`: added removable quick-fill buttons for demo credentials.
- `register.php`: uses `users` table and English form fields.
- `db/seed.php`: auto-creates schema, uses English demo data, and removes auto images.
- `booking.php`: wallet-only booking flow with credit checks, warning modal, and wallet CTA.
- `booking.php`: logs rental charges in the transactions history.
- `CSS/style.css`: added placeholder style for missing images.
- `db/db_config.php`: English connection error message.
- `db/schema.sql`: adds a `transactions` table for wallet activity logging.
- `includes/header.php`, `includes/footer.php`, `index.php`, `login.php`, `register.php`, `booking.php`, `profile.php`, `db/seed.php`, `admin.php`: UI copy translated to English.
- `topup.php`: renamed to `wallet.php`.
- `wallet.php`: added quick amount buttons and a removable card autofill button.
- `wallet.php`: logs wallet credits in the transactions history.
- `profile.php`: added user-facing transactions list, admin issue management UI, and self-service account deletion.
- `db/seed.php`: includes `transactions` in required tables.
