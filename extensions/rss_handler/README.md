# RSS Handler Extension

This extension fetches RSS feeds and appends new items as notes to your Notd outliner, using the API endpoint `append_to_page.php`. It is designed to run offline and can be scheduled (e.g., via cron or Task Scheduler) to keep your notes up to date with external RSS sources.

## Features
- Fetches and parses multiple RSS feeds
- Appends new items as notes to specified pages
- Prevents duplicate notes using a hash property
- Supports per-feed configuration for target page
- Dynamic page naming using `<today>` placeholder (replaced with current date)

## Configuration
Configuration is done via `rss_config.json` in the extension folder.

### Example `rss_config.json`
```json
{
  "api_url": "/api/v1",
  "default_page_name_for_rss": "Inbox/rss",
  "feeds": [
    "https://www.nrk.no/toppsaker.rss",
    { "url": "https://example.com/feed.xml", "page_name": "<today>/rss" },
    { "url": "https://another.com/feed", "page_name": "News" }
  ]
}
```

### Options
- `api_url`: Path or URL to your Notd API (relative or absolute)
- `default_page_name_for_rss`: Fallback page name for feeds that do not specify a `page_name`
- `feeds`: Array of feeds. Each feed can be:
  - A string (the feed URL), which will use `default_page_name_for_rss`
  - An object with:
    - `url`: The feed URL
    - `page_name`: (optional) The page to append notes to. Supports `<today>` placeholder, which is replaced with the current date in `YYYY-MM-DD` format (e.g., `2024-07-05/rss`).

## Usage
Run the handler from the command line:
```sh
php rss_handler.php
```

You can schedule this command to run periodically to keep your notes updated.

## Notes
- This script is intended for command-line use only. Web access will return an error.
- Requires the `SimplePie.php` library (included in this extension).
- Make sure your API endpoint and authentication (if any) are correctly configured. 