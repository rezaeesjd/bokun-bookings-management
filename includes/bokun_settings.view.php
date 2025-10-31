<?php
$api_key = isset($api_key) ? (string) $api_key : '';
$secret_key = isset($secret_key) ? (string) $secret_key : '';
$api_key_upgrade = isset($api_key_upgrade) ? (string) $api_key_upgrade : '';
$secret_key_upgrade = isset($secret_key_upgrade) ? (string) $secret_key_upgrade : '';
?>
<div id="booking">
    <div class="container-fluid">

        <div class="row">

            <div class="col-4 text-center ">
                <div class="card">
                    <h2><?php esc_html_e('Manage Bokun API Keys (Primary)', BOKUN_TEXT_DOMAIN); ?></h2>
                    <div class="notice notice-info is-dismissible msg_success_apis" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Success:', BOKUN_TEXT_DOMAIN); ?></strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_error_apis" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Error:', BOKUN_TEXT_DOMAIN); ?></strong>
                        </p>
                    </div>
                    <form method="post" action="javascript:;" id="bokun_api_auth_form" name="bokun_api_auth_form" enctype='multipart/form-data'>
                        <div class="bokun_cmrc-table">
                            <div class="bokun_settings-fb-config">
                                <label for="api_key"><?php esc_html_e('API Key:', BOKUN_TEXT_DOMAIN); ?></label>
                                <input type="text" name="api_key" value="<?= esc_attr($api_key) ?>" placeholder="<?php esc_attr_e('Enter your API key', BOKUN_TEXT_DOMAIN); ?>" required><br>
                                <label for="secret_key"><?php esc_html_e('Secret Key:', BOKUN_TEXT_DOMAIN); ?></label>
                                <input type="text" name="secret_key" value="<?= esc_attr($secret_key) ?>" placeholder="<?php esc_attr_e('Enter your secret key', BOKUN_TEXT_DOMAIN); ?>" required><br>
                                <input type="submit" name="submit" class="button button-primary bokun_api_auth_save" value="<?php esc_attr_e('Save Keys', BOKUN_TEXT_DOMAIN); ?>">
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-4 text-center ">
                <div class="card">
                    <h2><?php esc_html_e('Manage Bokun API Keys (Upgrade)', BOKUN_TEXT_DOMAIN); ?></h2>
                    <div class="notice notice-info is-dismissible msg_success_apis_upgrade" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Success:', BOKUN_TEXT_DOMAIN); ?></strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_error_apis_upgrade" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Error:', BOKUN_TEXT_DOMAIN); ?></strong>
                        </p>
                    </div>
                    <form method="post" action="javascript:;" id="bokun_api_auth_form_upgrade" name="bokun_api_auth_form_upgrade" enctype='multipart/form-data'>
                        <div class="bokun_cmrc-table">
                            <div class="bokun_settings-fb-config">
                                <label for="api_key_upgrade"><?php esc_html_e('API Key:', BOKUN_TEXT_DOMAIN); ?></label>
                                <input type="text" name="api_key_upgrade" value="<?= esc_attr($api_key_upgrade) ?>" placeholder="<?php esc_attr_e('Enter your API key', BOKUN_TEXT_DOMAIN); ?>" required><br>
                                <label for="secret_key_upgrade"><?php esc_html_e('Secret Key:', BOKUN_TEXT_DOMAIN); ?></label>
                                <input type="text" name="secret_key_upgrade" value="<?= esc_attr($secret_key_upgrade) ?>" placeholder="<?php esc_attr_e('Enter your secret key', BOKUN_TEXT_DOMAIN); ?>" required><br>
                                <input type="submit" name="submit" class="button button-primary bokun_api_auth_save_upgrade" value="<?php esc_attr_e('Save Keys', BOKUN_TEXT_DOMAIN); ?>">
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-4 text-center ">
                <div class="card">
                    <h2><?php esc_html_e('Fetch Booking', BOKUN_TEXT_DOMAIN); ?></h2>
                    <p class="for_api_1 msg_success msg_sec" style="display:none;"><?php esc_html_e('For API 1', BOKUN_TEXT_DOMAIN); ?></p>
                    <div class="notice notice-info is-dismissible msg_success msg_sec" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Success:', BOKUN_TEXT_DOMAIN); ?></strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_error msg_sec" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Error:', BOKUN_TEXT_DOMAIN); ?></strong>
                        </p>
                    </div>
                    <p class="for_api_2 msg_success_upgrade msg_sec" style="display:none;"><?php esc_html_e('For API 2', BOKUN_TEXT_DOMAIN); ?></p>
                    <div class="notice notice-info is-dismissible msg_success_upgrade msg_sec"  style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Success:', BOKUN_TEXT_DOMAIN); ?></strong>
                        </p>
                    </div>
                    <div class="notice notice-error is-dismissible msg_error_upgrade msg_sec" style="display:none;">
                        <p>
                            <strong><?php esc_html_e('Error:', BOKUN_TEXT_DOMAIN); ?></strong>
                        </p>
                    </div>
                    <form method="post" action="javascript:;" id="bokun_fetch_booking_data" name="bokun_fetch_booking_data" enctype='multipart/form-data'>
                        <div class="bokun_cmrc-table">
                            <div class="bokun_settings-fb-config">
                                <input type="submit" name="submit" class="button button-primary bokun_fetch_booking_data" value="<?php esc_attr_e('Fetch Now', BOKUN_TEXT_DOMAIN); ?>">
                            </div>
                        </div>
                    </form>
                    <div id="bokun_progress" class="bokun-progress" style="display: none;" role="status" aria-live="polite">
                        <div class="bokun-progress__header">
                            <span id="bokun_progress_message" class="bokun-progress__message"><?php esc_html_e('Import progress', BOKUN_TEXT_DOMAIN); ?></span>
                            <span class="bokun-progress__status">
                                <span id="bokun_progress_value" class="bokun-progress__value">0%</span>
                                <img id="bokun_progress_spinner" class="bokun-progress__spinner" src="<?= BOKUN_IMAGES_URL.'ajax-loading.gif'; ?>" alt="<?php esc_attr_e('Loading', BOKUN_TEXT_DOMAIN); ?>" width="18" height="18">
                            </span>
                        </div>
                        <div class="bokun-progress__track" aria-hidden="true">
                            <div id="bokun_progress_bar" class="bokun-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                        </div>
        </div>

        <div class="row">

            <div class="col-4 text-center ">
                <div class="card">
                    <h2><?php esc_html_e('Getting Started', BOKUN_TEXT_DOMAIN); ?></h2>
                    <p><?php esc_html_e('Follow these steps to prepare the plugin before going live.', BOKUN_TEXT_DOMAIN); ?></p>
                    <ol class="bokun-onboarding-steps">
                        <?php if (! empty($onboarding_steps) && is_array($onboarding_steps)) : ?>
                            <?php foreach ($onboarding_steps as $step) :
                                $completed = ! empty($step['completed']);
                                $status_attr = $completed ? 'complete' : 'incomplete';
                                ?>
                                <li data-status="<?php echo esc_attr($status_attr); ?>">
                                    <?php echo esc_html($step['label']); ?>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ol>
                </div>
            </div>

            <div class="col-4 text-center ">
                <div class="card">
                    <h2><?php esc_html_e('Validate Credentials', BOKUN_TEXT_DOMAIN); ?></h2>
                    <p><?php esc_html_e('Make sure the saved keys can communicate with the Bokun API.', BOKUN_TEXT_DOMAIN); ?></p>
                    <div class="notice notice-info bokun-validate-message" style="display:none;"></div>
                    <p>
                        <button type="button" class="button button-secondary bokun-validate-credentials" data-mode="primary">
                            <?php esc_html_e('Validate Primary Keys', BOKUN_TEXT_DOMAIN); ?>
                        </button>
                    </p>
                    <p>
                        <button type="button" class="button button-secondary bokun-validate-credentials" data-mode="upgrade">
                            <?php esc_html_e('Validate Upgrade Keys', BOKUN_TEXT_DOMAIN); ?>
                        </button>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Validation fetches a single page of bookings without saving changes.', BOKUN_TEXT_DOMAIN); ?>
                    </p>
                </div>
            </div>

            <div class="col-4 text-center ">
                <div class="card">
                    <h2><?php esc_html_e('Background Sync', BOKUN_TEXT_DOMAIN); ?></h2>
                    <p class="bokun-sync-status-message" data-default-message="<?php esc_attr_e('No background sync has run yet.', BOKUN_TEXT_DOMAIN); ?>">
                        <?php
                        $last_status = isset($sync_status['last_status']) ? $sync_status['last_status'] : '';
                        $last_message = isset($sync_status['last_message']) ? $sync_status['last_message'] : '';
                        if ($last_message) {
                            echo esc_html($last_message);
                        } elseif ($last_status) {
                            echo esc_html(ucfirst($last_status));
                        } else {
                            esc_html_e('No background sync has run yet.', BOKUN_TEXT_DOMAIN);
                        }
                        ?>
                    </p>
                    <ul class="bokun-sync-meta">
                        <li>
                            <strong><?php esc_html_e('Last run:', BOKUN_TEXT_DOMAIN); ?></strong>
                            <span class="bokun-sync-last-run" data-timestamp="<?php echo isset($sync_status['last_run']) ? esc_attr((string) $sync_status['last_run']) : ''; ?>">
                                <?php
                                if (! empty($sync_status['last_run_display'])) {
                                    echo esc_html($sync_status['last_run_display']);
                                } else {
                                    esc_html_e('Never', BOKUN_TEXT_DOMAIN);
                                }
                                ?>
                            </span>
                            <?php if (! empty($sync_status['last_run_relative'])) : ?>
                                <span class="bokun-sync-last-run-relative">(<?php echo esc_html($sync_status['last_run_relative']); ?> <?php esc_html_e('ago', BOKUN_TEXT_DOMAIN); ?>)</span>
                            <?php endif; ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Next run:', BOKUN_TEXT_DOMAIN); ?></strong>
                            <span class="bokun-sync-next-run" data-timestamp="<?php echo isset($sync_status['next_run']) ? esc_attr((string) $sync_status['next_run']) : ''; ?>">
                                <?php
                                if (! empty($sync_status['next_run_display'])) {
                                    echo esc_html($sync_status['next_run_display']);
                                } else {
                                    esc_html_e('Not scheduled', BOKUN_TEXT_DOMAIN);
                                }
                                ?>
                            </span>
                            <?php if (! empty($sync_status['next_run_relative'])) : ?>
                                <span class="bokun-sync-next-run-relative">(<?php echo esc_html($sync_status['next_run_relative']); ?>)</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                    <div class="notice notice-info bokun-sync-response" style="display:none;"></div>
                    <p>
                        <button type="button" class="button button-primary bokun-run-background-sync">
                            <?php esc_html_e('Run Sync Now', BOKUN_TEXT_DOMAIN); ?>
                        </button>
                    </p>
                </div>
            </div>

        </div>

    </div>
</div>

<style>
    .bokun-onboarding-steps {
        list-style: none;
        margin: 16px 0 0;
        padding: 0;
        text-align: left;
    }

    .bokun-onboarding-steps li {
        margin: 0 0 8px;
        padding-left: 28px;
        position: relative;
        font-weight: 600;
    }

    .bokun-onboarding-steps li::before {
        content: '\2713';
        position: absolute;
        left: 0;
        top: 0;
        font-size: 16px;
        color: #46b450;
    }

    .bokun-onboarding-steps li[data-status="incomplete"]::before {
        content: '\25CB';
        color: #d63638;
    }

    .bokun-sync-meta {
        list-style: none;
        margin: 16px 0;
        padding: 0;
        text-align: left;
        display: inline-block;
    }

    .bokun-sync-meta li {
        margin: 0 0 6px;
    }

    .bokun-sync-status-message {
        min-height: 36px;
    }
</style>

        </div>
    </div>
</div>
