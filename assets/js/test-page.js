/**
 * Test AI page JavaScript
 */
(function() {
    var placeholders = window.customAiTestPage || {
        textPlaceholder: 'Enter your text prompt...',
        imagePlaceholder: 'Describe the image you want to generate...'
    };

    function updatePromptPlaceholder() {
        var select = document.getElementById('provider_type');
        var textarea = document.getElementById('prompt');
        if (!select || !textarea) {
            return;
        }
        if (select.value === 'text') {
            textarea.placeholder = placeholders.textPlaceholder;
        } else {
            textarea.placeholder = placeholders.imagePlaceholder;
        }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        updatePromptPlaceholder();

        // Listen for changes to the provider type selector
        var select = document.getElementById('provider_type');
        if (select) {
            select.addEventListener('change', updatePromptPlaceholder);
        }
    });
})();
