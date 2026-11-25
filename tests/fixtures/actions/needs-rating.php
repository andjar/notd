<?php
// Test action: tag every movie that has no rating yet.
return ['on' => 'saved', 'match' => 'type:movie -has:rating',
        'do' => fn(array $node, Actions $notd) => $notd->addTag($node, 'needs-rating')];
