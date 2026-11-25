<?php
require_once __DIR__ . '/support/server.php';

test('the PHP that runs the tests has every extension notd needs', function () {
    Http::requireExtensions();
    eq(['pdo_sqlite', 'mbstring', 'intl', 'fileinfo'], NOTD_EXTENSIONS);
});

test('missing PHP extensions are named, with the php.ini lines that add them', function () {
    $e = throws(fn() => Http::requireExtensions(['mbstring', 'no_such_ext', 'json', 'nor_this']), HttpError::class);
    eq(500, $e->status);
    eq('php_extensions', $e->codeName);
    eq('PHP lacks extensions notd needs: no_such_ext, nor_this. Add extension=no_such_ext, extension=nor_this to php.ini and start notd again.',
        $e->getMessage());
});
