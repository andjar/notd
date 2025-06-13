// ui functions
ZenPen = window.ZenPen || {};
ZenPen.ui = (function() {

	// Base elements
	var body, article, uiContainer, overlay, header;

	// Buttons
	var screenSizeElement, colorLayoutElement, targetElement, saveElement;

	// Word Counter
	var wordCountValue, wordCountBox, wordCountElement, wordCounter, wordCounterProgress;
	
	//save support
	var supportsSave, saveFormat, textToWrite;
	
	var expandScreenIcon = '&#xe000;';
	var shrinkScreenIcon = '&#xe004;';

	var darkLayout = false;

	function init() {
		
		supportsSave = !!new Blob()?true:false;
		
		bindElements();

		wordCountActive = false;

		if ( ZenPen.util.supportsHtmlStorage() ) {
			loadState();
		}
		
		console.log( "Checkin under the hood eh? We've probably got a lot in common. You should totally check out ZenPen on github! (https://github.com/tholman/zenpen)." );
	}

	function loadState() {

		// Activate word counter
		if ( localStorage['wordCount'] && localStorage['wordCount'] !== "0") {			
			wordCountValue = parseInt(localStorage['wordCount']);
			wordCountElement.value = localStorage['wordCount'];
			wordCounter.className = "word-counter active";
			updateWordCount();
		}

		// Activate color switch
		if ( localStorage['darkLayout'] === 'true' ) {
			if ( darkLayout === false ) {
				document.body.className = 'yang';
			} else {
				document.body.className = 'yin';
			}
			darkLayout = !darkLayout;
		}

	}

	function saveState() {

		if ( ZenPen.util.supportsHtmlStorage() ) {
			localStorage[ 'darkLayout' ] = darkLayout;
			localStorage[ 'wordCount' ] = wordCountElement.value;
		}
	}

	function bindElements() {

		// Body element for light/dark styles
		body = document.body;

		uiContainer = document.querySelector( '.ui' );

		// UI element for color flip
		colorLayoutElement = document.querySelector( '.color-flip' );
		colorLayoutElement.onclick = onColorLayoutClick;

		// UI element for full screen		
		screenSizeElement = document.querySelector( '.fullscreen' );
		screenSizeElement.onclick = onScreenSizeClick;

		targetElement = document.querySelector( '.target ');
		targetElement.onclick = onTargetClick;
		
		//init event listeners only if browser can save
		if (supportsSave) {

			saveElement = document.querySelector( '.save' );
			saveElement.onclick = onSaveClick;
			
			var formatSelectors = document.querySelectorAll( '.saveselection span' );
			for( var i in formatSelectors ) {
				formatSelectors[i].onclick = selectFormat;
			}
			
			document.querySelector('.savebutton').onclick = saveText;
		} else {
			document.querySelector('.save.useicons').style.display = "none";
		}

		// Overlay when modals are active
		overlay = document.querySelector( '.overlay' );
		overlay.onclick = onOverlayClick;

		article = document.querySelector( '.markdown-editor' );
		article.onkeyup = onArticleKeyUp;

		wordCountBox = overlay.querySelector( '.wordcount' );
		wordCountElement = wordCountBox.querySelector( 'input' );
		wordCountElement.onchange = onWordCountChange;
		wordCountElement.onkeyup = onWordCountKeyUp;
		
		saveModal = overlay.querySelector('.saveoverlay');

		wordCounter = document.querySelector( '.word-counter' );
		wordCounterProgress = wordCounter.querySelector( '.progress' );

	}

	function onScreenSizeClick( event ) {

		screenfull.toggle();
   		if ( screenfull.enabled ) {
			document.addEventListener( screenfull.raw.fullscreenchange, function () {
				if ( screenfull.isFullscreen ) {
					screenSizeElement.innerHTML = shrinkScreenIcon;
				} else {
					screenSizeElement.innerHTML = expandScreenIcon;	
				}
    		});
    	}
	};

	function onColorLayoutClick( event ) {
		if ( darkLayout === false ) {
			document.body.className = 'yang';
		} else {
			document.body.className = 'yin';
		}
		darkLayout = !darkLayout;

		saveState();
	}

	function onTargetClick( event ) {
		overlay.style.display = "block";
		wordCountBox.style.display = "block";
		wordCountElement.focus();
	}

	function onSaveClick( event ) {
		overlay.style.display = "block";
		saveModal.style.display = "block";
	}

	function saveText( event ) {

		if (typeof saveFormat != 'undefined' && saveFormat != '') {
			var blob = new Blob([textToWrite], {type: "text/plain;charset=utf-8"});
			/* remove tabs and line breaks from header */
			saveAs(blob, 'ZenNote.txt');
		} else {
			document.querySelector('.saveoverlay h1').style.color = '#FC1E1E';
		}
	}
	
	/* Allows the user to press enter to tab from the word count modal */
	function onWordCountKeyUp( event ) {
		
		if ( event.keyCode === 13 ) {
			event.preventDefault();
			
			setWordCount( parseInt(this.value) );

			removeOverlay();

			article.focus();
		}
	}

	function onWordCountChange( event ) {

		setWordCount( parseInt(this.value) );
	}

	function setWordCount( count ) {

		// Set wordcount ui to active
		if ( count > 0) {

			wordCountValue = count;
			wordCounter.className = "word-counter active";
			updateWordCount();

		} else {

			wordCountValue = 0;
			wordCounter.className = "word-counter";
		}
		
		saveState();
	}

	function onArticleKeyUp( event ) {

		if ( wordCountValue > 0 ) {
			updateWordCount();
		}
	}

	function updateWordCount() {

		var wordCount = ZenPen.editor.getWordCount();
		var percentageComplete = wordCount / wordCountValue;
		wordCounterProgress.style.height = percentageComplete * 100 + '%';

		if ( percentageComplete >= 1 ) {
			wordCounterProgress.className = "progress complete";
		} else {
			wordCounterProgress.className = "progress";
		}
	}

	function selectFormat( e ) {
		
		if ( document.querySelectorAll('span.activesave').length > 0 ) {
			document.querySelector('span.activesave').className = '';
		}
		
		document.querySelector('.saveoverlay h1').style.cssText = '';
		
		var targ;
		if (!e) var e = window.event;
		if (e.target) targ = e.target;
		else if (e.srcElement) targ = e.srcElement;
		
		// defeat Safari bug
		if (targ.nodeType == 3) {
			targ = targ.parentNode;
		}
			
		targ.className ='activesave';
		
		saveFormat = targ.getAttribute('data-format');
		
		var body = document.querySelector('article.markdown-editor');
		var bodyText = body.innerHTML; // Keep innerHTML for now as per reasoning in prompt
			
		textToWrite = formatText(saveFormat, bodyText);
		
		var textArea = document.querySelector('.hiddentextbox');
		textArea.value = textToWrite;
		textArea.focus();
		textArea.select();

	}

	function formatText( type, content ) {
		
		var text;
		switch( type ) {

			case 'html':
				if (typeof marked === 'function') {
					text = marked(content); // Use marked.js if available
				} else {
					// Fallback if marked.js somehow isn't loaded - treat as pre-formatted HTML
					text = content.replace(/	/g, ''); 
				}
			break;

			case 'markdown':
				text = content.replace(/\t/g, ''); // content here is innerHTML from the editor
			
				text = text.replace(/<b>|<\/b>/g,"**")
					.replace(/\r\n+|\r+|\n+|\t+/ig,"")
					.replace(/<i>|<\/i>/g,"_")
					.replace(/<blockquote>/g,"> ")
					.replace(/<\/blockquote>/g,"")
					.replace(/<p>|<\/p>/gi,"\n")
					.replace(/<br>/g,"\n");
				
				var links = text.match(/<a href="(.+)">(.+)<\/a>/gi);
				
                                if (links !== null) {
                                        for ( var i = 0; i<links.length; i++ ) {
                                                var tmpparent = document.createElement('div');
                                                tmpparent.innerHTML = links[i];
                                                
                                                var tmp = tmpparent.firstChild;
                                                
                                                var href = tmp.getAttribute('href');
                                                var linktext = tmp.textContent || tmp.innerText || "";
                                                
                                                text = text.replace(links[i],'['+linktext+']('+href+')');
                                        }
                                }
			break;

			case 'plain':
				var tmp = document.createElement('div');
				tmp.innerHTML = content;
				text = tmp.textContent || tmp.innerText || "";
				
				text = text.replace(/\t/g, '')
					.replace(/\n{3}/g,"\n")
					.replace(/\n/,""); //replace the opening line break
			break;
			default:
			break;
		}
		
		return text;
	}

	function onOverlayClick( event ) {

		if ( event.target.className === "overlay" ) {
			removeOverlay();
		}
	}

	function removeOverlay() {
		
		overlay.style.display = "none";
		wordCountBox.style.display = "none";
		descriptionModal.style.display = "none";
		saveModal.style.display = "none";
		
		if ( document.querySelectorAll('span.activesave' ).length > 0) {
			document.querySelector('span.activesave').className = '';
		}

		saveFormat='';
	}

	return {
		init: init
	}

})();
