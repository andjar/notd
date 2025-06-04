<?php
// Book Page Template
// Contains multiple notes that will be created when this template is used
?>
[
    {
        "title": "Book Information: {{book_title}}",
        "content": "# Book Information: {{book_title}}\n\n**Author:** {{author}}\n**Publication Year:** {{publication_year}}\n**Genre:** {{genre}}\n**ISBN:** {{isbn}}\n**Pages:** {{page_count}}\n\n## Plot Summary\n{{plot_summary}}\n\n---\n*Page created on {{datetime}}*"
    },
    {
        "title": "Author Details: {{author}}",
        "content": "# Author Details: {{author}}\n\n**Born:** {{author_dob}}\n**Nationality:** {{author_nationality}}\n\n## Notable Works\n- {{notable_work_1}}\n- {{notable_work_2}}\n\n## Brief Bio\n{{author_bio}}\n\n---\n*Information added on {{datetime}}*"
    },
    {
        "title": "My Review: {{book_title}}",
        "content": "# My Review: {{book_title}}\n\n**Rating (out of 5):** {{my_rating}}\n**Date Read:** {{date_read}}\n\n## Review\n{{review_text}}\n\n## Favorite Quotes\n- \"{{favorite_quote_1}}\"\n- \"{{favorite_quote_2}}\"\n\n---\n*Review added on {{datetime}}*"
    }
] 