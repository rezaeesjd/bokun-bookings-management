<?php
if( !class_exists ( 'BOKUN_Shortcode' ) ) {

    class BOKUN_Shortcode {

        function __construct(){

            add_shortcode('bokun_fetch_button', array($this, "function_bokun_fetch_button" ) );
            
        } 

        
        function function_bokun_fetch_button() {
            ob_start();
            ?>
            <div class="bokun-fetch-wrapper">
                <button class="button button-primary bokun_fetch_booking_data_front">Fetch</button>
                <div id="bokun_progress" class="bokun-progress" style="display:none;" role="status" aria-live="polite">
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
            <?php
            return ob_get_clean();
        }


        function bokun_display_settings( ) {
            if( file_exists( BOKUN_INCLUDES_DIR . "bokun_shortcode.view.php" ) ) {
                include_once( BOKUN_INCLUDES_DIR . "bokun_shortcode.view.php" );
            }
        }

    }


    global $bokun_shortcode;
    $bokun_shortcode = new BOKUN_Shortcode();
}


    
?>