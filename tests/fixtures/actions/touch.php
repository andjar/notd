<?php
// Test action: a block tagged #touch makes a job write a file to the data folder.
return ['on' => 'created', 'match' => 'tag:touch',
        'do' => fn(array $node, Actions $notd) => $notd->run([PHP_BINARY, '-r', 'file_put_contents($argv[1], $argv[2]);',
            Config::dataDir() . '/touched', $node['text']], 'touch')];
