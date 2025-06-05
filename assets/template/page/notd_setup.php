<?php
// Notd Setup Page Template
// The first note loaded in the database
?>
[
    {
        "title": "Wellcome",
        "content": "Wellcome to notd! This is the first note loaded in the database. It will be used to guide you through the setup process."
    },
    {
        "title": "Keyboard Shortcuts",
        "content": ""
    },
    {
        "title": "Properties",
        "content": "Both pages and notes can have properties. Properties are key-value pairs that can be used to store additional information about the page or note. In notes, properties are defined in-text using the syntax `name::value`"
    },
    {
        "title": "Special Page Properties",
        "content": "- `alias`: Will redirect to the page with the given alias. If the alias is not set, the page will be loaded with the given name."
    },
    {
        "title": "Special Note Properties",
        "content": "- `internal`: If set to `true`, the note will not be shown in the page view. This is useful for notes that are used internally by the system.\n- `tag`: Special rendering. \n - `favorite`: If true, render as star."
    },
    {
        "title": "Trigger words",
        "content": "Most trigger words are related to task handling: `TODO`, `DONE`, `CANCELLED`"
    }
] 