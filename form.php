<?php
// Form.php
if ( ! Actifend::isAdmin( wp_get_current_user() ) ) exit();

$error      = 1;
$ERROR_MSG  = NULL;
$utilObj    = new Utility;

@$ripple_email = sanitize_email( sanitize_text_field( $_POST['actifend_email'] ) );

if (isset( $ripple_email )
    && $utilObj->actifend_validEmail( $ripple_email )) {

    @$actifend_optin = $_POST['optin'];
    debug_log( "Admin email: $ripple_email" );
    debug_log( "Actifend Opt In: $actifend_optin" );

    $userRegObj = new ActifendUserRegister;
    $ret = $userRegObj->actifend_register_step2( $ripple_email );
    if ( strcmp( $ret, 'NO_ASSETID' ) === 0 ) {
        // get assetid and update
        $res = $this->utiObj->update_assetid( get_site_url(), $ripple_email );
        if ( $res ) {
            debug_log( 'DB updated with asset id.' );
        }
    }
    # Display next page
    $mapp = get_option( 'mapp_activated', 0 );
    $actifend_dir = plugin_dir_path( __FILE__ );
    $template = ( $mapp == 0 ? 'store.php' : 'usage.php' );
    require_once( trailingslashit( $actifend_dir ) . $template );

    $error = 0;
} else {
    $error = 1;
    $ERROR_MSG = 'ERROR: Not a valid email address to register.';
}

if ( $error == 1 ) {
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="<?php echo plugins_url( 'static/steps.css',__FILE__ ); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Montserrat" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Oswald" rel="stylesheet">
</head>

<body style="background: #f5f5f5;font-family: 'Montserrat', sans-serif;">
    <div style="width:100%;height:100%">
        <div style="background-repeat: no-repeat;">
            <picture>
                <source srcset="<?php echo plugins_url( 'images/header-small.png',__FILE__ );?>" media="(max-width: 450px)">
                <source srcset="<?php echo plugins_url( 'images/header-768.png',__FILE__ );?>" media="(max-width: 900px)">
                <img src="<?php echo plugins_url( 'images/header.png',__FILE__ );?>" style="width:auto" alt="ActiFend Security Monitoring and Recovery">
            </picture>
        </div>
        <div id="col1">
		<form method="post">
            <?php
                $current_user = wp_get_current_user();
                $admin_email  = $current_user->user_email;
            ?>
            <p>
                <span style="color:#839e66"><b>Plugin activation completed</b>. Now setup your email-based login for instant<br> website recovery through the ACTIFEND App.</span>
            </p>
            <br>
            <b style="font-family: 'Oswald', sans-serif;">LINK YOUR EMAIL ADDRESS WITH ACTIFEND</b>
            <br>
            <br>
            <div style="max-width: 366px;height:26px;background: #eeeeee;">
                <input type="email"
                       placeholder="Admin email address"
                       id="admin_email"
                       style="background: none;border: none;width: 330px;margin-left: 5px;"
                       value="<?php echo $admin_email; ?>"
                       size="30"
                       onfocusout="checkEmail()"
                       name="actifend_email">
                <img id="tick" src="<?php echo plugins_url( 'images/tick.png',__FILE__ );?>" style="margin-top:4px;max-width:19px;">
            </div>
            <br>
            <label>
                <input type="checkbox"
                       id="optin"
                       name="optin"
                       style="float: left; margin-top: 3px;"
                       onchange="optedIn();" />
                <span style="display: block; margin: 0 0 25px 25px">I agree to use this email address to login to the mobile app and receive important security information from ACTIFEND.</span>
            </label>
            <p style="padding-left: 25px;">Read our <span style="color:#915353;"><a href="http://www.actifend.com/privacy.html" target="_blank">privacy policy</span></a> for more details.</p>
            <div style="text-align: center">
                <input  type="submit"
                        id="activate"
                        name="activate"
                        class="button"
                        style="background: #839e66;border: none;color: #f5f5f5"
                        value="Accept email opt-in to proceed"
                        disabled />
                <input  type="button"
                        id="cancelbutton"
                        name="cancelbutton"
                        class="button"
                        style="background: #839e66;border: none;color: #f5f5f5"
                        value="Cancel"
                        onclick="cancelOptin();" />
            </div>
			</form>
            <script type="text/javascript">
                function optedIn() {
                    var actButton = document.getElementById("activate");
                    var chkBox = document.getElementById("optin");
                    if (chkBox.checked) {
                        actButton.value = "PROCEED TO FINAL STEP";
                        actButton.disabled = false;
                    }else {
                        actButton.value = "Accept email opt-in to proceed";
                        actButton.disabled = true;
                        actButton.style = "background: #839e66;border: none;color: #f5f5f5";
                    }
                }

                function cancelOptin() {
                    var cancelButton = document.getElementById("cancelbutton");
                    var retVal = confirm("You would not receive full benefits of ActiFend without completion of onboarding! \n\nDo you want to cancel?");
                    if (retVal == true) {
                        window.location="<?php echo admin_url() . '/plugins.php'; ?>";
                    }
                }

                function checkEmail() {
                    var emailField = document.getElementById("admin_email");
                    var tickImg = document.getElementById("tick");
                    var emailVal = emailField.value;
                    if (emailVal == "") {
                        tickImg.style.visibility = "hidden";
                    } else {
                        if ((emailVal.includes('@') === true) && (emailVal.includes('.') === true)) {
                            tickImg.style.visibility = "visible";
                        }
                    }
                }
            </script>
        </div>
        <div style="float: right;position: relative;right: 150px;">
            <img src="<?php echo plugins_url( 'images/right1.png',__FILE__ );?>" style='width:100%; height:auto; display:block' border="0" alt="Null"></img>
        </div>
    </div>
</body>
</html>
<?php } ?>
