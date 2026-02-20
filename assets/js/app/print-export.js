/**
 * Print and Export Functionality
 * Handles PDF printing and HTML export
 */

/**
 * Prepare page for printing
 * Expands all collapsed notes and ensures content is visible
 */
function preparePrintView() {
    // Expand all collapsed notes
    const collapsedNotes = document.querySelectorAll('.note-item.collapsed');
    collapsedNotes.forEach(note => {
        note.classList.remove('collapsed');
    });

    // Expand all collapsed children containers
    const collapsedChildren = document.querySelectorAll('.note-children.collapsed');
    collapsedChildren.forEach(container => {
        container.classList.remove('collapsed');
        container.style.display = 'block';
        container.style.maxHeight = 'none';
        container.style.opacity = '1';
    });

    // Ensure all arrows show expanded state
    const collapseArrows = document.querySelectorAll('.note-collapse-arrow');
    collapseArrows.forEach(arrow => {
        arrow.setAttribute('data-collapsed', 'false');
    });

    // Store original state for restoration
    return {
        collapsedNotes: Array.from(collapsedNotes).map(n => n.dataset.noteId),
        collapsedChildren: Array.from(collapsedChildren)
    };
}

/**
 * Restore page state after printing
 */
function restorePrintView(savedState) {
    if (!savedState) return;

    // Restore collapsed notes
    savedState.collapsedNotes.forEach(noteId => {
        const note = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
        if (note) {
            note.classList.add('collapsed');
        }
    });

    // Restore collapsed children
    savedState.collapsedChildren.forEach(container => {
        container.classList.add('collapsed');
    });
}

/**
 * Open browser print dialog
 */
function printPage() {
    const savedState = preparePrintView();
    
    // Small delay to ensure DOM updates are rendered
    setTimeout(() => {
        window.print();
        
        // Restore state after print dialog closes
        setTimeout(() => {
            restorePrintView(savedState);
        }, 100);
    }, 100);
}

/**
 * Export page as standalone HTML file
 */
