SQL{SELECT DISTINCT N.id FROM Notes N JOIN Properties P ON N.id = P.note_id WHERE P.name = 'done_at' AND P.value >= date('now', '-7 days') AND N.content LIKE 'DONE %' ORDER BY P.value DESC}
