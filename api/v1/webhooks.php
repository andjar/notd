<?php
/**
 * /api/v1/webhooks.php — manage webhook subscriptions (see App\WebhooksManager).
 *
 *   GET                       list            GET ?id=N                  one webhook
 *   GET ?action=history&id=N  delivery log    POST                       create
 *   POST ?action=test&id=N    send test event POST ?action=verify&id=N   verify endpoint
 *   PUT ?id=N / DELETE ?id=N  update / delete (or POST with {"_method": "PUT"|"DELETE"})
 */

require_once __DIR__ . '/../WebhooksManager.php';

(new \App\WebhooksManager(get_db_connection()))->handleRequest();