async function exportAsHTML() {
    try {
        const savedState = preparePrintView();
        
        // Get page name
        const urlParams = new URLSearchParams(window.location.search);
        const pageName = urlParams.get('page') || 'untitled';
        
        // Build complete HTML document
        const htmlContent = await buildExportHTML(pageName);
        
        // Create blob and download
        const blob = new Blob([htmlContent], { type: 'text/html;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${sanitizeFilename(pageName)}.html`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        // Restore state
        restorePrintView(savedState);
        
        console.log('✓ Page exported as HTML');
    } catch (error) {
        console.error('Error exporting as HTML:', error);
        alert('Failed to export page as HTML. Please try again.');
    }
}

/**
 * Build complete HTML document for export
 */
async function buildExportHTML(pageName) {
    // Get current page title
    const pageTitle = document.querySelector('.page-title')?.textContent || pageName;
    
    // Get page properties
    const pageProperties = document.querySelector('.page-properties')?.innerHTML || '';
    
    // Get notes content
    const notesContainer = document.getElementById('notes-container');
    const notesHTML = notesContainer ? notesContainer.innerHTML : '';
    
    // Get inline CSS (including print styles)
    const cssLinks = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map(link => link.href)
        .filter(href => !href.includes('font-awesome') && !href.includes('font-ubuntu'));
    
    // Build HTML
    return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${escapeHtml(pageTitle)}</title>
    <meta name="generator" content="notd - note outliner">
    <meta name="export-date" content="${new Date().toISOString()}">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <style>
/* Base Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Ubuntu', 'Segoe UI', Arial, sans-serif;
    font-size: 11pt;
    line-height: 1.6;
    color: #000;
    background: white;
    padding: 2cm;
    max-width: 21cm;
    margin: 0 auto;
}

/* Page Header */
.page-header {
    margin-bottom: 1.5cm;
    padding-bottom: 0.5cm;
    border-bottom: 2px solid #333;
}

.page-title {
    font-size: 20pt;
    font-weight: 600;
    margin: 0 0 0.3cm 0;
    line-height: 1.3;
}

.page-properties {
    margin-top: 0.3cm;
    font-size: 9pt;
    color: #555;
}

/* Notes Structure */
.notes-content {
    margin-top: 1cm;
}

.note-item {
    margin-bottom: 0.3cm;
    position: relative;
}

.note-header-row {
    display: flex;
    align-items: flex-start;
}

.note-controls {
    display: flex;
    align-items: flex-start;
    margin-right: 0.3cm;
    flex-shrink: 0;
}

.note-bullet {
    display: inline-block;
    width: 0.15cm;
    height: 0.15cm;
    background: #333;
    border-radius: 50%;
    margin-top: 0.25cm;
}

.note-content-wrapper {
    flex: 1;
    min-width: 0;
}

.note-content {
    font-size: 10pt;
    line-height: 1.6;
    word-wrap: break-word;
}

/* Nesting */
.note-children {
    margin-left: 0.8cm;
}

/* Typography */
.note-content h1 {
    font-size: 16pt;
    font-weight: 600;
    margin: 0.4cm 0 0.2cm 0;
    border-bottom: 2px solid #333;
    padding-bottom: 0.1cm;
}

.note-content h2 {
    font-size: 14pt;
    font-weight: 600;
    margin: 0.4cm 0 0.2cm 0;
    border-bottom: 1px solid #666;
    padding-bottom: 0.1cm;
}

.note-content h3 {
    font-size: 12pt;
    font-weight: 600;
    margin: 0.4cm 0 0.2cm 0;
}

.note-content h4,
.note-content h5,
.note-content h6 {
    font-size: 10pt;
    font-weight: 600;
    margin: 0.3cm 0 0.2cm 0;
}

.note-content p {
    margin: 0.2cm 0;
}

/* Links */
.note-content a {
    color: #0066cc;
    text-decoration: underline;
}

/* Lists */
.note-content ul,
.note-content ol {
    margin: 0.2cm 0 0.2cm 0.5cm;
    padding-left: 0.5cm;
}

.note-content li {
    margin: 0.1cm 0;
}

/* Code */
.note-content pre {
    background: #f5f5f5;
    border: 1px solid #ccc;
    border-radius: 0.1cm;
    padding: 0.3cm;
    margin: 0.3cm 0;
    font-size: 8pt;
    overflow-x: auto;
    white-space: pre-wrap;
}

.note-content code {
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 0.1cm;
    padding: 0.05cm 0.15cm;
    font-size: 9pt;
    font-family: 'Courier New', monospace;
}

.note-content pre code {
    border: none;
    background: transparent;
    padding: 0;
}

/* Blockquotes */
.note-content blockquote {
    border-left: 3px solid #999;
    margin: 0.3cm 0 0.3cm 0.5cm;
    padding-left: 0.4cm;
    color: #333;
    font-style: italic;
}

/* Images */
.note-content img {
    max-width: 100%;
    height: auto;
    margin: 0.3cm 0;
    border: 1px solid #ddd;
}

/* Tables */
.note-content table {
    width: 100%;
    border-collapse: collapse;
    margin: 0.3cm 0;
    font-size: 9pt;
}

.note-content th,
.note-content td {
    border: 1px solid #999;
    padding: 0.2cm;
    text-align: left;
}

.note-content th {
    background: #f0f0f0;
    font-weight: 600;
}

/* Tasks */
.task-container {
    display: flex;
    align-items: flex-start;
    margin: 0.1cm 0;
}

.task-container.todo::before {
    content: "☐ ";
    font-size: 12pt;
    margin-right: 0.2cm;
}

.task-container.done::before {
    content: "☑ ";
    font-size: 12pt;
    margin-right: 0.2cm;
}

.task-container.doing::before {
    content: "◐ ";
    font-size: 12pt;
    margin-right: 0.2cm;
}

/* Footer */
.export-footer {
    margin-top: 2cm;
    padding-top: 0.5cm;
    border-top: 1px solid #ccc;
    font-size: 8pt;
    color: #999;
    text-align: center;
}

/* Print Styles */
@media print {
    body {
        padding: 0;
    }
    
    @page {
        size: A4;
        margin: 2cm 1.5cm;
    }
}
    </style>
</head>
<body>
    <div class="page-header">
        <h1 class="page-title">${escapeHtml(pageTitle)}</h1>
        ${pageProperties ? `<div class="page-properties">${pageProperties}</div>` : ''}
    </div>
    
    <div class="notes-content">
        ${notesHTML}
    </div>
    
    <div class="export-footer">
        <p>Exported from notd on ${new Date().toLocaleString()}</p>
    </div>
</body>
</html>`;
}

/**
 * Sanitize filename for export
 */
function sanitizeFilename(filename) {
    return filename
        .replace(/[^a-z0-9-_]/gi, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '')
        .substring(0, 200);
}

/**
 * Escape HTML for safe insertion
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Add print/export buttons to page
 */
function initPrintExport() {
    // Check if we're on a page (not index)
    if (!document.getElementById('notes-container')) return;
    
    // Create print button
    const printBtn = document.createElement('button');
    printBtn.id = 'print-page-btn';
    printBtn.className = 'action-button';
    printBtn.title = 'Print or Save as PDF';
    printBtn.innerHTML = '<i data-feather="printer"></i>';
    printBtn.addEventListener('click', printPage);
    
    // Create export HTML button
    const exportBtn = document.createElement('button');
    exportBtn.id = 'export-html-btn';
    exportBtn.className = 'action-button';
    exportBtn.title = 'Export as HTML';
    exportBtn.innerHTML = '<i data-feather="download"></i>';
    exportBtn.addEventListener('click', exportAsHTML);
    
    // Add to page header actions
    const pageHeaderActions = document.querySelector('.page-header-actions');
    if (pageHeaderActions) {
        pageHeaderActions.insertBefore(exportBtn, pageHeaderActions.firstChild);
        pageHeaderActions.insertBefore(printBtn, pageHeaderActions.firstChild);
        
        // Replace feather icons
        if (window.feather) {
            feather.replace();
        }
    }
}

// Export functions
export {
    printPage,
    exportAsHTML,
    preparePrintView,
    restorePrintView,
    initPrintExport
};


