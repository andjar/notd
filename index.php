<?php
// Entry point: open today's journal page. The browser decides what "today"
// is (the server may be in another time zone); without JavaScript the
// server's date is used.
$serverToday = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>notd</title>
<script>
(function () {
    var d = new Date();
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    location.replace('page.php?page=' + d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()));
})();
</script>
<noscript><meta http-equiv="refresh" content="0; url=page.php?page=<?php echo $serverToday; ?>"></noscript>
</head>
<body></body>
</html>
