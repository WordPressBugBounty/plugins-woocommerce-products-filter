"use strict";

var woof_add_seo_rule_lock = false;

jQuery(document).ready(function () {

    jQuery(".woof_add_seo_rule").on('click', function () {

        if (woof_add_seo_rule_lock) {
            return;
        }

        woof_add_seo_rule_lock = true;

        var url = jQuery('.woof_seo_rule_url_add').val();
        var lang = jQuery('.woof_seo_current_lang').val();
	const nonce = document.querySelector('#woof_url_request_nonce').value;

        var data = {
            action: "woof_get_seo_rule_html",
            url: url,
            lang: lang,
	    url_request_nonce: nonce
        };

        if (jQuery('#woof_seo_rules_list li').length >= 2 && woof_show_notes) {
            alert('In FREE version it is possible to operate with 2 rules only!');
            return;
        }

        jQuery.post(ajaxurl, data, function (section) {
            jQuery('#woof_seo_rules_list').append(section);
            jQuery('.woof_seo_rule_url_add').val("");
            woof_init_seo_rules_scripts();
            // show the new rule only in the selected language, like the saved ones
            woof_seo_rules_check_lang();
            woof_add_seo_rule_lock = false;
        }).fail(function () {
            // unlock the button, otherwise it stays blocked after a failed request
            woof_add_seo_rule_lock = false;
        });

    });

    // collapsible rules, all of them are expanded by default; collapsed fields are still submitted
    jQuery(document).on('click', '.woof_seo_rules_toggle', function () {
        jQuery(this).closest('.woof_seo_rules_item').toggleClass('woof_seo_rule_collapsed');
        return false;
    });
    jQuery('.woof_seo_rules_open_all').on('click', function () {
        jQuery('#woof_seo_rules_list .woof_seo_rules_item').removeClass('woof_seo_rule_collapsed');
        return false;
    });
    jQuery('.woof_seo_rules_close_all').on('click', function () {
        jQuery('#woof_seo_rules_list .woof_seo_rules_item').addClass('woof_seo_rule_collapsed');
        return false;
    });

    // remember the opened settings tabs, the page is reloaded after saving
    jQuery('#mainform').on('submit', function () {
        var tabs = [];
        jQuery('.woof-tabs > nav li.tab-current > a').each(function () {
            tabs.push(jQuery(this).attr('href'));
        });
        try {
            sessionStorage.setItem('woof_settings_tabs', JSON.stringify(tabs));
        } catch (e) {
        }
    });

    jQuery(".woof_seo_current_lang").on('change', function () {
        woof_seo_rules_check_lang();
    });

    woof_init_seo_rules_scripts();
    woof_seo_rules_check_lang();
});

// after saving open the same settings tabs again (outer tabs first, then the nested ones)
jQuery(window).on('load', function () {
    var tabs = null;
    try {
        tabs = JSON.parse(sessionStorage.getItem('woof_settings_tabs'));
        sessionStorage.removeItem('woof_settings_tabs');
    } catch (e) {
    }
    if (!Array.isArray(tabs) || window.location.search.indexOf('settings_saved=1') === -1) {
        return;
    }
    tabs.forEach(function (href) {
        jQuery('.woof-tabs > nav a[href="' + href + '"]').trigger('click');
    });
});

function woof_seo_rules_check_lang() {
    var lang = jQuery('.woof_seo_current_lang').val();
    jQuery('.woof_seo_rules_item').hide();
    jQuery('.woof_seo_rules_item_' + lang).show();
}

function woof_init_seo_rules_scripts() {
    jQuery('.woof_seo_rules_delete').off('click');
    jQuery('.woof_seo_rules_delete').on('click', function () {
        var key = jQuery(this).data('key');
        jQuery("li[data-key='" + key + "']").remove();

        return false;
    });
    jQuery('.woof_seo_rules_move_down').off('click');
    jQuery('.woof_seo_rules_move_down').on('click', function () {
        var key = jQuery(this).data('key');
        var item = jQuery("li[data-key='" + key + "']");
	var after = item.next();
	item.insertAfter(after);

        return false;
    });
    jQuery('.woof_seo_rules_move_up').off('click');
    jQuery('.woof_seo_rules_move_up').on('click', function () {
        var key = jQuery(this).data('key');
        var item = jQuery("li[data-key='" + key + "']");
	var after = item.prev();
	item.insertBefore(after);

        return false;
    });       
}
