function markdownEditor() {
  return {
    raw: '',
    rendered: '',
    init() {
      // Load from localStorage if available
      const saved = localStorage.getItem('zen-md-content');
      if (saved) {
        this.raw = saved;
        this.$refs.editor.innerText = saved;
        this.updatePreview();
      }
    },
    updatePreview() {
      // Get raw Markdown from the contenteditable div
      this.raw = this.$refs.editor.innerText;
      // Render Markdown with syntax highlighting
      this.rendered = renderMarkdownWithSyntax(this.raw);
    },
    saveContent() {
      localStorage.setItem('zen-md-content', this.raw);
      // Optionally, show a save confirmation
      this.$refs.editor.blur();
      setTimeout(() => this.$refs.editor.focus(), 100);
    }
  }
}

// Helper: Render Markdown and highlight syntax
function renderMarkdownWithSyntax(md) {
  // Highlight Markdown syntax in muted grey, render content as HTML
  // We'll do a simple regex-based replacement for headers and bold/italic
  let html = md
    // Headers: # Header
    .replace(/^(#+)(\s*)(.*)$/gm, (m, hashes, space, text) => {
      const level = hashes.length;
      return `<span class='zen-muted'>${hashes}</span>${space}<h${level}>${text}</h${level}>`;
    })
    // Bold: **bold**
    .replace(/\*\*(.*?)\*\*/g, (m, text) => {
      return `<span class='zen-muted'>**</span><strong>${text}</strong><span class='zen-muted'>**</span>`;
    })
    // Italic: *italic*
    .replace(/\*(.*?)\*/g, (m, text) => {
      return `<span class='zen-muted'>*</span><em>${text}</em><span class='zen-muted'>*</span>`;
    });
  // Use marked for the rest
  try {
    html = marked.parse(md);
  } catch (e) {
    html = md;
  }
  return html;
} 