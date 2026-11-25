<?php
// Used by runner_test.php: one passing test, one failing assertion, one error.
test('passes', function () { eq(1, 1); });
test('fails', function () { eq('a', 'b', 'letters'); });
test('errors', function () { throw new RuntimeException('boom'); });
