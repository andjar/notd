---
title: "Backlinks & Favorites"
weight: 30
---

## Backlinks

When a note contains `[[Page Name]]`, the page "Page Name" lists that note as a backlink. You never maintain backlinks; they follow from the links you write.

Backlinks appear:

- in the **right sidebar**, with the note that contains the link,
- in the **backlinks window** (`Ctrl + B`), which shows each linking note in context.

Pages with many backlinks tend to be the hubs of your notes. A common pattern is to write in the journal and link to the topics you mention; each topic page then collects everything said about it, with dates.

## Favorites

Mark a page as a favorite by adding this to its page properties (gear icon next to the title):

```markdown
{favorite::true}
```

Favorites are listed at the top of the right sidebar. Remove the property to remove the page from the list.

## Namespaces and child pages

A `/` in a page name creates a hierarchy:

```markdown
[[Projects/Alpha]]
[[Projects/Alpha/Meetings]]
```

The page `Projects` lists `Projects/Alpha` as a child page, and the title of a namespaced page links to each of its parents.
