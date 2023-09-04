jQuery(document).ready(function ($) {

  setTimeout(() => {
    // if any blocks are in preview mode by default on page load, we manually adjust their heights here:
    const previewIframes = document.querySelectorAll('iframe.block-preview-iframe')
    previewIframes.forEach(iframe => {
      adjustIframeHeight(iframe);
    });

    MutationObserver = window.MutationObserver || window.WebKitMutationObserver;

    /* 
      Watch the Gutenberg editor for newly added ACF blocks
    */
    const editorObserver = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        console.log('Editor mutation: ', mutation)
        if (mutation.type === 'childList' && mutation.target.classList.contains('acf-block-component') && mutation.addedNodes.length > 0) {
          // user added a new block, so we re-run observeACFBlocks to include it in mutation observations
          observeACFBlocks()
        }
      });
    });

    /* 
      When an ACF Block's DOM subtree changes (i.e. when it switches to preview mode), 
      we run some custom JS to set the height of the preview Iframe to its inner contents
    */
    const acfBlocksObserver = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        if (mutation.type === 'childList' && mutation.target.classList.contains('acf-block-component') && mutation.addedNodes.length > 0) {
          const iframe = mutation.target.querySelector('iframe.block-preview-iframe');
          if (iframe) {
            adjustIframeHeight(iframe);
          }
        }
      });
    });

    const gutenbergEditorCntr = document.querySelector('.is-root-container.wp-block-post-content')
    editorObserver.observe(gutenbergEditorCntr, {
      childList: true,
      subtree: true
    });

    observeACFBlocks() // run on initial page load

    function observeACFBlocks () {
      const acfBlocks = document.querySelectorAll('.acf-block-component')
  
      // we watch each ACF Block separately because observe() expects a single Node, not a NodeList
      acfBlocks.forEach(acfBlock => {
        acfBlocksObserver.observe(acfBlock, {
          childList: true,
          subtree: true
        });
      });
    }
      
    function adjustIframeHeight (iframe) {
      console.log('adjust iframe')

      // Add a message event listener to receive messages from the <iframe> element
      window.addEventListener('message', function(event) {
        // Check if the message is from the <iframe> element
        if (event.source === iframe.contentWindow) {
          console.log('window message event from iframe: ', event)
          console.log('set height to ', event.data)
          // Set the height of the <iframe> element to the content height
          iframe.style.height = event.data + 'px';
          iframe.parentNode.style.height = event.data + 'px';
        }
      });

      // After the 1st time the block preview renders, this getHeight request becomes necessary in order to adjust the iframe's height for subsequent preview renders:
      iframe.contentWindow.postMessage('getHeight', '*')
    }
  }, 1000)

});
