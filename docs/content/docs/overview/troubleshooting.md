---
title: "Troubleshooting"
weight: 60
---

### Common Issues

1. **Database Locked Errors**
   - The API will retry automatically
   - If persistent, check for long-running transactions

2. **Property Not Found**
   - Properties are extracted from content automatically
   - Check that your content uses the correct syntax: `{key::value}`

3. **Search Not Working**
   - Ensure search terms are properly URL-encoded
   - Check that the content contains the search terms

4. **Template Processing Errors**
   - Verify template syntax
   - Check that placeholder names are valid

### Debug Mode

Enable debug logging by checking the server logs for detailed error information.
