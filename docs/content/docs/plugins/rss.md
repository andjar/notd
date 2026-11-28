---
title: "RSS"
weight: 25
description: "Feeds in notes: new items arrive under the block that names the feed."
---

The RSS plugin reads feeds (RSS 2.0, RSS 1.0 and Atom) and adds their new items to your notes. A feed is any block with a `feed` field:

```text
Garden blog {feed:: https://example.org/feed.xml} {view:: group:published:day sort:-published}
```

**RSS feed** in the slash menu writes both fields for you. Put the block wherever you like: a page called Feeds, a project's page, today's journal.

## What arrives

Each new item becomes a block under the feed's block:

```text
[Sowing peas in November](https://example.org/peas) {published:: 2026-11-25 09:00}
Peas can go in now, under glass.
```

The title links to the item, `published` is when it was published (on the server's clock, see `timezone` in the [guide](../../guide/#views-showing-a-blocks-children-another-way); for an item without a date, when it was read), and the second line is the start of its text (up to 280 characters, as plain text). The items are ordinary blocks: edit them, move them, link to them, or find them with a query such as `{{query published>=2026-11-01}}`.

## Days and hours

The items are kept as a plain list under the feed. How they are shown is up to the feed block's [view](../../guide/#views-showing-a-blocks-children-another-way):

```text
{view:: group:published:day sort:-published}     a heading per day, newest first (what /RSS feed writes)
{view:: group:published:hour sort:-published}    a heading per day, and under it one per hour
{view:: group:published:month sort:-published}   a heading per month, for a quiet feed
```

Without a view, the items are shown as blocks, newest first. Click the feed's bullet to see them that way at any time. A query can also show several feeds together, by day: `{{query has:published group:published:day sort:-published}}`.

Every item is added once. notd remembers, for each feed, what it has seen (the last 1,000 items), so an item you delete does not come back. The first time a feed is read, only its newest 20 items are added; after that, up to 50 new ones at a time.

## When feeds are read

- As soon as a block with a feed address is saved, and again when the address changes.
- Then every hour, while notd is open somewhere (or `tools/jobs.php` runs from cron). `rss_every` in the config sets another interval, in minutes.
- **Read this feed now** in a block's menu, or **Read all feeds now** in the command palette, reads at once and says how many new items came in, or why a feed could not be read.

Feeds are fetched by the server notd runs on, so it needs to reach the internet: PHP's `allow_url_fopen` (on by default) or the curl extension. Only `http://` and `https://` addresses are read.
