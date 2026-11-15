---
title: "Attachment Dashboard"
weight: 30
description: "Every file attached to a note, in one searchable and sortable list. On by default."
---

![The attachment dashboard: a search field, a type filter, and a table of files with name, size, type, date, the note it belongs to, a preview and a delete button](/images/attachment_dashboard.png)

Files you attach to notes (paste or drop them onto a note, or use **Upload attachment** in the note menu) are shown below the note they belong to. The attachment dashboard lists all of them in one place.

It is on by default; open it with the paperclip icon in the right sidebar.

- **Search** by file name with the field at the top; **filter** by file type with the menu next to it.
- **Sort** by clicking a column heading (name, size, type, date uploaded, note); click again to reverse.
- **Go to the note** a file belongs to by clicking its page in the **Note** column.
- **Open** a file with **View/Download**. Images also show a small preview.
- **Delete** a file with its **Delete** button (you are asked first). Under its note, the trash icon does the same.

Files are listed newest first, a page at a time; use **Previous** and **Next** at the bottom.

## Files without a note

Deleting a page deletes the files of its notes as well. Deleting a single note keeps its files, so nothing you uploaded disappears by accident: the dashboard shows them as *No note (deleted)*.

To clean up, tick **Only files without a note**. The dashboard then lists just those files and offers **Delete them all**:

![The dashboard with "Only files without a note" ticked: one file, and a "Delete them all" button](/images/attachment_dashboard_orphans.png)

Files are stored in `uploads/` (or the folder set with `UPLOADS_DIR`), sorted into year and month folders. Which file types are accepted, and the 10 MB limit, are described under [Attachments]({{< relref "../api-reference/attachments.md" >}}).
