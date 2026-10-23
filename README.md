# GZHC TMS Supervisor

A standalone PHP/MySQL supervisor workspace built in this folder. The interface follows the local desktop TMS palette: navy `#173B63`, deep navy `#102A46`, teal `#167D8D`, slate text, and a pale blue-gray workspace. It uses local CSS and JavaScript only; no CDN, external font, or online asset is required.

## Requirements

- PHP 8.1 or newer with PDO MySQL enabled
- Access to the existing TMS MySQL database
- An active `Supervisor` account in `req_acc`

The application expects the existing TMS tables and columns: `records` (`id`, `rec_date`, `file_num`, `machine_num`, `Apllicant`, `Defendent`, `audio_status`, `audio`, `remark`, `appointed_on`, `transcriber`, `status`, `distrubuted_on`, `finished_date`, `doc`), `req_acc` (`id`, `username`, `full_name`, `role`, `status`, `password`), and `files` (`id`, `file_number`, `machine_number`, `path`). It does not create, drop, or alter the existing production tables.

## Configure and run

Copy `.env.example` to `.env` and set the `TMS_DB_*` values and `TMS_AUDIO_ROOT`, or define them in the PHP/web-server environment. The local `.env` file is read by the app but must not be committed or shared. A non-empty server environment value takes precedence; an empty server value does not prevent the `.env` setting from being used.

- `records.audio` stores a relative path such as `Audio/Aman/094252-250221.mp3` or `uploads/060311-250319.mp3`. The app reads this value from the selected database record; it does not ask the Supervisor to enter or select an audio URL.
- Set `TMS_AUDIO_ROOT` to the Recorder application directory on the PHP server (the parent directory containing the relative paths stored in `records.audio`). Audio is streamed through an authenticated PHP endpoint with path containment checks and byte-range seeking. A valid root takes precedence over `TMS_AUDIO_BASE_URL`.
- `TMS_AUDIO_BASE_URL` is a legacy fallback for the Recorder application URL, used only when `TMS_AUDIO_ROOT` is empty. Prefer the protected root setting for authenticated streaming.

For a local development server in PowerShell:

```powershell
Copy-Item .env.example .env
# Edit .env with your local TMS database connection.
$env:TMS_DB_HOST = "127.0.0.1"
$env:TMS_DB_PORT = "3306"
$env:TMS_DB_NAME = "tms"
$env:TMS_DB_USER = "your_database_user"
$env:TMS_DB_PASSWORD = "your_database_password"
$env:TMS_AUDIO_ROOT = "C:\TMS\Recorder"
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080`. The PHP built-in server is for local testing, not production hosting.

## Supervisor workflows

- Unauthenticated visits and sign-out return to the shared TMS login at `../index.php`; the shared login routes active Supervisor accounts here, and this workspace rechecks the account role and status before granting access.
- The Account management page lets Supervisors create Recorder, Transcriber, and Supervisor accounts, edit names/gender/roles/status, and set new passwords. Passwords are hashed; usernames cannot be changed. The signed-in Supervisor and the last active Supervisor cannot be demoted or deactivated, and Transcribers with assigned, suspended, or pending recordings must have that work reassigned before their role/status changes.
- Accounts can be searched by username or name and filtered by role/status. The account list is compact and scrollable; each row opens an inline Manage panel, and only one account editor remains open at a time.
- The dashboard summarizes uploaded, assigned, pending, finished, suspended, and overdue appointment counts.
- The assignment queue permits assigning or reassigning Uploaded and Assigned records to active Transcriber accounts. Assignment updates are transactional and recheck both account and recording state.
- Pending recordings have a dedicated queue where Supervisors can keep them pending, change their transcriber, return them to Assigned, or release them to Uploaded.
- Assigned records can be held in the suspended queue. Suspended records can be restored; Finished records can be returned to Uploaded, following the existing TMS status transitions.
- The transcriber page shows active assignment and completion counts. Reports can be filtered by recording date and exported as CSV.
- Assignment lists prioritize unassigned recordings; filters split the unassigned/assigned queues and allow filtering by transcriber. Queue audio metadata is loaded as rows become visible, and one docked player is shared by the page; pending and finished recordings can also be played, and weekly report rows provide the same player and load completed-recording metadata to calculate duration totals.
- The dashboard includes local canvas charts for status distribution and transcriber workload. Reports cover transcriber output, status distribution, daily volume, and on-demand weekly reports grouped by completed date (Monday-Friday). Weekly reports calculate durations from audio metadata, allow per-report transcriber selection, and use the browser's print-to-PDF workflow.
- Finished work shows completion and assignment turnaround dates, and reads the existing overtime classification (`records.doc`, for example `OT`) from the database.
- Ethiopian (Amharic) calendar display is an optional per-browser setting. Date filtering remains Gregorian to match the database's `DATE` fields.
- Mutating forms require POST and a session CSRF token. Supervisor authorization is checked against the live account status.
- Apache users should retain the included `.htaccess` rules that disable directory listing and deny web access to local settings and SQL setup files. Configure equivalent deny rules on IIS/Nginx.

A static IP is not required for audio access: a stable hostname or dynamic DNS can work with VPN/network routing and HTTPS. Do not expose the database or audio storage directory directly to the public internet.
