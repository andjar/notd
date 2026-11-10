<?php
// Entry point: open today's journal page.
header('Location: page.php?page=' . urlencode(date('Y-m-d')));
exit;
