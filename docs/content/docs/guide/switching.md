---
title: "Switching from notd 3"
weight: 95
description: "Moving your notes to notd 4 and retiring notd 3: the milestone of the rewrite."
---

notd 4 is ready to replace notd 3 when it does what you need every day. Try it first beside notd 3 ([Trying notd 4 beside notd 3](../staging/)); when nothing gets in the way any more, switch. Nothing below touches notd 3's database, so you can always go back.

## 1. Move your notes

1. In notd 3, export your notes as Markdown, as for staging.
2. On the server, get notd 4 into a folder of its own (the `next` branch, or `main` once it has been switched, see step 3):

   ```sh
   git clone --branch next https://github.com/andjar/notd.git notd4 && cd notd4
   ```

3. Import, check, and set the password other devices log in with:

   ```sh
   php tools/import.php /path/to/the/export     # into data/, or the data_dir in config.local.php
   php tools/verify.php                          # every stored file is there and intact
   php tools/password.php
   ```

   The import prints what it could not take over (an `.org` file, a link to a missing image). Open a few pages you know well and compare.

## 2. Serve it

notd 4 needs PHP 8.1 or newer with the `pdo_sqlite`, `mbstring`, `intl` and `fileinfo` extensions, and `git` for version history. If one is missing, notd says which, both in the browser and from the tools.

- **Apache**: point the site (or a folder of it) at the notd 4 folder. The included `.htaccess` sends everything through `index.php` and never serves the data, the server code or `config.local.php`.
- **nginx** with PHP-FPM:

  ```nginx
  location / { try_files $uri @notd; }
  location /client/ { }
  location @notd { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_pass unix:/run/php/php-fpm.sock; }
  location ~ ^/(data|server|tools|tests|actions)/ { return 404; }
  ```

- **Just this computer**: `php -S 127.0.0.1:8080 index.php`.
- **phpdesktop**: see below.

### phpdesktop

[phpdesktop](https://github.com/cztomczak/phpdesktop) turns notd into a Windows program for this computer. Three things differ from a normal web server:

1. **Requests that are not files.** The protocol's addresses (`/api/v1/…`) are not files, and phpdesktop answers them with 404 unless they go to notd. In `settings.json`, under `web_server`, set `"www_directory": "www"`, with the notd 4 folder's contents in phpdesktop's `www` folder, and set `"404_handler": "/index.php"`. Without the handler, notd stops at start-up and says so.
2. **PHP extensions.** phpdesktop's `php/php.ini` loads only some extensions. Add these lines below the other `extension=` lines:

   ```ini
   extension=php_mbstring.dll
   extension=php_intl.dll
   extension=php_fileinfo.dll
   ```

   `php_pdo_sqlite.dll` is already there. Lines for extensions that PHP 8 no longer has (`php_gd2.dll`, `php_mysql.dll`) can go.
3. **Your notes.** phpdesktop's web server hands out any file in `www` as it is and ignores `.htaccess`, so keep the data folder outside it. Copy `config.local.example.php` to `config.local.php` and set `'data_dir' => 'C:/Users/you/notd-data'`.

Reach it from your other devices over Tailscale, or a VPS with HTTPS; without a password notd answers only the computer it runs on.

## 3. Retire notd 3

When notd 4 is your notebook, the repository follows (this is for the owner of the repository):

```sh
git fetch origin
git push origin origin/main:refs/tags/v3-final     # keep notd 3 findable forever
git push origin origin/next:main --force-with-lease=main:origin/main   # notd 4 becomes main
```

The documentation site is published from `main`, so it switches to notd 4 at the same moment, and the nightly benchmark starts running. Keep notd 3's data folder until you are sure; nothing in notd 4 needs it.
