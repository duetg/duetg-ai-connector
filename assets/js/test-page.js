/**
 * Test AI page JavaScript
 */
(function() {
    var placeholders = window.customAiTestPage || {
        textPlaceholder: 'Enter your text prompt...',
        imagePlaceholder: 'Describe the image you want to generate...',
        imageRefinePlaceholder: 'Describe how you want to refine the reference image...'
    };

    function updatePromptPlaceholder() {
        var select = document.getElementById('provider_type');
        var textarea = document.getElementById('prompt');
        if (!select || !textarea) {
            return;
        }
        if (select.value === 'text') {
            textarea.placeholder = placeholders.textPlaceholder;
        } else if (select.value === 'image_refine') {
            textarea.placeholder = placeholders.imageRefinePlaceholder;
        } else {
            textarea.placeholder = placeholders.imagePlaceholder;
        }
    }

    function updateReferenceImageVisibility() {
        var select = document.getElementById('provider_type');
        var row = document.getElementById('reference_image_row');
        if (!select || !row) {
            return;
        }
        row.style.display = select.value === 'image_refine' ? 'table-row' : 'none';
    }

    function onProviderChange() {
        updatePromptPlaceholder();
        updateReferenceImageVisibility();
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        updatePromptPlaceholder();
        updateReferenceImageVisibility();

        // Listen for changes to the provider type selector
        var select = document.getElementById('provider_type');
        if (select) {
            select.addEventListener('change', onProviderChange);
        }
    });
})();