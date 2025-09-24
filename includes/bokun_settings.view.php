<?php
$api_key = get_option('bokun_api_key', '');
$secret_key = get_option('bokun_secret_key', '');
$api_key_upgrade = get_option('bokun_api_key_upgrade', '');
$secret_key_upgrade = get_option('bokun_secret_key_upgrade', '');
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
                    <div id="bokun_loader" class="bokun_loader" style="display: none;">Processing for API 1... <img src="<?= BOKUN_IMAGES_URL.'ajax-loading.gif'; ?>" width="35px;"></div>
                    <div id="bokun_loader_upgrade" class="bokun_loader" style="display: none;">Processing for API 2... <img src="<?= BOKUN_IMAGES_URL.'ajax-loading.gif'; ?>" width="35px;"></div>
                                        
                </div>
            </div>

        </div>
    </div>
</div>
