<?php
$api_key = get_option('bokun_api_key', '');
$secret_key = get_option('bokun_secret_key', '');
$api_key_upgrade = get_option('bokun_api_key_upgrade', '');
$secret_key_upgrade = get_option('bokun_secret_key_upgrade', '');
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
            
            <div class="col-4 text-center ">
                <div class="card">
                    <h2>Manage Bokun API Keys 1</h2>
                    <div class="notice notice-info is-dismissible msg_success_apis" style="display:none;">
                        <p>
                            <strong>Success:</strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_error_apis" style="display:none;">
                        <p>
                            <strong>Error:</strong>
                        </p>
                    </div>
                    <form method="post" action="javascript:;" id="bokun_api_auth_form" name="bokun_api_auth_form" enctype='multipart/form-data'>
                        <div class="bokun_cmrc-table">
                            <div class="bokun_settings-fb-config">                                
                                <label for="api_key">API Key:</label>
                                <input type="text" name="api_key" value="<?= $api_key ?>" placeholder="Enter your API key" required><br>
                                <label for="secret_key">Secret Key:</label>
                                <input type="text" name="secret_key" value="<?= $secret_key ?>" placeholder="Enter your Secret key" required><br>
                                <input type="submit" name="submit" class="button button-primary bokun_api_auth_save" value="Save Keys">
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-4 text-center ">
                <div class="card">
                    <h2>Manage Bokun API Keys 2</h2>
                    <div class="notice notice-info is-dismissible msg_success_apis_upgrade" style="display:none;">
                        <p>
                            <strong>Success:</strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_error_apis_upgrade" style="display:none;">
                        <p>
                            <strong>Error:</strong>
                        </p>
                    </div>
                    <form method="post" action="javascript:;" id="bokun_api_auth_form_upgrade" name="bokun_api_auth_form_upgrade" enctype='multipart/form-data'>
                        <div class="bokun_cmrc-table">
                            <div class="bokun_settings-fb-config">                                
                                <label for="api_key_upgrade">API Key:</label>
                                <input type="text" name="api_key_upgrade" value="<?= $api_key_upgrade ?>" placeholder="Enter your API key" required><br>
                                <label for="secret_key">Secret Key:</label>
                                <input type="text" name="secret_key_upgrade" value="<?= $secret_key_upgrade ?>" placeholder="Enter your Secret key" required><br>
                                <input type="submit" name="submit" class="button button-primary bokun_api_auth_save_upgrade" value="Save Keys">
                            </div>
                        </div>
                    </form>
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
                    <p class="for_api_2 msg_success_upgrade msg_sec" style="display:none;">For API 2</p>
                    <div class="notice notice-info is-dismissible msg_success_upgrade msg_sec"  style="display:none;">
                        <p>
                            <strong>Success:</strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_error_upgrade msg_sec" style="display:none;">
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
    </div>
</div>
