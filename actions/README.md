# Your actions

Put your own actions here: small PHP files that run when notes change. They are
yours and stay out of the core. See "Actions" in the plugin docs:
docs/content/docs/plugins/_index.md

    <?php
    // actions/movie-needs-rating.php: tag every movie that has no rating yet
    return ['on' => 'saved', 'match' => 'type:movie -has:rating',
            'do' => fn(array $node, Actions $notd) => $notd->addTag($node, 'needs-rating')];
