<?php
// security
if (!defined("ABSPATH")) {
    exit('You must not access this fil directy');
}

?>

<div class="notice notice-error is-dismissible">
    <p>
        <?php
        _e("Sing Pay requires Woocommerce to be installed and active.", 'sing-pay')
        ?>
    </p>
</div>

<script>
    jQuery(document).ready(function($){
        $('.notice-error p').css('font-weight', 'bold')
    })
</script>