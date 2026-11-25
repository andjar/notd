---
title: "Protocol"
weight: 20
description: "The HTTP protocol between the notd server and any client. The examples on these pages are run as tests."
---

The browser app, plugins and scripts all talk to the notd server over this protocol, and nothing else. It is written down so that either side can be replaced: a new server passes if it passes the conformance tests in `tests/conformance/`, which include every example on these pages.

## Conventions

- Every endpoint lives under `/api/v1/`.
- Reads are `GET` with parameters in the query string. Writes are `POST` with a JSON object as the body. No other methods are used, so notd runs under minimal web servers.
- A successful response is status 200 with a JSON body.
- An error has a 4xx or 5xx status and a body of the form `{"error": {"code": "...", "message": "..."}}`. `code` is stable and meant for programs; `message` is for people.

| Code | Status | Meaning |
| --- | --- | --- |
| `bad_json` | 400 | The request body is not a JSON object. |
| `login_required` | 401 | A password is set and this request has no valid login cookie; see [login](login/). |
| `password_required`, `cross_origin` | 403 | Another device before a password is set, or a POST from another site. |
| `not_found` | 404 | No such endpoint, or no such thing. |
| `method_not_allowed` | 405 | The endpoint exists but not with this method. |
| `internal` | 500 | A bug or a broken installation; details are in the server log. |

## Examples as tests

An example is a `request` block followed by a `response` block. The request block starts with `GET /path` or `POST /path`, then an optional JSON body. The response block starts with the status, then an optional JSON body. The actual response must contain every key shown (extra keys are allowed), arrays must have the same length, and the string `"…"` stands for any value. Each page runs against a fresh, empty notebook, with its examples in order.

## Ping

`GET /api/v1/ping` says that the server is up and which protocol version it speaks.

```request
GET /api/v1/ping
```

```response
200
{"name": "notd", "version": "…", "protocol": 1}
```

Writing to it is not allowed:

```request
POST /api/v1/ping
```

```response
405
{"error": {"code": "method_not_allowed", "message": "…"}}
```

```request
GET /api/v1/no-such-endpoint
```

```response
404
{"error": {"code": "not_found", "message": "…"}}
```
