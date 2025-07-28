jQuery(document).ready(function($) {
    if (omnisendIdentifiers && omnisend && omnisend.identifyContact) {
        omnisend.identifyContact(omnisendIdentifiers);
    }
});
