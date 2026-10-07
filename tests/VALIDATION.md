# Student ID and community approval validation

Validated on 6 October 2026 with XAMPP PHP and an isolated local MariaDB instance, using fictional demo records.

- PHP syntax checks passed for application and test files.
- `student_registry.php`: 34 checks passed for manual entry, CSV and actual XLSX uploads, leading zeros, a 1,000-ID batch, duplicate handling, invalid/oversized uploads, CSRF and admin restrictions, approved-ID registration, reused IDs, community rejection and decision replay.
- `community_accounts.php`: 38 checks passed, covering the registration choice, separate login, four-leader minimum, unique leader emails, pending-login rejection, administrator approval, private leader access, a fifth leader, community club/event creation and account deletion.
- `integration.php`: 83 checks passed for existing personal accounts, roles, club/event ownership, memberships, RSVP capacity, calendars, profiles, enquiries and account deletion.
- `browser.ps1`: 70 checks passed in Chrome, including student ID fields, the admin Excel/manual entry section, community review section, desktop/mobile layouts, and existing personal, organiser and admin workflows.

These 225 checks used a temporary database and web server, not the user's XAMPP database. Rerunning the installer also completed successfully with existing records. The normal installation still requires running Apache/MySQL and `scripts/install.php` with local configuration. The installer adds missing tables while preserving existing accounts. Tests do not establish that every possible input or deployment environment is error-free. ID matching checks list eligibility, not identity ownership.
