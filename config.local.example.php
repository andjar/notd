<?php
// Copy to config.local.php and change what you need. config.local.php is never committed.
// Every setting can also come from the environment as NOTD_<KEY>, e.g. NOTD_DATA_DIR.
return [
    // Where the database and files live. $NOTD_DATA_DIR overrides it.
    'data_dir' => __DIR__ . '/data',

    // The password other devices log in with, as a hash. Set it with: php tools/password.php
    // 'password_hash' => '$2y$10$...',
];
