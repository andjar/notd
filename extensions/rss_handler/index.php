<?php
// The sidebar page of a command-line extension: it only explains how to run it.
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSS handler</title>
    <?php $assetBase = '../../'; require '../../assets/css/theme_loader.php'; ?>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <style>
        body { max-width: 640px; margin: 3em auto; padding: 0 1.5em; line-height: 1.6; }
        code, pre { background: var(--ls-secondary-background-color, #f3f3f3); border-radius: 4px; }
        pre { padding: 0.8em 1em; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>RSS handler</h1>
    <p>The RSS handler is a command-line script: it adds new items from the feeds in <code>extensions/rss_handler/config.json</code> as notes. Run it on the machine where notd runs, by hand or from a scheduler:</p>
    <pre><code>php extensions/rss_handler/rss_handler.php</code></pre>
    <p>How to set it up is described in the <a href="https://andjar.github.io/notd/docs/extensions/rss-handler/">documentation</a>.</p>
    <p><a href="../../">Back to notd</a></p>
</body>
</html>
