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
                <div id="bokun_progress" class="bokun-progress" style="display:none;">
                    <span id="bokun_progress_message">Import progress</span>
                    <span id="bokun_progress_value" class="bokun-progress__value">0%</span>
                </div>
                <div id="bokun_loader" class="bokun_loader" style="display:none;">Processing for API 1…</div>
                <div id="bokun_loader_upgrade" class="bokun_loader" style="display:none;">Processing for API 2…</div>
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