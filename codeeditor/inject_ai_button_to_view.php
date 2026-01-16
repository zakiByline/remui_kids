<?php
/**
 * Quick Script to Inject AI Assistant Button
 * Add this to view.php to immediately show the AI button
 */

// Add this near the end of view.php, before echo $OUTPUT->footer();

?>
<script src="inject_ai_button.js"></script>
<script>
// Alternative inline injection
(function() {
    console.log('Loading AI Assistant button...');
    
    // Wait for iframe to load
    const iframe = document.getElementById('judge0-ide-frame');
    if (iframe) {
        iframe.addEventListener('load', function() {
            console.log('Iframe loaded, injecting AI button...');
            
            // Try to inject into iframe
            try {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                const script = iframeDoc.createElement('script');
                script.src = '../inject_ai_button.js';
                iframeDoc.head.appendChild(script);
            } catch (e) {
                console.log('Cannot inject into iframe (cross-origin), using parent page');
            }
        });
    }
    
    // Also add to parent page
    const script = document.createElement('script');
    script.src = '<?php echo $CFG->wwwroot; ?>/mod/codeeditor/inject_ai_button.js';
    document.head.appendChild(script);
})();
</script>
<?php





