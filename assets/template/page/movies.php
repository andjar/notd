<?php
// Movie Page Template
// Contains multiple notes that will be created when this template is used
?>
[
    {
        "title": "Movie Details: {{movie_title}}",
        "content": "# Movie Details: {{movie_title}}\n\n**Director:** {{director}}\n**Release Year:** {{release_year}}\n**Genre:** {{genre}}\n**Runtime:** {{runtime_minutes}} minutes\n\n## Synopsis\n{{synopsis}}\n\n## Key Cast\n- {{actor1}}\n- {{actor2}}\n- {{actor3}}\n\n---\n*Page created on {{datetime}}*"
    },
    {
        "title": "My Review: {{movie_title}}",
        "content": "# My Review: {{movie_title}}\n\n**Rating (out of 5):** {{my_rating}}\n**Date Watched:** {{date_watched}}\n\n## Review Summary\n{{review_summary}}\n\n## Detailed Thoughts\n{{detailed_thoughts}}\n\n---\n*Review added on {{datetime}}*"
    },
    {
        "title": "Notes & Trivia: {{movie_title}}",
        "content": "# Notes & Trivia: {{movie_title}}\n\n## Production Notes\n{{production_notes}}\n\n## Interesting Trivia\n- {{trivia_fact_1}}\n- {{trivia_fact_2}}\n\n## Memorable Quotes\n- \"{{quote_1}}\"\n- \"{{quote_2}}\"\n\n---\n*Notes added on {{datetime}}*"
    }
] 