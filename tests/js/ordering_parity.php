<?php
require __DIR__ . '/../../api/Ordering.php';
use App\Ordering;
$cases = json_decode(file_get_contents($argv[1]), true);
$bad = 0;
foreach ($cases as $c) {
    if ($c[0] === '__multi') {
        $got = Ordering::keysBetween($c[1], $c[2], $c[3]);
        if ($got !== $c[4]) { $bad++; echo "keysBetween mismatch\n"; }
        continue;
    }
    [$a, $b, $k] = $c;
    $got = Ordering::keyBetween($a, $b);
    if ($got !== $k) { if ($bad++ < 5) echo "mismatch: a=$a b=$b js=$k php=$got\n"; }
}
echo $bad === 0 ? "PHP parity ok\n" : "PHP parity FAILED: $bad\n";
exit($bad ? 1 : 0);
