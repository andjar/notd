<?php
// The sidebar page of a command-line extension: it only explains how to run it.
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mail handler</title>
    <?php $assetBase = '../../'; require '../../assets/css/theme_loader.php'; ?>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <style>
        body { max-width: 640px; margin: 3em auto; padding: 0 1.5em; line-height: 1.6; }
        code, pre { background: var(--ls-secondary-background-color, #f3f3f3); border-radius: 4px; }
        pre { padding: 0.8em 1em; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Mail handler</h1>
    <p>The Mail handler is a command-line script: it adds an e-mail message to today's journal page. Run it on the machine where notd runs, by hand or from a scheduler:</p>
    <pre><code>php extensions/mail_handler/mail_handler.php &lt; message.eml</code></pre>
    <p>How to set it up is described in the <a href="https://andjar.github.io/notd/docs/extensions/mail-handler/">documentation</a>.</p>
    <p><a href="../../">Back to notd</a></p>
</body>
</html>
