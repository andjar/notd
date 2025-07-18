---
title: "Troubleshooting"
weight: 70
---

Common issues and solutions for notd frontend features.

## Search Issues

### Search Not Finding Content

**Problem:** Global search returns no results for content you know exists.

**Solutions:**
1. **Check encryption status** - Encrypted content requires the page password
2. **Verify content is saved** - Ensure changes were saved before searching  
3. **Check property syntax** - Properties must use correct syntax: `{key::value}`
4. **Search different terms** - Try broader or alternative keywords

**Example Fix:**
```markdown
# If searching for: "project planning"
# Try instead: "project" or "planning" separately
# Or check if content uses different terms like "project preparation"
```

### Search Results Missing Context

**Problem:** Search results don't show enough context around matching terms.

**Solutions:**
1. **Use longer content blocks** - Avoid very short notes
2. **Include keywords in context** - Mention key terms near matching content
3. **Use descriptive titles** - Make page and note titles searchable

### Property Search Not Working

**Problem:** Searching `priority::high` doesn't find expected results.

**Solutions:**
1. **Check property syntax** - Must be exactly `{priority::high}` in content
2. **Verify property values** - Check for typos like `{priority::hi}` vs `{priority::high}`
3. **Use SQL for complex searches** - Property searches are exact matches

**SQL Alternative:**
```sql
SQL{
  SELECT N.content, P.name 
  FROM Notes N 
  JOIN Pages P ON N.page_id = P.id
  JOIN Properties Prop ON N.id = Prop.note_id
  WHERE Prop.name = 'priority' 
  AND (Prop.value = 'high' OR Prop.value = 'urgent')
}
```

## Page Linking Issues

### Links Not Creating Pages

**Problem:** Clicking `[[New Page]]` doesn't create the page.

**Solutions:**
1. **Check permissions** - Ensure write access to database
2. **Verify syntax** - Use exactly `[[Page Name]]` format
3. **Clear browser cache** - Old JavaScript might be cached
4. **Check for special characters** - Avoid characters like `<>|?*` in page names

### Backlinks Not Appearing

**Problem:** Backlinks don't show even though pages link to current page.

**Solutions:**
1. **Refresh the page** - Backlinks may need a refresh to update
2. **Check link syntax** - Links must use `[[Page Name]]` format exactly
3. **Verify page names match** - Case-sensitive matching required
4. **Wait for processing** - Large numbers of links may take time to process

## Task Management Issues

### Tasks Not Rendering Correctly

**Problem:** Tasks show as plain text instead of formatted task items.

**Solutions:**
1. **Check task keywords** - Must start line with: TODO, DOING, DONE, etc.
2. **Verify spacing** - Need space after keyword: `TODO task` not `TODOtask`
3. **Case sensitivity** - Keywords must be uppercase: `TODO` not `todo`
4. **Line position** - Task keywords must be at start of line

**Correct Format:**
```markdown
TODO Complete project proposal {priority::high}
DOING Review documentation {assigned::team}
DONE Set up development environment {completed::2024-07-18}
```

### Task Properties Not Working

**Problem:** Task properties don't filter correctly in Kanban or queries.

**Solutions:**
1. **Check property syntax** - Must use `{key::value}` format
2. **Verify property names** - Check for typos in property names
3. **Check value matching** - Values are case-sensitive
4. **Use consistent values** - Establish standard property values team-wide

## SQL Query Issues

### SQL Queries Not Executing

**Problem:** SQL queries show as plain text instead of executing.

**Solutions:**
1. **Check syntax** - Must use `SQL{query}` format exactly
2. **Verify query validity** - Test queries for SQL syntax errors
3. **Check permissions** - Ensure database access is available
4. **Browser security** - Some browsers may block SQL execution

**Valid SQL Format:**
```sql
SQL{SELECT name FROM Pages WHERE updated_at > DATE('now', '-7 days')}
```

### SQL Results Not Displaying

**Problem:** SQL queries execute but don't show results.

**Solutions:**
1. **Check query logic** - Verify query returns data
2. **Add LIMIT clause** - Large results may not display fully
3. **Simplify query** - Complex joins might timeout
4. **Check data exists** - Query might be correct but no matching data

**Debug Query:**
```sql
-- Start with simple query to verify data exists
SQL{SELECT COUNT(*) as total_pages FROM Pages}

-- Then add conditions step by step
SQL{SELECT COUNT(*) as recent_pages FROM Pages WHERE updated_at > DATE('now', '-7 days')}
```

## Encryption Issues

### Cannot Decrypt Content

**Problem:** Encrypted content shows as `[DECRYPTION FAILED]` or similar.

**Solutions:**
1. **Check password** - Ensure correct password is entered
2. **Verify encryption property** - Page should have `{encrypted::true}`
3. **Browser session** - Try refreshing page and re-entering password
4. **Content integrity** - Encryption may be corrupted if partially modified

### Password Prompts Not Appearing

