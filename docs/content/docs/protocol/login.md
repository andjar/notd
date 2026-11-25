---
title: "Login"
weight: 45
description: "Who may talk to the server, and how a device logs in."
---

notd has one user and one password.

- **Without a password**, the server answers only requests from the computer it runs on. Any other request gets `403 password_required`. Requests that came through a proxy (they carry `X-Forwarded-For`, `Forwarded` or `X-Real-IP`) never count as local, because behind a proxy every request would look local.
- **With a password** (`php tools/password.php` on the server writes its hash to `config.local.php`), every endpoint except `ping`, `login` and `logout` needs the login cookie, from every device including the server itself. Without it: `401 login_required`.
- **POSTs from another site are refused** (`403 cross_origin`): when the request has an `Origin` header, its host must be the server's; otherwise `Sec-Fetch-Site` must not be `cross-site`. Scripts that send neither are fine.

`POST /api/v1/login` with `{"password": "..."}` sets the cookie `notd_login` for 30 days (HttpOnly, SameSite=Lax, and Secure over HTTPS). It is signed with a secret kept in the data folder together with the password hash, so changing the password logs every device out. A wrong password is `401 wrong_password`; after five wrong passwords from one address, that address is refused with `429 too_many_attempts` for 15 minutes. `POST /api/v1/logout` removes the cookie.

`GET /api/v1/ping` also says where a client stands: `passwordSet`, `local` and `authenticated`.

```request
GET /api/v1/ping
```

```response
200
{"name": "notd", "protocol": 1, "passwordSet": false, "local": true, "authenticated": true}
```

```request
POST /api/v1/login
{"password": "anything"}
```

```response
400
{"error": {"code": "no_password", "message": "No password is set."}}
```
