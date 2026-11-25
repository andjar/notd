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

notd 4 needs PHP 8.1 or newer with the `pdo_sqlite`, `mbstring` and `intl` extensions, and `git` for version history.

- **Apache**: point the site (or a folder of it) at the notd 4 folder. The included `.htaccess` sends everything through `index.php` and never serves the data, the server code or `config.local.php`.
- **nginx** with PHP-FPM:

  ```nginx
  location / { try_files $uri @notd; }
  location /client/ { }
  location @notd { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_pass unix:/run/php/php-fpm.sock; }
  location ~ ^/(data|server|tools|tests|actions)/ { return 404; }
  ```

- **Just this computer**: `php -S 127.0.0.1:8080 index.php`.

Reach it from your other devices over Tailscale, or a VPS with HTTPS; without a password notd answers only the computer it runs on.

## 3. Retire notd 3

When notd 4 is your notebook, the repository follows (this is for the owner of the repository):

```sh
git fetch origin
git push origin origin/main:refs/tags/v3-final     # keep notd 3 findable forever
git push origin origin/next:main --force-with-lease=main:origin/main   # notd 4 becomes main
```

The documentation site is published from `main`, so it switches to notd 4 at the same moment, and the nightly benchmark starts running. Keep notd 3's data folder until you are sure; nothing in notd 4 needs it.
