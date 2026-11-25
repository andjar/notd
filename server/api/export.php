<?php
// POST /api/v1/export: writes the Markdown export to the export folder (export_dir,
// default <data folder>/export) and says how many files changed.

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

return ['POST' => fn(Request $r): array => Markdown::export(Db::get(), Config::get('export_dir', Config::dataDir() . '/export'))
    + ['dir' => Config::get('export_dir', Config::dataDir() . '/export')]];
