<?php
$stored_api_credentials = get_option('bokun_api_credentials', []);
$api_credentials = [];

if (is_array($stored_api_credentials)) {
    foreach ($stored_api_credentials as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $api_credentials[] = [
            'api_key'    => isset($entry['api_key']) ? (string) $entry['api_key'] : '',
            'secret_key' => isset($entry['secret_key']) ? (string) $entry['secret_key'] : '',
        ];
    }
}

if (empty($api_credentials)) {
    $legacy_primary = [
        'api_key'    => get_option('bokun_api_key', ''),
        'secret_key' => get_option('bokun_secret_key', ''),
    ];

    $legacy_secondary = [
        'api_key'    => get_option('bokun_api_key_upgrade', ''),
        'secret_key' => get_option('bokun_secret_key_upgrade', ''),
    ];

    foreach ([$legacy_primary, $legacy_secondary] as $legacy_entry) {
        $legacy_api_key    = isset($legacy_entry['api_key']) ? trim((string) $legacy_entry['api_key']) : '';
        $legacy_secret_key = isset($legacy_entry['secret_key']) ? trim((string) $legacy_entry['secret_key']) : '';

        if ('' === $legacy_api_key && '' === $legacy_secret_key) {
            continue;
        }

        $api_credentials[] = [
            'api_key'    => $legacy_api_key,
            'secret_key' => $legacy_secret_key,
        ];
    }
}

if (empty($api_credentials)) {
    $api_credentials[] = [
        'api_key'    => '',
        'secret_key' => '',
    ];
}

