<?php
// Sets the password that other devices need to use notd, by writing its hash to
// config.local.php. Usage: php tools/password.php   (asks for the password)
//                          php tools/password.php --remove

declare(strict_types=1);

require dirname(__DIR__) . '/server/core.php';

$file = Config::$file;
$config = is_file($file) ? (require $file) : [];
if (!is_array($config)) {
    fwrite(STDERR, "config.local.php does not return an array; set password_hash in it by hand.\n");
    exit(1);
}
if (in_array('--remove', $argv, true)) {
    unset($config['password_hash']);
} else {
    echo 'New password: ';
    system('stty -echo 2>/dev/null');
    $password = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\n";
    if (strlen($password) < 8) {
        fwrite(STDERR, "Use at least 8 characters.\n");
        exit(1);
    }
    $config['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
}
file_put_contents($file, "<?php\n// Local settings; never committed. See config.local.example.php.\nreturn " . var_export($config, true) . ";\n");
echo isset($config['password_hash']) ? "Password set; every device must log in again.\n" : "Password removed; only this computer can use notd now.\n";
