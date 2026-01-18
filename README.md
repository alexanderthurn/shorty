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

- **Web Interface**: Open `index.html` (served via a web server) to interact with the uploading tool.
- **CLI**:
    - `php list.php`: Lists videos from the configured Sheet/Drive.
    - `php nightly.php`: Runs the nightly automation tasks.
    - `php upload.php`: Handles video uploads (usually called via web interface or automation).
    - `php x_post.php`: Handles X posting.

## Security

- **Credentials**: Never commit `client_secret.json` or `token.json`. These are added to `.gitignore`.
- **Passwords**: The upload password is stored as a SHA-256 hash.

## License

[MIT License](LICENSE)