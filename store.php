<?php
if( ! Actifend::isAdmin( wp_get_current_user() ) ) exit();

$error = 0;
if ( isset( $ERROR_MSG ) && !empty( $ERROR_MSG ) ) {
    echo "<style type='text/css'> .errorClass{ color:#ff0000; font-size:20px;}</style>";
    echo "<div><br /></div>";
    echo "<div class='errorClass'>$ERROR_MSG</div>";
    exit;
}
if ( $error == 0 ) {
?>
<!DOCTYPE html>
<html>

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="<?php echo plugins_url( 'static/steps.css',__FILE__ ); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Montserrat" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Oswald" rel="stylesheet">
</head>

<body style="background: #f5f5f5; font-family: 'Montserrat', sans-serif;">
    <div style="width:100%;height:100%">
        <div style="background-repeat: no-repeat;">
            <picture>
                <source srcset="<?php echo plugins_url( 'images/header-small.png',__FILE__ );?>" media="(max-width: 450px)">
                <source srcset="<?php echo plugins_url( 'images/header-768.png',__FILE__ );?>" media="(max-width: 768px)">
                <img src="<?php echo plugins_url( 'images/header.png',__FILE__ );?>" style="width:auto" alt="ActiFend Security Monitoring and Recovery">
            </picture>
        </div>
        <br><br><br><br>

        <div id="col1">
            <div style="margin-top: 50px">
                <span style="color:#839e66"><b>Plugin activation completed.</b></span><br>
                <span style="color:#839e66"><b>Email address provided.</b></span><br><br>
                <span>Now see your website live security dashboard, site health, security alerts and more from the <b>delightfully easy to use ACTIFEND Mobile App.</b></span><br>
            </div><br><br>
            <a href="https://play.google.com/store/apps/details?id=com.dsdinfosec.ripple&hl=en" target="_blank">
            <!--<img src="<?php echo plugins_url( 'images/actifend_android.png',__FILE__ ); ?>"  width="200px" height="50px" /></a>-->
            <img src="<?php echo plugins_url( 'images/actifend_android.png',__FILE__ ); ?>" style='max-width:200px; max-height:50px; display:block' border="0" /></a>
        </div>
        <div style="float: right; position: relative;right: 150px;">
            <img src="<?php echo plugins_url( 'images/right2.png',__FILE__ );?>" style='max-width:100%; height:auto; display:block' border="0">
        </div>
    </div>
</body>
</html>
<?php } ?>