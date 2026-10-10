# CampusConnect

Student clubs and events website built with PHP 8.2+, MySQL-compatible SQL, HTML, CSS and JavaScript. All website assets are local; Composer and npm are not required.

## Run on XAMPP

1. Keep this project in `C:\xampp\htdocs\CampusConnect_Complete`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Copy `config/example.php` to `config/local.php` and set your database connection details. The default database is `campusconnect` on port 3306.
4. From this folder, run:

```powershell
& 'C:\xampp\php\php.exe' scripts/install.php --demo
```

The installer creates the database, imports the schema and generates an application key. On a fresh installation, `--demo` adds fictional clubs, events and accounts. Existing accounts and data are preserved on reruns. The database user needs permission to create the database, or you must create it first.

5. Open `http://localhost/CampusConnect_Complete/`.
6. Read `var/demo-accounts.txt` for generated login credentials. Keep this file private.

For an empty installation, omit `--demo`. You can supply `--admin-email=you@example.edu` and `--admin-name="Campus Administrator"` to create the first administrator.

For the PHP development server, after database installation:

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8080 router.php
```

Open `http://127.0.0.1:8080/`. PHP requires a PHP-capable server; VS Code Live Server cannot run the backend.

## Website files

- `index.php`, `router.php`, `.htaccess`: entry point, development routing and Apache settings.
- `app/`: accounts, clubs, events, community pages, layout and shared backend code.
- `assets/`: styles, JavaScript and favicon.
- `config/`: database connection and application settings.
- `database/`: installation schema.
- `scripts/install.php`: installation and initial accounts.
- `scripts/maintenance.php`: expired rate limits, old audit entries and expired session cleanup.
- `var/`: private runtime storage, sessions, logs and generated credentials.
- `tests/`: website integration, concurrency and browser checks.

Keep the directory protection `.htaccess` files. Keep local configuration, generated credentials and runtime data private; `.gitignore` excludes them from version control.

## Community accounts

Registration offers Personal account and Community account options. Community registration requires one shared email/password, the registering leader's approved student ID, and at least four leaders with distinct email addresses (up to eight). Only the registering leader provides a student ID; list this person as Leader 1. Leader contacts do not create individual logins. The request appears in the admin dashboard for approval or rejection. Pending and rejected communities cannot log in. Once approved, the community can manage clubs, events and its private leader list. Updating leaders requires the shared password and cannot leave fewer than four leaders.

Community accounts use the separate Community login link on the personal login page. Existing personal accounts and organiser accounts continue to use personal login. All leaders using the shared account have the same access, including password changes and account deletion.

For an existing installation, run `scripts/install.php` again after updating the code. It creates any missing tables, including the student ID registry, personal ID claims and community applications, without replacing existing accounts. Existing personal accounts and communities created before the approval workflow retain their access. New communities must be approved. Back up your database before applying updates. For a new installation, follow the normal setup above.

## Approved student ID list

Log in as an administrator and open **Campus administration > Approved student IDs**. Enter one ID per line or upload an Excel `.xlsx` or comma-separated `.csv` file. Older `.xls` files must be saved as `.xlsx` first. No Composer dependency or PHP ZIP extension is required.

Use a `student_id` column header; without a header, the first column is used. Excel imports use the first worksheet only. Format IDs as **Text** before entering them into Excel to preserve leading zeros. IDs may contain 1-40 letters, digits or hyphens. They are trimmed and converted to uppercase. Uploads are limited to 2 MB and 5,000 rows; formula cells are rejected. Duplicate IDs are skipped, and invalid batches add nothing. The uploaded file is removed after parsing.

Example:

```csv
student_id
001234
CC-1002
```

New personal accounts require an ID on this list. One ID can be linked to one personal account; deleting that account releases the ID. The same ID can identify the registering leader on community requests. Admin uploads add eligibility records, not student login accounts. Existing accounts and installer-created demonstration accounts continue to work.

ID matching checks list eligibility, not ownership of the ID. Administrators should verify the registering leader when reviewing community applications. The admin dashboard includes the submitted ID and leader contacts for that review.

## Website checks

With a local development database installed and the website running:

```powershell
& 'C:\xampp\php\php.exe' tests/integration.php http://127.0.0.1:8080
& 'C:\xampp\php\php.exe' tests/community_accounts.php http://127.0.0.1:8080
& 'C:\xampp\php\php.exe' tests/student_registry.php http://127.0.0.1:8080
powershell -NoProfile -ExecutionPolicy Bypass -File tests/browser.ps1
```

Integration tests create temporary records and clean them up afterward. Use a development database only. Browser tests require Chrome and the unchanged local demo-account password. Generated test output is stored in `tests/output/`.

## Functionality

The application includes registration and login, profiles, club memberships and management, organiser approval, events, RSVP and cancellation, calendar downloads, and an administrator inbox. Contact submissions are stored in the inbox; they do not send email. Events are free.

If the website displays the setup/unavailable page, check that MySQL is running, the connection settings are correct and the installer has completed. Details are recorded in `var/application.log`.

## Four-student file distribution

The `Student_Collaboration` folder contains four complementary source modules and assembly instructions. Every student has PHP application code:

- Student 1: personal accounts, shared backend, page layout, routing and installation.
- Student 2: clubs, public/community pages, administrator student ID registry, database schema and browser checks.
- Student 3: events, integration checks, student ID and community account tests, and JavaScript.
- Student 4: community registration, administrator approval, separate login, leader management, the PHP Help & Getting Started page (`app/help.php`) and CSS.

The Help & Getting Started page is available from the website footer and at `index.php?page=help`. It explains personal ID verification, community approval, clubs, events and common account questions.

The modules must be combined to run the site. The manifest and assembly script verify that their combined files match this version exactly. These assignments describe file ownership for collaboration, not evidence of individual authorship.
