<?php
if( ! Actifend::isAdmin( wp_get_current_user() ) ) exit();

$error = 0;
$subs_info = Utility::get_subscription_status();
if ( false === $subs_info ) {
    $assetid = Utility::userAssetID();
    $comp_score = 0;
} else {
    $assetid = $subs_info['asset_id'];
    $comp_score = round( $subs_info['comp_score'] );
    $subs_plan = get_option( 'actifend_subs_plan' );
    if ( $subs_plan == 'trial' ) $subs_plan = 'pro trial';
}

$secMsgText = "Please address and subsequently close the alerts in <b>ActiFend Mobile Security Center</b>.";
if ( $subs_info['category'] == 'HEALTHY' ) {
    $className = 'past';
    $msgText = "You are effectively using the <b>ActiFend Mobile Security Center. </b>";
    $secMsgText = "Congratulations. Please continue to use <b>ActiFend Mobile Security Center</b> regularly!";
} elseif ( $subs_info['category'] == 'CRITICAL' ) {
    $className = 'critical';
    $msgText = "ActiFend detected <b>intrusions</b> in your website and raised alerts. ";
} else {
    $className = 'atrisk';
    $msgText = "ActiFend detected <b>vulnerabilities</b> in your website. ";
    if ( $comp_score <= 30 ) $className = 'critical';
}

if ( $error == 0 ) {
?>
<!DOCTYPE html>
<html>

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css?family=Montserrat" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Oswald" rel="stylesheet">
</head>

<body style="background: #f5f5f5;font-family: 'Montserrat', sans-serif;">
    <div style="width:100%;height:100%">
        <div style="background-repeat: no-repeat;">
            <picture>
                <source srcset="<?php echo plugins_url( 'images/header-small.png',__FILE__ );?>" media="(max-width: 450px)">
                <source srcset="<?php echo plugins_url( 'images/header-768.png',__FILE__ );?>" media="(max-width: 768px)">
                <img src="<?php echo plugins_url( 'images/header.png',__FILE__ );?>" style="width:auto" alt="ActiFend Security Monitoring and Recovery">
            </picture>
        </div>
        <br><br><br>
        <div id="col-center" align="center">
          <div>
              <span style="letter-spacing: 1.2px;"><b>ACTI</b>vely de<b>FEND</b> your website</span><br>
              <p style="letter-spacing: 2px;"><b>ACTIFEND Asset ID - <?php echo $assetid; ?></b><br>
              <?php echo "Subscription - <strong>" . strtoupper( $subs_plan ) . "</strong>" ?></p>
          </div>
          <br><br>
          <div>
              <span>Your website security composite score is <b><?php echo $comp_score;?>/100</b>. <br>Please check the ActiFend App to improve.</span>
              <br><br>
              <span><?php echo $msgText; ?><br><?php echo $secMsgText; ?></span>
          </div>
        </div><br><br>
    </div>
</body>
</html>
<?php } ?>