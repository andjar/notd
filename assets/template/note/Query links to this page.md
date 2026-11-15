SQL{SELECT DISTINCT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = 'links_to_page' AND P.value = '{{page}}' COLLATE NOCASE ORDER BY N.updated_at DESC}
