---
title: "Espanso: Clipboard to Journal"
weight: 90
description: "Copy text anywhere, type ;notd, and it is a note on today's journal page."
---

![A note made from copied text, with the property source::espanso](/images/espanso.png)

[Espanso](https://espanso.org/) is a free text expander for Windows, macOS and Linux: you type a short trigger and it replaces it, in any program. notd comes with an Espanso configuration that turns copied text into a note:

1. Copy some text (a quote, a link, an idea from a chat).
2. Type `;notd` anywhere you can type.
3. The text is added as a new note at the end of today's journal page, with `{source::espanso}`. The `;notd` you typed disappears.

Nothing is typed when it works. When the clipboard is empty or notd cannot be reached, a short message such as `[notd: could not save to http://localhost:8000]` is typed where you are instead, so a failure never goes unnoticed.

The property makes the collected snippets easy to find, for example on a page of their own:

```text
SQL{SELECT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = 'source' AND P.value = 'espanso'}
```

## Setting it up

1. [Install Espanso](https://espanso.org/install/).
2. Copy the file for your system into Espanso's `match` folder (`espanso path` shows where Espanso keeps its configuration; the folder is `match` inside it):
   - macOS and Linux: [`tools/espanso/notd.yml`](https://github.com/andjar/notd/blob/main/tools/espanso/notd.yml) (needs `bash` and `curl`, which these systems have)
   - Windows: [`tools/espanso/notd-windows.yml`](https://github.com/andjar/notd/blob/main/tools/espanso/notd-windows.yml) (uses Windows PowerShell)
3. If notd does not run at `http://localhost:8000`, change the address on the first line of the command (`NOTD=` or `$notd =`).

Espanso picks up the file by itself. To use another trigger, change `";notd"`.

## How it works

The configuration asks Espanso for the clipboard and runs a short command that sends it to notd's [`append_to_page.php`]({{< relref "../api-reference/append-to-page.md" >}}) with today's date as the page name, the same endpoint the other extensions use. The clipboard text is handed to the command in an environment variable and never pasted into the command itself, so copied text that looks like a command is stored as text and never run.

- A one-line note gets the property at the end of the line; a longer note gets it on a line of its own, so text that ends with a code block stays intact.
- "Today" is the date on the computer that runs Espanso.
- If notd is behind HTTP authentication, add `-u user:password` to the `curl` line.

The macOS and Linux version:

```yaml
matches:
  - trigger: ";notd"
    replace: "{{result}}"
    vars:
      - name: clip
        type: clipboard
      - name: result
        type: shell
        params:
          shell: bash
          cmd: |
            NOTD="http://localhost:8000"

            # The clipboard arrives as $ESPANSO_CLIP; it is never pasted into the command.
            text=$(printf '%s' "$ESPANSO_CLIP" | tr -d '\r')
            if [ -z "$(printf '%s' "$text" | tr -d '[:space:]')" ]; then
              echo "[notd: the clipboard is empty]"; exit 0
            fi
            # The property goes on the same line for one-line notes, on its own line otherwise.
            case "$text" in
              *$'\n'*) note="$text"$'\n''{source::espanso}' ;;
              *)       note="$text {source::espanso}" ;;
            esac
            # JSON string escaping with standard tools: \ " tab, line breaks; other control characters dropped.
            tab=$(printf '\t')
            json=$(printf '%s' "$note" \
              | tr -d '\000-\010\013\014\016-\037' \
              | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e "s/$tab/\\\\t/g" \
              | awk 'NR > 1 { printf "\\n" } { printf "%s", $0 }')
            body="{\"page_name\":\"$(date +%Y-%m-%d)\",\"notes\":\"$json\"}"

            if ! curl -sf -m 10 -H 'Content-Type: application/json' --data-binary "$body" \
                 "$NOTD/api/v1/append_to_page.php" > /dev/null; then
              echo "[notd: could not save to $NOTD]"
            fi
```

The Windows version does the same in PowerShell; see [`tools/espanso/notd-windows.yml`](https://github.com/andjar/notd/blob/main/tools/espanso/notd-windows.yml).
