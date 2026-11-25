---
title: "Trying notd 4 beside notd 3"
weight: 90
description: "Run notd 4 on a copy of your notes while notd 3 stays in use."
---

Until notd 4 is good enough for daily use, notd 3 stays your notebook. To try notd 4 on your own notes without risking them:

1. In notd 3, export your notes as Markdown. You get a folder with `journals/` and `pages/`, in the same layout Logseq uses.
2. In the notd 4 folder (the `next` branch), run:

   ```sh
   sh tools/staging.sh /path/to/the/export
   ```

   It imports the export into `staging-data/`, a data folder of its own, and starts notd 4 on <http://127.0.0.1:8090/>. notd 3 and its database are never touched. Give a port as the second argument to use another one.
3. Use it for real for a while. Whatever you write there stays in `staging-data/`; it does not flow back to notd 3.

To start over with a fresh export, add `--fresh`: `sh tools/staging.sh /path/to/the/export 8090 --fresh` deletes `staging-data/` first.

What the import understands, and what it reports, is described under [Markdown export and import](../../protocol/export/#import). The browser test `tests/browser/staging_test.py` imports a sample Logseq graph the same way and opens every page, so a page that fails to draw is caught before you meet it.

When something gets in the way in daily use, it becomes a step: notd 4 replaces notd 3 at the milestone, once tasks and git history are in (phase 3).