**Problem:** Encrypted pages don't prompt for password.

**Solutions:**
1. **Check page properties** - Must have `{encrypted::true}` property
2. **Clear browser cache** - Cached pages might not show prompts
3. **Verify JavaScript** - Ensure JavaScript is enabled
4. **Browser compatibility** - Try different browser

## Transclusion Issues

### Transclusion Content Not Loading

**Problem:** `{{transclude:Page Name}}` shows as plain text or loading placeholder.

**Solutions:**
1. **Check page name** - Must match exactly, case-sensitive
2. **Verify page exists** - Target page must exist
3. **Check syntax** - Must use `{{transclude:Page Name}}` format exactly
4. **Content permissions** - Ensure access to target page

### Circular Transclusion

**Problem:** Page A transcludes Page B which transcludes Page A, causing errors.

**Solutions:**
1. **Review transclusion chain** - Map out which pages transclude which
2. **Break circular references** - Remove one of the transclusions
3. **Use alternative approach** - Consider page links instead of transclusion
4. **Create shared reference page** - Both pages can transclude a third page

## Extension Issues

### Extensions Not Loading

**Problem:** Extensions don't appear in menu or don't open.

**Solutions:**
1. **Check extension directory** - Verify extension files exist
2. **Verify configuration** - Check `config.json` files for errors
3. **Browser compatibility** - Some extensions require modern browsers
4. **JavaScript errors** - Check browser console for errors

### Kanban Board Not Updating

**Problem:** Moving tasks in Kanban board doesn't update notes.

**Solutions:**
1. **Check task format** - Tasks must use proper keywords (TODO, DOING, etc.)
2. **Verify properties** - Board filters must match task properties
3. **Refresh board** - Try reloading the Kanban extension
4. **Check permissions** - Ensure write access to notes

### Excalidraw Drawings Not Saving

**Problem:** Drawings don't save as attachments.

**Solutions:**
1. **Check note ID** - Extension must be launched with valid note ID
2. **Verify attachments API** - Ensure attachment upload is working
3. **Browser permissions** - Check file access permissions
4. **File size limits** - Large drawings might exceed limits

## Performance Issues

### Slow Search Performance

**Problem:** Search takes too long to return results.

**Solutions:**
1. **Limit search scope** - Use more specific search terms
2. **Use properties** - Property-based searches are faster
3. **Add LIMIT to SQL** - Limit query results to reasonable numbers
4. **Check database size** - Very large databases may need optimization

### Slow Page Loading

**Problem:** Pages take too long to load.

**Solutions:**
1. **Reduce note count** - Pages with many notes load slower
2. **Optimize images** - Large attachments slow page loading
3. **Simplify SQL queries** - Complex queries can slow rendering
4. **Clear browser cache** - Cached data might be corrupted

## Browser Compatibility

### Features Not Working in Specific Browsers

**Problem:** Some features work in Chrome but not Firefox/Safari.

**Solutions:**
1. **Update browser** - Ensure latest version
2. **Enable JavaScript** - All modern features require JavaScript
3. **Check extensions** - Browser extensions might interfere
4. **Try private/incognito mode** - Rule out extension conflicts

### Mobile Interface Issues

**Problem:** Interface doesn't work well on mobile devices.

**Solutions:**
1. **Use landscape mode** - Some features work better in landscape
2. **Zoom appropriately** - Adjust zoom for better touch targets
3. **Use simplified features** - Focus on core features on mobile
4. **Consider desktop** - Complex workflows better suited for desktop

## Database Issues

### Connection Problems

**Problem:** "Database connection failed" or similar errors.

**Solutions:**
1. **Check file permissions** - Database file must be writable
2. **Verify database path** - Check `config.php` for correct path
3. **Database corruption** - Backup and restore from known good backup
4. **Disk space** - Ensure sufficient disk space for database

### Data Inconsistency

**Problem:** Notes appear in some places but not others.

**Solutions:**
1. **Refresh browser** - Force reload of cached data
2. **Check for duplicates** - Multiple notes with same content
3. **Verify relationships** - Ensure proper page-note relationships
4. **Database maintenance** - May need database integrity check

## Getting Help

### Diagnostic Information

When reporting issues, include:

1. **Browser and version** - Chrome 91, Firefox 89, etc.
2. **Error messages** - Copy exact error text
3. **Steps to reproduce** - What you did before the problem occurred
4. **Expected vs actual behavior** - What should happen vs what does happen
5. **Console errors** - Check browser developer console for errors

### Debug Mode

Enable debug information:
1. Open browser developer tools (F12)
2. Check Console tab for error messages
3. Check Network tab for failed requests
4. Check Application/Storage tab for cached data issues

### Community Resources

- **GitHub Issues** - Report bugs and feature requests
- **Documentation** - Check other sections for related information
- **Examples** - Look at working examples for proper syntax

Most issues can be resolved by checking syntax, verifying configuration, and ensuring proper permissions.