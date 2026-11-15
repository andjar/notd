---
title: "Zen Writer"
weight: 40
description: "A distraction-free, full-screen editor for one note. Turn it on with ACTIVE_EXTENSIONS."
---

![The Zen writer: the text of one note in a calm full-screen editor, with save and theme buttons in the corner](/images/zen.png)

The Zen writer opens one note in a full-screen editor with nothing else on the screen: good for longer pieces of writing that would feel cramped in the outline.

## Turning it on

Add `zen` to `ACTIVE_EXTENSIONS` in `config.local.php` (see [Turning extensions on and off]({{< relref "_index.md#turning-extensions-on-and-off" >}})). It then appears in the note menu.

## Using it

1. Right-click a note's bullet and choose **Open in Zen Writer**. The note opens in a new tab.
2. Write. The editor highlights Markdown as you type.
3. Save with `Ctrl + S` (`Cmd + S` on a Mac) or the save button in the top right corner.

The moon/sun button switches between a light and a dark theme; the choice is remembered in the browser.

Saving replaces the note's text, the same way an edit in the outline does, and notd tabs that show the note update by themselves.

Editing the same note in two places is safe:

- If the note is changed in notd while you have nothing unsaved in the Zen writer, the Zen writer takes the new text.
- If it was changed elsewhere since you opened or last saved it, saving asks first. **OK** replaces that change with your text; **Cancel** saves nothing and keeps your text in the editor, so you can copy what you need.
- Closing the tab with unsaved text asks for confirmation.

Opened without a note (`extensions/zen/index.html`), the editor works as a scratch pad that is saved in the browser only.

## Themes

`defaultLightTheme` and `defaultDarkTheme` in `extensions/zen/config.json` name a theme from `extensions/zen/codemirror/themes/` (without `.css`).
