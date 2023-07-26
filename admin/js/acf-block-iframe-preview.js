jQuery(document).ready(function ($) {

  setTimeout(() => {
    // if any blocks are in preview mode by default on page load, we manually adjust their heights here:
    const previewIframes = document.querySelectorAll('iframe.block-preview-iframe')
    previewIframes.forEach(iframe => {
      adjustIframeHeight(iframe);
    });

    MutationObserver = window.MutationObserver || window.WebKitMutationObserver;    

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

    const acfBlocks = document.querySelectorAll('.acf-block-component')

    // we watch each ACF Block separately because observe() expects a single Node, not a NodeList
    acfBlocks.forEach(acfBlock => {
      acfBlocksObserver.observe(acfBlock, {
        childList: true,
        subtree: true
      });
    });
      
    function adjustIframeHeight(iframe) {
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