$next_api_index = count($api_credentials);
$dashboard_page_id = (int) get_option('bokun_dashboard_page_id', 0);
$dashboard_page_dropdown = wp_dropdown_pages(
    array(
        'name'              => 'dashboard_page_id',
        'id'                => 'bokun_dashboard_page_select',
        'class'             => 'widefat',
        'selected'          => $dashboard_page_id,
        'show_option_none'  => __('— Select a page —', 'BOKUN_txt_domain'),
        'option_none_value' => '0',
        'echo'              => false,
    )
);
?>
<div id="booking">
    <div class="container-fluid">

        <div class="row">
            
            <div class="col-8">
                <div class="card">
                    <h2><?php esc_html_e('Manage Bokun API Keys', 'BOKUN_txt_domain'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Store one or more Bokun API credentials. Each set will be used during the import process.', 'BOKUN_txt_domain'); ?>
                    </p>
                    <div class="notice notice-info is-dismissible msg_success_apis" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Success:', 'BOKUN_txt_domain'); ?></strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_error_apis" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Error:', 'BOKUN_txt_domain'); ?></strong>
                        </p>
                    </div>
                    <form method="post" action="javascript:;" id="bokun_api_credentials_form" name="bokun_api_credentials_form" enctype='multipart/form-data'>
                        <div class="bokun_cmrc-table" data-api-credentials-wrapper>
                            <div class="bokun_api_credentials" data-api-credentials-list data-next-index="<?php echo esc_attr($next_api_index); ?>">
                                <?php foreach ($api_credentials as $index => $credential) :
                                    $field_index = (int) $index;
                                    $api_key_value = isset($credential['api_key']) ? $credential['api_key'] : '';
                                    $secret_key_value = isset($credential['secret_key']) ? $credential['secret_key'] : '';
                                    $item_label = sprintf(__('API #%d', 'BOKUN_txt_domain'), $field_index + 1);
                                ?>
                                    <div class="bokun_api_credentials__item" data-api-credentials-item data-index="<?php echo esc_attr($field_index); ?>">
                                        <div class="bokun_api_credentials__item-header">
                                            <span class="bokun_api_credentials__item-title"><?php echo esc_html($item_label); ?></span>
                                            <button type="button" class="button-link-delete bokun_api_credentials__item-remove" data-api-credentials-remove aria-label="<?php esc_attr_e('Remove API credential', 'BOKUN_txt_domain'); ?>">
                                                <?php esc_html_e('Remove', 'BOKUN_txt_domain'); ?>
                                            </button>
                                        </div>
                                        <div class="bokun_api_credentials__fields">
                                            <label>
                                                <?php esc_html_e('API Key', 'BOKUN_txt_domain'); ?>
                                                <input type="text" name="api_credentials[<?php echo esc_attr($field_index); ?>][api_key]" value="<?php echo esc_attr($api_key_value); ?>" autocomplete="off">
                                            </label>
                                            <label>
                                                <?php esc_html_e('Secret Key', 'BOKUN_txt_domain'); ?>
                                                <input type="text" name="api_credentials[<?php echo esc_attr($field_index); ?>][secret_key]" value="<?php echo esc_attr($secret_key_value); ?>" autocomplete="off">
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="bokun_api_credentials__actions">
                                <button type="button" class="button" data-api-credentials-add><?php esc_html_e('Add another API', 'BOKUN_txt_domain'); ?></button>
                                <input type="submit" name="submit" class="button button-primary bokun_api_credentials_save" value="<?php esc_attr_e('Save API keys', 'BOKUN_txt_domain'); ?>">
                            </div>
                        </div>
                    </form>
                    <template id="bokun-api-credential-template">
                        <div class="bokun_api_credentials__item" data-api-credentials-item data-index="__index__">
                            <div class="bokun_api_credentials__item-header">
                                <span class="bokun_api_credentials__item-title"><?php esc_html_e('API', 'BOKUN_txt_domain'); ?> __number__</span>
                                <button type="button" class="button-link-delete bokun_api_credentials__item-remove" data-api-credentials-remove aria-label="<?php esc_attr_e('Remove API credential', 'BOKUN_txt_domain'); ?>">
                                    <?php esc_html_e('Remove', 'BOKUN_txt_domain'); ?>
                                </button>
                            </div>
                            <div class="bokun_api_credentials__fields">
                                <label>
                                    <?php esc_html_e('API Key', 'BOKUN_txt_domain'); ?>
                                    <input type="text" name="api_credentials[__index__][api_key]" value="" autocomplete="off">
                                </label>
                                <label>
                                    <?php esc_html_e('Secret Key', 'BOKUN_txt_domain'); ?>
                                    <input type="text" name="api_credentials[__index__][secret_key]" value="" autocomplete="off">
                                </label>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="col-4 text-center ">
                <div class="card">
                    <h2>Fetch Booking</h2>
                    <p class="for_api_1 msg_success msg_sec" style="display:none;">For API 1</p>
                    <div class="notice notice-info is-dismissible msg_success msg_sec" style="display:none;">
                        <p>
                            <strong>Success:</strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_error msg_sec" style="display:none;">
                        <p>
                            <strong>Error:</strong>
                        </p>
                    </div>
                    <form method="post" action="javascript:;" id="bokun_fetch_booking_data" name="bokun_fetch_booking_data" enctype='multipart/form-data'>
                        <div class="bokun_cmrc-table">
                            <div class="bokun_settings-fb-config">
                                <input type="submit" name="submit" class="button button-primary bokun_fetch_booking_data" value="Fetch Now">
                            </div>
                        </div>
                    </form>
                    <div id="bokun_progress" class="bokun-progress" style="display: none;" role="status" aria-live="polite">
                        <div class="bokun-progress__header">
                            <span id="bokun_progress_message" class="bokun-progress__message">Import progress</span>
                            <span class="bokun-progress__status">
                                <span id="bokun_progress_value" class="bokun-progress__value">0%</span>
                                <img id="bokun_progress_spinner" class="bokun-progress__spinner" src="<?= BOKUN_IMAGES_URL.'ajax-loading.gif'; ?>" alt="Loading" width="18" height="18">
                            </span>
                        </div>
                        <div class="bokun-progress__track" aria-hidden="true">
                            <div id="bokun_progress_bar" class="bokun-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                        </div>
                    </div>

                </div>
            </div>

        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <h2><?php esc_html_e('Booking dashboard display', 'BOKUN_txt_domain'); ?></h2>
                    <p>
                        <?php esc_html_e('Use the [bokun_booking_dashboard] shortcode or choose a page below to automatically display the dashboard.', 'BOKUN_txt_domain'); ?>
                    </p>
                    <div class="notice notice-info is-dismissible msg_dashboard_success" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Success:', 'BOKUN_txt_domain'); ?></strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_dashboard_error" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Error:', 'BOKUN_txt_domain'); ?></strong>
                        </p>
                    </div>
                    <form method="post" action="javascript:;" id="bokun_dashboard_settings_form" name="bokun_dashboard_settings_form" enctype='multipart/form-data'>
                        <div class="bokun_cmrc-table">
                            <div class="bokun_settings-fb-config">
                                <label for="bokun_dashboard_page_select"><?php esc_html_e('Dashboard page', 'BOKUN_txt_domain'); ?>:</label>
                                <?php echo $dashboard_page_dropdown; ?>
                                <p class="description">
                                    <?php esc_html_e('Select a page to automatically append the booking dashboard content. Leave blank to manage placement with the shortcode.', 'BOKUN_txt_domain'); ?>
                                </p>
                                <input type="submit" name="submit" class="button button-primary bokun_dashboard_settings_save" value="<?php esc_attr_e('Save dashboard settings', 'BOKUN_txt_domain'); ?>">
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-4 text-center ">
                <div class="card">
                    <h2><?php esc_html_e('Import Product Tag Images', 'BOKUN_txt_domain'); ?></h2>
                    <div class="notice notice-info is-dismissible msg_product_images_success" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Success:', 'BOKUN_txt_domain'); ?></strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_product_images_error" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Error:', 'BOKUN_txt_domain'); ?></strong>
                        </p>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Download gallery images for all product tags from Bokun and attach them to the corresponding taxonomy terms.', 'BOKUN_txt_domain'); ?>
                    </p>
                    <form method="post" action="javascript:;" id="bokun_import_product_tag_images_form" name="bokun_import_product_tag_images_form" enctype='multipart/form-data'>
                        <div class="bokun_cmrc-table">
                            <div class="bokun_settings-fb-config">
                                <input type="submit" name="submit" class="button button-primary bokun_import_product_tag_images" value="<?php esc_attr_e('Import Images', 'BOKUN_txt_domain'); ?>" data-loading-text="<?php esc_attr_e('Importing…', 'BOKUN_txt_domain'); ?>">
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
