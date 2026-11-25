<?php
// Runs waiting jobs. notd runs them after each answer anyway; run this from cron
// (every minute, say) if notd's web server cannot work after answering.
// Usage: php tools/jobs.php [seconds=50]

declare(strict_types=1);

require dirname(__DIR__) . '/server/core.php';
Plugins::load(Db::get());

$ran = Jobs::work(Db::get(), (float) ($argv[1] ?? 50));
echo "$ran job" . ($ran === 1 ? '' : 's') . " run\n";
