SQL{SELECT DISTINCT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name IN ('tag', 'tags') AND P.value = 'TAG' COLLATE NOCASE}
