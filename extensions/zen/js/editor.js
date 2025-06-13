// editor
ZenPen = window.ZenPen || {};
ZenPen.editor = (function() {

	const ZEN_PAGE_ID = 1; // Placeholder for the page ID where the Zen note is stored
	const ZEN_NOTE_TITLE = "Zen Writer Content"; // Placeholder for identifying the Zen note
	let zenNoteId = null; // To store the ID of our note once loaded/created

	// Editor elements
	var contentField, lastType, currentNodeList, lastSelection;

	// Editor Bubble elements
	var textOptions, optionsBox, boldButton, italicButton, quoteButton, urlButton, urlInput;

	var composing;

	function init() {

		composing = false;
		bindElements();

		createEventBindings();

		// Load state if storage is supported
		loadNoteFromDB();
		// Set cursor position
		contentField.focus();

	}

	function createEventBindings() {

		// Key up bindings
		document.onkeyup = function( event ) {
			checkTextHighlighting( event );
			saveNoteToDB(); // Call the new save function
		}

		// Mouse bindings
		document.onmousedown = checkTextHighlighting;
		document.onmouseup = function( event ) {

			setTimeout( function() {
				checkTextHighlighting( event );
			}, 1);
		};
		
		// Window bindings
		window.addEventListener( 'resize', function( event ) {
			updateBubblePosition();
		});


		document.body.addEventListener( 'scroll', function() {

			// TODO: Debounce update bubble position to stop excessive redraws
			updateBubblePosition();
		});

		// Composition bindings. We need them to distinguish
		// IME composition from text selection
		document.addEventListener( 'compositionstart', onCompositionStart );
		document.addEventListener( 'compositionend', onCompositionEnd );
	}


	function bindElements() {

		contentField = document.querySelector( '.markdown-editor' );
		textOptions = document.querySelector( '.text-options' );

		optionsBox = textOptions.querySelector( '.options' );

		boldButton = textOptions.querySelector( '.bold' );
		boldButton.onclick = onBoldClick;

		italicButton = textOptions.querySelector( '.italic' );
		italicButton.onclick = onItalicClick;

		quoteButton = textOptions.querySelector( '.quote' );
		quoteButton.onclick = onQuoteClick;

		urlButton = textOptions.querySelector( '.url' );
		urlButton.onmousedown = onUrlClick;

		urlInput = textOptions.querySelector( '.url-input' );
		urlInput.onblur = onUrlInputBlur;
		urlInput.onkeydown = onUrlInputKeyDown;
	}

	function checkTextHighlighting( event ) {

		var selection = window.getSelection();


		if ( (event.target.className === "url-input" ||
		    event.target.classList.contains( "url" ) ||
		    event.target.parentNode.classList.contains( "ui-inputs" ) ) ) {

			currentNodeList = findNodes( selection.focusNode );
			updateBubbleStates();
			return;
		}

		// Check selections exist
		if ( selection.isCollapsed === true && lastType === false ) {

			onSelectorBlur();
		}

		// Text is selected
		if ( selection.isCollapsed === false && composing === false ) {

			currentNodeList = findNodes( selection.focusNode );

			// Find if highlighting is in the editable area
			if ( hasNode( currentNodeList, "ARTICLE") ) {
				updateBubbleStates();
				updateBubblePosition();

				// Show the ui bubble
				textOptions.className = "text-options active";
			}
		}

		lastType = selection.isCollapsed;
	}
	
	function updateBubblePosition() {
		var selection = window.getSelection();
		var range = selection.getRangeAt(0);
		var boundary = range.getBoundingClientRect();
		
		textOptions.style.top = boundary.top - 5 + window.pageYOffset + "px";
		textOptions.style.left = (boundary.left + boundary.right)/2 + "px";
	}

	function updateBubbleStates() {

		// It would be possible to use classList here, but I feel that the
		// browser support isn't quite there, and this functionality doesn't
		// warrent a shim.

		if ( hasNode( currentNodeList, 'B') ) {
			boldButton.className = "bold active"
		} else {
			boldButton.className = "bold"
		}

		if ( hasNode( currentNodeList, 'I') ) {
			italicButton.className = "italic active"
		} else {
			italicButton.className = "italic"
		}

		if ( hasNode( currentNodeList, 'BLOCKQUOTE') ) {
			quoteButton.className = "quote active"
		} else {
			quoteButton.className = "quote"
		}

		if ( hasNode( currentNodeList, 'A') ) {
			urlButton.className = "url useicons active"
		} else {
			urlButton.className = "url useicons"
		}
	}

	function onSelectorBlur() {

		textOptions.className = "text-options fade";
		setTimeout( function() {

			if (textOptions.className == "text-options fade") {

				textOptions.className = "text-options";
				textOptions.style.top = '-999px';
				textOptions.style.left = '-999px';
			}
		}, 260 )
	}

	function findNodes( element ) {

		var nodeNames = {};

		// Internal node?
		var selection = window.getSelection();

		// if( selection.containsNode( document.querySelector('b'), false ) ) {
		// 	nodeNames[ 'B' ] = true;
		// }

		while ( element.parentNode ) {

			nodeNames[element.nodeName] = true;
			element = element.parentNode;

			if ( element.nodeName === 'A' ) {
				nodeNames.url = element.href;
			}
		}

		return nodeNames;
	}

	function hasNode( nodeList, name ) {

		return !!nodeList[ name ];
	}

	async function saveNoteToDB() {
		if (!contentField) return; // Make sure contentField is initialized
		const currentContent = contentField.innerHTML;
		let operation;

		// Ensure API client is available
		if (!window.parent || !window.parent.notesAPI || !window.parent.notesAPI.batchUpdateNotes) {
			console.error("notesAPI not found on parent window. Cannot save.");
			return;
		}

		if (zenNoteId) { // If note exists, update it
			operation = {
				type: 'update',
				payload: {
					id: zenNoteId,
					content: currentContent
				}
			};
		} else { // If note doesn't exist, create it
			// Ensure the title is part of the content for new notes
			const newContentWithTitle = (currentContent.startsWith("# " + ZEN_NOTE_TITLE) ? currentContent : "# " + ZEN_NOTE_TITLE + "\n" + currentContent);
			operation = {
				type: 'create',
				payload: {
					page_id: ZEN_PAGE_ID,
					content: newContentWithTitle,
					// order_index: 0 // Optional: set an order_index
				}
			};
		}

		try {
			console.log("Attempting to save note:", operation);
			const response = await window.parent.notesAPI.batchUpdateNotes([operation]);
			if (response && response.results && response.results.length > 0) {
				const result = response.results[0];
				if (result.status === 'success') {
					if (result.type === 'create' && result.note && result.note.id) {
						zenNoteId = result.note.id; // Store new note ID
						console.log('Zen note created with ID:', zenNoteId);
						// If the title was added, and the editor doesn't reflect it, update it.
						// This depends on whether innerHTML reflects the exact saved content immediately.
						if (!contentField.innerHTML.startsWith("# " + ZEN_NOTE_TITLE)) {
							 contentField.innerHTML = operation.payload.content;
						}
					} else if (result.type === 'update') {
						console.log('Zen note updated successfully:', zenNoteId);
					}
				} else {
					console.error('Failed to save note, API returned error:', result.message, result);
				}
			} else {
				 console.error('Failed to save note, unexpected response:', response);
			}
		} catch (error) {
			console.error("Error saving note to DB:", error);
		}
	}

	async function loadNoteFromDB() {
		try {
			// Ensure API client is available
			if (!window.parent || !window.parent.notesAPI || !window.parent.notesAPI.getPageData) {
				console.error("notesAPI not found on parent window.");
				loadDefaultContent(); // Fallback to default content
				return;
			}

			const notes = await window.parent.notesAPI.getPageData(ZEN_PAGE_ID);
			// Attempt to find the note. For this implementation, we'll assume the Zen
			// note is the *only* note on that page or the first one that somewhat matches.
			// A more robust solution would use a specific property on the note.
			// Let's look for a note whose content *starts with* our expected title.
			const existingNote = notes.find(note => note.content && note.content.startsWith("# " + ZEN_NOTE_TITLE));

			if (existingNote) {
				zenNoteId = existingNote.id;
				contentField.innerHTML = existingNote.content; // Load the raw content
				console.log('Zen note loaded:', zenNoteId);
			} else {
				console.log('Zen note not found on page ' + ZEN_PAGE_ID + '. Initializing with default content. It will be created on first save.');
				loadDefaultContent(); // Uses defaultContent from default.js
			}
		} catch (error) {
			console.error("Error loading note from DB:", error);
			loadDefaultContent(); // Fallback to default content
		}
	}

	function loadDefaultContent() {
		contentField.innerHTML = defaultContent; // in default.js
	}

	function onBoldClick() {
		document.execCommand( 'bold', false );
	}

	function onItalicClick() {
		document.execCommand( 'italic', false );
	}

	function onQuoteClick() {

		var nodeNames = findNodes( window.getSelection().focusNode );

		if ( hasNode( nodeNames, 'BLOCKQUOTE' ) ) {
			document.execCommand( 'formatBlock', false, 'p' );
			document.execCommand( 'outdent' );
		} else {
			document.execCommand( 'formatBlock', false, 'blockquote' );
		}
	}

	function onUrlClick() {

		if ( optionsBox.className == 'options' ) {

			optionsBox.className = 'options url-mode';

			// Set timeout here to debounce the focus action
			setTimeout( function() {

				var nodeNames = findNodes( window.getSelection().focusNode );

				if ( hasNode( nodeNames , "A" ) ) {
					urlInput.value = nodeNames.url;
				} else {
					// Symbolize text turning into a link, which is temporary, and will never be seen.
					document.execCommand( 'createLink', false, '/' );
				}

				// Since typing in the input box kills the highlighted text we need
				// to save this selection, to add the url link if it is provided.
				lastSelection = window.getSelection().getRangeAt(0);
				lastType = false;

				urlInput.focus();

			}, 100);

		} else {

			optionsBox.className = 'options';
		}
	}

	function onUrlInputKeyDown( event ) {

		if ( event.keyCode === 13 ) {
			event.preventDefault();
			applyURL( urlInput.value );
			urlInput.blur();
		}
	}

	function onUrlInputBlur( event ) {

		optionsBox.className = 'options';
		applyURL( urlInput.value );
		urlInput.value = '';

		currentNodeList = findNodes( window.getSelection().focusNode );
		updateBubbleStates();
	}

	function applyURL( url ) {

		rehighlightLastSelection();

		// Unlink any current links
		document.execCommand( 'unlink', false );

		if (url !== "") {
		
			// Insert HTTP if it doesn't exist.
			if ( !url.match("^(http|https)://") ) {

				url = "http://" + url;	
			} 

			document.execCommand( 'createLink', false, url );
		}
	}

	function rehighlightLastSelection() {
		var selection = window.getSelection();
		if (selection.rangeCount > 0) {
			selection.removeAllRanges();
		}
		selection.addRange( lastSelection );
	}

	function getWordCount() {
		
		var text = ZenPen.util.getText( contentField );

		if ( text === "" ) {
			return 0
		} else {
			return text.split(/\s+/).length;
		}
	}

	function onCompositionStart ( event ) {
		composing = true;
	}

	function onCompositionEnd (event) {
		composing = false;
	}

	return {
		init: init,
		saveNoteToDB: saveNoteToDB,
		getWordCount: getWordCount
	}

})();
