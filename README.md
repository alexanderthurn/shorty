# Shorty - Automated Video Upload & Social Posting

Shorty is a PHP-based automation tool designed to streamline the process of uploading content to YouTube and posting updates to X (formerly Twitter). It manages video uploads from Google Drive, handles metadata via Google Sheets, and automates nightly posting schedules.

## Features

- **Google Drive Integration**: Automatically fetches video files from a specified folder.
- **Google Sheets Management**: Reads video metadata (title, description, tags) from a Google Sheet.
- **YouTube Upload**: Uploads videos to a specific YouTube playlist.
- **X (Twitter) Posting**: Posts updates to X with video links or custom messages.
- **Nightly Automation**: Can be scheduled to run nightly checks and actions.
- **Secure Configuration**: Uses `client_secret.json` for sensitive credentials, keeping them out of the codebase.

## Prerequisites

- **PHP 7.4+**
- **Composer**
- **Google Cloud Console Project**:
  - Enabled APIs: Google Drive API, Google Sheets API, YouTube Data API v3.
  - OAuth 2.0 Credentials (client ID & secret).
- **X (Twitter) Developer Account**:
  - API Key, Secret, Access Token, and Access Token Secret.

## Installation

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/alexanderthurn/shorty.git
    cd shorty
    ```

2.  **Install dependencies:**
    ```bash
    composer install
    ```

3.  **Configuration:**
    - Rename `client_secret.example.json` to `client_secret.json`.
    - Fill in your Google OAuth credentials in the `google` section.
    - Fill in your X (Twitter) credentials in the `x` section.
    - Configure the application settings in the `app` section:
        - `sheet_id`: ID of your Google Sheet.
        - `folder_id`: ID of the Google Drive folder containing videos.
        - `sheet_name`: Name of the tab in your Google Sheet (default: "Themen").
        - `playlist_id`: ID of the target YouTube playlist.
        - `nightly_mode`: Set to `ON`, `OFF`, or `MOCK`.
        - `nightly_x_active`: Enable/disable X posting (true/false).
        - `nightly_youtube_active`: Enable/disable YouTube upload (true/false).
        - `password`: SHA-256 hash of your desired upload password.

4.  **Google Authentication:**
    - Run the script once (e.g., via `php list.php`) or access the web interface.
    - Follow the prompts to authenticate with your Google account.
    - A `token.json` file will be created locally.

## Usage

### Web Interface

Open `index.html` (served via a web server) to interact with the dashboard:

- **Project Selector**: Choose from configured projects
- **List View**: Shows all videos with status badges
- **Nr Column**: Click to open Google Sheets at that row
- **Titel Column**: Click to view details (Key-Info, Final, Infos)
- **Bulk Actions**: Select multiple videos for batch operations

### CLI Commands

```bash
php list.php           # Lists videos from the configured Sheet/Drive
php nightly.php        # Runs the nightly automation tasks
php upload.php         # Handles video uploads
php refresh.php        # Refreshes YouTube metadata
php x_post.php         # Posts to X (Twitter)
```

## API Reference

### list.php

Returns video entries from Google Sheets combined with Drive file status.

**Base URL**: `list.php?project={projectId}`

#### Query Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `project` | string | *required* | Project ID from config |
| `detaillevel` | string | `basic` | `basic`: standard fields. `full`: includes keyInfo, infos, final |
| `startnr` | int | - | Filter: entries with `nr >= startnr` |
| `endnr` | int | - | Filter: entries with `nr <= endnr` |
| `startdate` | string | - | Filter: entries on/after this date (`YYYY-MM-DD`) |
| `enddate` | string | - | Filter: entries on/before this date |

#### Examples

```bash
# Get project list
list.php

# Get all entries for a project
list.php?project=myproject

# Get single entry with full details
list.php?project=myproject&detaillevel=full&startnr=42&endnr=42

# Get entries in date range
list.php?project=myproject&startdate=2026-01-15&enddate=2026-01-20
```

#### Response Fields

**Basic level** (default):
- `nr`, `titel`, `datum`, `hasMp4`, `hasSrt`, `isUploaded`
- `youtubeId`, `xTweetId`, `xMediaId`
- `sheetLink`, `mp4Link`, `srtLink`

**Full level** (additional):
- `keyInfo` - Key information (Column D)
- `infos` - Research notes (Column E)
- `final` - Final script (Column F)

## Multi-Project Support

Configure multiple projects in `client_secret.json`:

```json
{
  "projects": [
    {
      "id": "project1",
      "title": "Project One",
      "sheet_id": "...",
      "folder_id": "...",
      "playlist_id": "...",
      "start_date": "2026-01-01 21:21:00"
    },
    {
      "id": "project2",
      "title": "Project Two",
      ...
    }
  ],
  "app": {
    "default_project": "project1"
  }
}
```

## Security

- **Credentials**: Never commit `client_secret.json` or `token.json`. These are in `.gitignore`.
- **Passwords**: The upload password is stored as a SHA-256 hash.
- **`.htaccess`**: Blocks direct access to sensitive files.

## File Structure

```
shorty/
├── index.html          # Main dashboard UI
├── list.php            # API: List videos with filters
├── upload.php          # API: Upload to YouTube
├── refresh.php         # API: Refresh YouTube metadata
├── x_post.php          # API: Post to X (Twitter)
├── nightly.php         # Cron: Nightly automation
├── config.php          # Configuration loader
├── auth.php            # Google OAuth flow
├── config_editor.html  # Config editing UI
└── client_secret.json  # Credentials (not committed)
```

## License

[MIT License](LICENSE)