<?php
error_reporting(0);
/* (c) Blue Wave Projects and Services 2015-2023. This software is released under the GNU GPL license.

 This is a FAS script providing an example of remote Forward Authentication for openNDS (NDS) on an http web server supporting PHP.

 The following NDS configurations must be set:
 1. fasport: Set to the port number the remote webserver is using (typically port 443)

 2. faspath: This is the path from the FAS Web Root to the location of this FAS script (not from the file system root).
	eg. /nds/fas-aes-https.php

 3. fasremoteip: The remote IPv4 address of the remote server eg. 46.32.240.41

 4. fasremotefqdn: The fully qualified domain name of the remote web server.
	This is required in the case of a shared web server (ie. a server that hosts multiple domains on a single IP),
	but is optional for a dedicated web server (ie. a server that hosts only a single domain on a single IP).
	eg. onboard-wifi.net

 5. faskey: Matching $key as set in this script (see below this introduction).
	This is a key phrase for NDS to encrypt the query string sent to FAS.
	It can be any combination of A-Z, a-z and 0-9, up to 16 characters with no white space.
	eg 1234567890

 6. fas_secure_enabled:  set to level 3
	The NDS parameters: clientip, clientmac, gatewayname, client token, gatewayaddress, authdir and originurl
	are encrypted using fas_key and passed to FAS in the query string.

	The query string will also contain a randomly generated initialization vector to be used by the FAS for decryption.

	The "php-cli" package and the "php-openssl" module must both be installed for fas_secure level 2 and above.

 openNDS does not have "php-cli" and "php-openssl" as dependencies, but will exit gracefully at runtime if this package and module
 are not installed when fas_secure_enabled is set to level 2 or 3.

 The FAS must use the initialisation vector passed with the query string and the pre shared faskey to decrypt the required information.

 The remote web server (that runs this script) must have the "php-openssl" module installed (standard for most hosting services).

 This script requires the client user to enter their Fullname and email address. This information is stored in a log file kept
 in the same folder as this script.

 This script requests the client CPD to display the NDS avatar image directly from Github.

 This script displays an example Terms of Service. **You should modify this for your local legal juristiction**.

 The script is provided as a fully functional https splash page sequence.
 In its present form it does not do any verification, but serves as an example for customisation projects.

 The script retreives the clientif string sent from NDS and displays it on the login form.
 "clientif" is of the form [client_local_interface] [remote_meshnode_mac] [local_mesh_if]
 The returned values can be used to dynamically modify the login form presented to the client,
 depending on the interface the client is connected to.
 eg. The login form can be different for an ethernet connection, a private wifi, a public wifi or a remote mesh network zone.

*/

// Set the pre-shared key. This **MUST** be the same as faskey in the openNDS config:
$key = "328411b33fe55127421fa394995711658526ed47d0affad3fe56a0b3930c8689";

// Allow immediate flush to browser
if (ob_get_level()) {
    ob_end_clean();
}

//force redirect to secure page
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == "off") {
    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $redirect);
    exit(0);
}

// setup basic defaults
date_default_timezone_set("UTC");
$client_zone = $fullname = $email = $invalid = "";
$me = $_SERVER['SCRIPT_NAME'];

// Set logpath
if (file_exists("/etc/config/opennds")) {
    $logpath = "/tmp/";
} elseif (file_exists("/etc/opennds/opennds.conf")) {
    $logpath = "/run/";
} else {
    $logpath = "";
}


/*Configure Quotas - Time, Data and Data Rate - Override global settings (openNDS config values or defaults)

	Description of values that can be set:
	1. Set the session length(minutes), upload/download quotas(kBytes), upload/download rates(kbits/s)
		and custom string to be sent to the BinAuth script.
	2. Upload and download quotas are in kilobytes.
		If a client exceeds its upload or download quota it will be deauthenticated on the next cycle of the client checkinterval.
		(see openNDS config for checkinterval)

	3. Client Upload and Download Rates are the average rates a client achieves since authentication
		If a client exceeds its set upload or download rate it will be deauthenticated on the next cycle of the client checkinterval.

	**Note** - The following variables are set on a client by client basis. If a more sophisticated client credential verification was implemented,
		these variables could be set dynamically.

	In addition, choice of the values of these variables can be determined, based on the interface used by the client
		(as identified by the clientif parsed variable). For example, a system with two wireless interfaces such as "members" and "guests".

	A value of 0 means no limit
*/

$sessionlength = 30; // minutes (1440 minutes = 24 hours)
$uploadrate = 10000; // kbits/sec (500 kilobits/sec = 0.5 Megabits/sec)
$downloadrate = 10000; // kbits/sec (1000 kilobits/sec = 1.0 Megabits/sec)
$uploadquota = 0; // kBytes (500000 kiloBytes = 500 MegaBytes)
$downloadquota = 0; // kBytes (1000000 kiloBytes = 1 GigaByte)


/* define a remote image to display
	eg. https://avatars1.githubusercontent.com/u/62547912 is the openNDS Portal Lens Flare
	$imagepath is used function footer()
*/

$imageurl = "https://avatars1.githubusercontent.com/u/62547912";
$imagetype = "png";
$scriptname = basename($_SERVER['SCRIPT_NAME']);
$imagepath = htmlentities("$scriptname?get_image=$imageurl&imagetype=$imagetype");


###################################
#Begin processing inbound requests:
###################################

// Send The Auth List when requested by openNDS (authmon daemon)
auth_get();

// Service requests for remote image
if (isset($_GET["get_image"])) {
    $url = $_GET["get_image"];
    $imagetype = $_GET["imagetype"];
    get_image($url, $imagetype);
    exit(0);
}

// Get the query string components
if (isset($_GET['status'])) {
    @$redir = $_GET['redir'];
    @$redir_r = explode("fas=", $redir);
    @$fas = $redir_r[1];

    if (isset($_GET['iv'])) {
        $iv = $_GET['iv'];
    } else {
        $iv = "error";
    }
} else if (isset($_GET['fas'])) {
    $fas = $_GET['fas'];

    if (isset($_GET['iv'])) {
        $iv = $_GET['iv'];
    } else {
        $iv = "error";
    }
} else {
    exit(0);
}

//Decrypt and Parse the querystring
decrypt_parse();

if (!isset($clientmac)) {
    //Encryption error
    err403();
    exit(0);
}

// Extract the client zone:
$client_zone_r = explode(" ", trim($clientif));

if (!isset($client_zone_r[1])) {
    $client_zone = "LocalZone:" . $client_zone_r[0];
} else {
    $client_zone = "MeshZone:" . str_replace(":", "", $client_zone_r[1]);
}

/* Create auth list directory for this gateway
	This list will be sent to NDS when it requests it.
*/
$gwname = hash('sha256', trim($gatewayname));

if (!file_exists("$logpath" . "$gwname")) {
    mkdir("$logpath" . "$gwname", 0700);
}

#######################################################
//Start Outputing the requested responsive page:
#######################################################

splash_header();

if (isset($_GET["terms"])) {
    // ToS requested
    display_terms();
    footer();
} elseif (isset($_GET["status"])) {
    // The status page is triggered by a client if already authenticated by openNDS (eg by clicking "back" on their browser)
    status_page();
    footer();
} elseif (isset($_GET["auth"])) {
    # Verification is complete so now wait for openNDS to authenticate the client.
    authenticate_page();
    footer();
} elseif (isset($_GET["landing"])) {
    // The landing page is served to the client after openNDS authentication, but many CPDs will immediately close so this page might not be seen
    landing_page();
    footer();
} else {
    login_page();
    footer();
}

// Functions:

function decrypt_parse()
{
    /*
	Decrypt and Parse the querystring
		Note: $ndsparamlist is an array of parameter names to parse for.
			Add your own custom parameters to **this array** as well as to the **config file**.
			"admin_email" and "location" are examples of custom parameters.
	*/

    $cipher = "AES-256-CBC";
    $ndsparamlist = explode(" ", "clientip clientmac client_type gatewayname gatewayurl version hid gatewayaddress gatewaymac originurl clientif admin_email location");

    if (isset($_GET['fas']) and isset($_GET['iv'])) {
        $string = $_GET['fas'];
        $iv = $_GET['iv'];
        $decrypted = openssl_decrypt(base64_decode($string), $cipher, $GLOBALS["key"], 0, $iv);
        $dec_r = explode(", ", $decrypted);

        foreach ($ndsparamlist as $ndsparm) {
            foreach ($dec_r as $dec) {
                @list($name, $value) = explode("=", $dec);
                if ($name == $ndsparm) {
                    $GLOBALS["$name"] = $value;
                    break;
                }
            }
        }
    }
}

function get_image($url, $imagetype)
{
    // download the requested remote image
    header("Content-type: image/$imagetype");
    readfile($url);
}

function auth_get_custom()
{
    // Add your own function to handle auth_get custom payload
    $payload_decoded = base64_decode($_POST["payload"]);

    $logpath = $GLOBALS["logpath"];
    $log = date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']) .
        ", $payload_decoded\n";

    if ($logpath == "") {
        $logfile = "ndslog/customlog_log.php";

        if (!file_exists($logfile)) {
            @file_put_contents($logfile, "<?php exit(0); ?>\n");
        }
    } else {
        $logfile = "$logpath" . "ndslog/customlog_log.log";
    }

    @file_put_contents($logfile, $log,  FILE_APPEND);

    echo "ack";
}

function auth_get_deauthed()
{
    // Add your own function to handle auth_get deauthed payload
    // By default it isappended to the FAS deauth log
    $payload_decoded = base64_decode($_POST["payload"]);

    $logpath = $GLOBALS["logpath"];
    $log = date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']) .
        ", $payload_decoded\n";

    if ($logpath == "") {
        $logfile = "ndslog/deauthlog_log.php";

        if (!file_exists($logfile)) {
            @file_put_contents($logfile, "<?php exit(0); ?>\n");
        }
    } else {
        $logfile = "$logpath" . "ndslog/deauthlog_log.log";
    }

    @file_put_contents($logfile, $log,  FILE_APPEND);

    echo "ack";
}

function auth_get()
{
    /* Send and/or clear the Auth List when requested by openNDS
		When a client was verified, their parameters were added to the "auth list"
		The auth list is sent to openNDS when authmon requests it.

		auth_get:
		auth_get is sent by authmon or libopennds in a POST request and can have the following values:

		1. Value "list".
			FAS sends the auth list and deletes each client entry currently on that list.

		2. Value "view".
			FAS checks the received payload for an ack list of successfully authenticated clients from previous auth lists.
			Clients on the auth list are only deleted if they are in a received ack list.
			Authmon will have sent the ack list as acknowledgement of all clients that were successfully authenticated in the previous auth list.
			Finally FAS replies by sending the next auth list.
			"view" is the default method used by authmon.

		3. Value "clear".
			This is a housekeeping function and is called by authmon on startup of openNDS.
			The auth list is cleared as any entries held by this FAS at the time of openNDS startup will be stale.

		4. Value "deauthed".
			FAS receives a payload containing notification of deauthentication of a client and the reason for that notification.
			FAS replies with an ack., confirming reception of the notification.

		5. Value "custom".
			FAS receives a payload containing a b64 encoded string to be used by FAS to provide custom functionality.
			FAS replies with an ack., confirming reception of the custom string.
	*/

    $logpath = $GLOBALS["logpath"];

    if (isset($_POST["auth_get"])) {

        if (isset($_POST["gatewayhash"])) {
            $gatewayhash = $_POST["gatewayhash"];
        } else {
            # invalid call, so:
            exit(0);
        }

        if ($_POST["auth_get"] == "deauthed") {
            auth_get_deauthed();
            exit(0);
        }

        if ($_POST["auth_get"] == "custom") {
            auth_get_custom();
            exit(0);
        }

        if (!file_exists("$logpath" . "$gatewayhash")) {
            # no clients waiting, so:
            exit(0);
        }

        if ($_POST["auth_get"] == "clear") {
            $auth_list = scandir("$logpath" . "$gatewayhash");
            array_shift($auth_list);
            array_shift($auth_list);

            foreach ($auth_list as $client) {
                unlink("$logpath" . "$gatewayhash/$client");
            }
            # Stale entries cleared, so:
            exit(0);
        }

        # Set default empty authlist:
        $authlist = "*";

        $acklist = base64_decode($_POST["payload"]);

        if ($_POST["auth_get"] == "list") {
            $auth_list = scandir("$logpath" . "$gatewayhash");
            array_shift($auth_list);
            array_shift($auth_list);

            foreach ($auth_list as $client) {
                $clientauth = file("$logpath" . "$gatewayhash/$client");
                $authlist = $authlist . " " . rawurlencode(trim($clientauth[0]));
                unlink("$logpath" . "$gatewayhash/$client");
            }
            echo trim("$authlist");
        } else if ($_POST["auth_get"] == "view") {

            if ($acklist != "none") {
                $acklist_r = explode("\n", $acklist);

                foreach ($acklist_r as $client) {
                    $client = ltrim($client, "* ");

                    if ($client != "") {
                        if (file_exists("$logpath" . "$gatewayhash/$client")) {
                            unlink("$logpath" . "$gatewayhash/$client");
                        }
                    }
                }
                echo "ack";
            } else {
                $auth_list = scandir("$logpath" . "$gatewayhash");
                array_shift($auth_list);
                array_shift($auth_list);

                foreach ($auth_list as $client) {
                    $clientauth = file("$logpath" . "$gatewayhash/$client");
                    $authlist = $authlist . " " . rawurlencode(trim($clientauth[0]));
                }
                echo trim("$authlist");
            }
        }
        exit(0);
    }
}

function write_log()
{
    # In this example we have decided to log all clients who are granted access
    # Note: the web server daemon must have read and write permissions to the folder defined in $logpath
    # By default $logpath is null so the logfile will be written to the folder this script resides in,
    # or the /tmp directory if on the NDS router

    $logpath = $GLOBALS["logpath"];

    if (!file_exists("$logpath" . "ndslog")) {
        mkdir("$logpath" . "ndslog", 0700);
    }

    $me = $_SERVER['SCRIPT_NAME'];
    $script = basename($me, '.php');
    $host = $_SERVER['HTTP_HOST'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $clientip = $GLOBALS["clientip"];
    $clientmac = $GLOBALS["clientmac"];
    $client_type = $GLOBALS["client_type"];
    $gatewayname = $GLOBALS["gatewayname"];
    $gatewayaddress = $GLOBALS["gatewayaddress"];
    $gatewaymac = $GLOBALS["gatewaymac"];
    $clientif = $GLOBALS["clientif"];
    $originurl = $GLOBALS["originurl"];
    $redir = rawurldecode($originurl);
    if (isset($_GET["fullname"])) {
        $fullname = $_GET["fullname"];
    } else {
        $fullname = "na";
    }

    if (isset($_GET["email"])) {
        $email = $_GET["email"];
    } else {
        $email = "na";
    }

    $log = date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']) .
        ", $script, $gatewayname, $fullname, $email, $clientip, $clientmac, $client_type, $clientif, $user_agent, $redir\n";

    if ($logpath == "") {
        $logfile = "ndslog/ndslog_log.php";

        if (!file_exists($logfile)) {
            @file_put_contents($logfile, "<?php exit(0); ?>\n");
        }
    } else {
        $logfile = "$logpath" . "ndslog/ndslog.log";
    }

    @file_put_contents($logfile, $log,  FILE_APPEND);
}

###################################
// Functions used to generate html:
###################################

function authenticate_page()
{
    # Display a "logged in" landing page once NDS has authenticated the client.
    # or a timed out error if we do not get authenticated by NDS
    $me = $_SERVER['SCRIPT_NAME'];
    $host = $_SERVER['HTTP_HOST'];
    $clientip = $GLOBALS["clientip"];
    $gatewayname = $GLOBALS["gatewayname"];
    $gatewayaddress = $GLOBALS["gatewayaddress"];
    $gatewaymac = $GLOBALS["gatewaymac"];
    $hid = $GLOBALS["hid"];
    $key = $GLOBALS["key"];
    $clientif = $GLOBALS["clientif"];
    $originurl = $GLOBALS["originurl"];
    $redir = rawurldecode($originurl);
    $sessionlength = $GLOBALS["sessionlength"];
    $uploadrate = $GLOBALS["uploadrate"];
    $downloadrate = $GLOBALS["downloadrate"];
    $uploadquota = $GLOBALS["uploadquota"];
    $downloadquota = $GLOBALS["downloadquota"];
    $gwname = $GLOBALS["gwname"];
    $logpath = $GLOBALS["logpath"];

    if (isset($_GET["fullname"])) {
        $fullname = $_GET["fullname"];
    } else {
        $fullname = "na";
    }

    if (isset($_GET["email"])) {
        $email = $_GET["email"];
    } else {
        $email = "na";
    }


    /*	You can also send a custom data string to BinAuth. Set the variable $custom to the desired value
		It can contain any information that could be used for post authentication processing
		eg. the values set per client for Time, Data and Data Rate quotas can be sent to BinAuth for a custom script to use
		This string will be b64 encoded before sending to binauth and will appear in the output of ndsctl json
	*/

    $custom = "fullname=$fullname, email=$email";
    $custom = base64_encode($custom);


    $rhid = hash('sha256', trim($hid) . trim($key));

    # Construct the client authentication string or "log"
    # Note: override values set earlier if required, for example by testing clientif
    $log = "$rhid $sessionlength $uploadrate $downloadrate $uploadquota $downloadquota $custom \n";

    $logfile = "$logpath" . "$gwname/$rhid";

    # Request authentication by openNDS
    if (!file_exists($logfile)) {
        file_put_contents("$logfile", "$log");
    }
    echo '
        <div class="full-content">
        <img id="logo-img" src="./src/img/logo-kiaby.png" alt="logo-kiaby">
      <p class="subtitle">Tienda de ropa</p>
      <h3 id="main-message">Conectando con el servidor...</h3><br>';
    // flush();
    echo '
        <div id="progress-container" >
            <div id="progress-bar" >0%</div>
        </div>
        <div id="status"></div>
 </div>

';
    flush();
    # Display "waiting" ticker, then log authentication if successful:
    $count = 0;
    $maxcount = 30;

    for ($i = 1; $i <= $maxcount; $i++) {
        $count++;
        sleep(1);
        // echo "<b style=\"color:red;\">⭐</b>";
        $percent = intval(($i / $maxcount) * 100);
        flush();

        if ($percent == 30) {
            echo "<script>updateMainMessage('Casi listo, preparando tu acceso...');</script>";
        }

        echo "<script>updateProgress($percent);</script>";
        if ($count == 10) {
            // echo "<br>";
            $count = 0;
        }

        flush();

        if (file_exists("$logfile")) {
            $authed = "no";
        } else {
            //no list so must be authed
            $authed = "yes";
            write_log();
        }

        if ($authed == "yes") {
            echo "<script>updateStatus('<b>Autenticado</b>');</script>";
            landing_page();
            flush();
            break;
        }
    }

    // Serve warning to client if authentication failed/timed out:
    if ($i > $maxcount) {
        unlink("$logfile");
        echo "
			<br>El Portal ha expirado<br>Puede que tengas que apagar y encender tu WiFi para volver a conectarte.<br>
			<p>
			Haga clic o toque Continuar para volver a intentarlo.
			</p>
			<form>
				<input type=\"button\" VALUE=\"Continuar\" onClick=\"location.href='" . $redir . "'\" >
			</form>
		";
    }
}

function thankyou_page()
{
    /* Output the "Thankyou page" with a continue button
		You could include information or advertising on this page
		Be aware that many devices will close the login browser as soon as
		the client taps continue, so now is the time to deliver your message.
	*/

    $me = $_SERVER['SCRIPT_NAME'];
    $host = $_SERVER['HTTP_HOST'];
    $fas = $GLOBALS["fas"];
    $iv = $GLOBALS["iv"];
    $clientip = $GLOBALS["clientip"];
    $gatewayname = $GLOBALS["gatewayname"];
    $gatewayaddress = $GLOBALS["gatewayaddress"];
    $gatewaymac = $GLOBALS["gatewaymac"];
    $key = $GLOBALS["key"];
    $hid = $GLOBALS["hid"];
    $clientif = $GLOBALS["clientif"];
    $originurl = $GLOBALS["originurl"];
    // $fullname = $_GET["fullname"];
    $fullname = "USER";
    $email = $_GET["email"];
    $fullname_url = rawurlencode($fullname);
    $auth = "yes";
    //   <h2>Desplázate hacia abajo para encontrar el botón de continuar</h2>

    echo '
    
        <div class="full-content">
        <img id="logo-img" src="./src/img/logo-kiaby.png" alt="logo-kiaby">
      <p class="subtitle">Tienda de ropa</p>
';

    echo "
        <br></br>
        <h1 id=\"counter\"></h1>
            <br></br>

            <form action=\"$me\" method=\"get\">
                <input type=\"hidden\" name=\"fas\" value=\"$fas\">
                <input type=\"hidden\" name=\"iv\" value=\"$iv\">
                <input type=\"hidden\" name=\"auth\" value=\"$auth\">
                <input type=\"hidden\" name=\"fullname\" value=\"$fullname_url\">
                <input type=\"hidden\" name=\"email\" value=\"$email\">
                <input style=\"display: none;\" class=\"link-div\" id=\"continue_btn_sleep\" type=\"submit\" value=\"Conectarse\" >
            </form>
            <p id=\"counter_msg\">Por favor, no cierres esta página. Debes esperar a que aparezca el botón de conectarse y hacer clic para tener acceso a Internet.</p>


        ";

    echo '
        <img id="arrow_down" src="./src/img/chevron-down-outline.svg" alt="">
        <div class="container_main">
               <h1 style="font-weight: lighter;" >Síguenos en redes sociales</h1>
            <section>
                <h3>Tiktok</h3>

                <blockquote class="tiktok-embed" cite="https://www.tiktok.com/@kiaby.store" data-unique-id="kiaby.store"
                    data-embed-type="creator" style="max-width: 780px; min-width: 288px;">
                    <section> <a target="_blank" href="https://www.tiktok.com/@kiaby.store?refer=creator_embed">@kiaby.store</a> </section>
                </blockquote>

            </section>

            <section>
                <h3>Facebook</h3>
                    <div class="iframe">
                        <div>
                            <iframe src="https://www.facebook.com/plugins/page.php?href=https%3A%2F%2Fwww.facebook.com%2Fkiabystore%2F&tabs=timeline&width=300&height=900&small_header=false&adapt_container_width=true&hide_cover=false&show_facepile=true&appId"  height="500" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowTransparency="true" allow="encrypted-media"></iframe>
                        </div>
                    </div>
            </section>

            <section>
                <h3>Instagram</h3>
                <blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/kiaby.store/" data-instgrm-version="14"></blockquote>
                <script async src="//www.instagram.com/embed.js"></script>
            </section>
     </div>
            <script async src="https://www.tiktok.com/embed.js"></script>

    ';

    echo "
            <br></br>
            <h2 id=\"counter\"></h2>
            <br></br>
            <p id=\"counter_msg\">Por favor, no cierres esta página. Debes esperar a que aparezca el botón de conectarse y hacer clic para tener acceso a Internet.</p>



            ";

    // read_terms();
    flush();
}

function login_page()
{
    $fullname = $email = "";
    $me = $_SERVER['SCRIPT_NAME'];
    $fas = $_GET["fas"];
    $iv = $GLOBALS["iv"];
    $clientip = $GLOBALS["clientip"];
    $clientmac = $GLOBALS["clientmac"];
    $gatewayname = $GLOBALS["gatewayname"];
    $gatewayaddress = $GLOBALS["gatewayaddress"];
    $gatewaymac = $GLOBALS["gatewaymac"];
    $clientif = $GLOBALS["clientif"];
    $client_zone = $GLOBALS["client_zone"];
    $originurl = $GLOBALS["originurl"];


    if (isset($_GET["fullname"])) {
        $fullname = ucwords($_GET["fullname"]);
    }

    if (isset($_GET["email"])) {
        // $email = $_GET["email"];
        $email = "dummy";
    }

    if ($email == "") {
        if (!isset($_GET['fas'])) {
            echo "<br><b style=\"color:red;\">ERROR! Incomplete data passed from NDS</b>\n";
        } else {
            echo '
                        <div class="full-content">
                            <div>
                                <img id="logo-img" src="./src/img/logo-kiaby.png" alt="logo-kiaby">
                                <p style="margin-top:-5px; font-weight: 800;font-size: 1.3rem;	color:#ffffff ;">Tienda de ropa</p>
                            </div>
                            <section id="welcome_msg">
                                <h1 style="font-weight: lighter;">Bienvenido a kiaby.com.co</h1>
                                <h1>WIFI</h1>
                                <img id= "icon_wifi" src="./src/img/wifi-logo-svgrepo-com.svg" alt="">
                                </section>
                              <script>
                                alertMens();
                            </script>


                    ';
            echo "
                            <form action=\"$me\" method=\"get\" >
                                <input type=\"hidden\" name=\"fas\" value=\"$fas\">
                                <input type=\"hidden\" name=\"iv\" value=\"$iv\">
                                <input type=\"hidden\" name=\"email\" value=\"$email\">
                                   <input type=\"submit\" class=\"link-div\" id=\"submitButton\" value=\"Navegar\" >
                                  <label><input type=\"checkbox\" id=\"termsCheckbox\" onclick=\"toggleSubmitButton()\" checked>Acepta los términos de servicio</label>
        
    
                            </form>

                        </div>
                    ";

            read_terms();
            flush();
        }
    } else {
        thankyou_page();
    }
}



function status_page()
{
    $me = $_SERVER['SCRIPT_NAME'];
    $clientip = $GLOBALS["clientip"];
    $clientmac = $GLOBALS["clientmac"];
    $gatewayname = $GLOBALS["gatewayname"];
    $gatewayaddress = $GLOBALS["gatewayaddress"];
    $gatewaymac = $GLOBALS["gatewaymac"];
    $clientif = $GLOBALS["clientif"];
    $originurl = $GLOBALS["originurl"];
    $redir = rawurldecode($originurl);

    // Is the client already logged in?
    if ($_GET["status"] == "authenticated") {
        echo "
			<p><big-red>You are already logged in and have access to the Internet.</big-red></p>
			<hr>
			<p><italic-black>You can use your Browser, Email and other network Apps as you normally would.</italic-black></p>
		";

        read_terms();

        echo "
			<p>
			Your device originally requested <b>$redir</b>
			<br>
			Click or tap Continue to go to there.
			</p>
			<form>
				<input type=\"button\" VALUE=\"Continuar\" onClick=\"location.href='" . $redir . "'\" >
			</form>
		";
    } else {
        echo "
			<p><big-red>ERROR 404 - Page Not Found.</big-red></p>
			<hr>
			<p><italic-black>The requested resource could not be found.</italic-black></p>
		";
    }
    flush();
}

function landing_page()
{
    $me = $_SERVER['SCRIPT_NAME'];
    $fas = $_GET["fas"];
    $iv = $GLOBALS["iv"];
    $originurl = $GLOBALS["originurl"];
    $gatewayaddress = $GLOBALS["gatewayaddress"];
    $gatewayname = $GLOBALS["gatewayname"];
    $gatewayurl = rawurldecode($GLOBALS["gatewayurl"]);
    $gatewayurl_tiktok = "https://www.tiktok.com/@kiaby.store";
    $clientif = $GLOBALS["clientif"];
    $client_zone = $GLOBALS["client_zone"];
    $fullname = $_GET["fullname"];
    $email = $_GET["email"];
    $redir = rawurldecode($originurl);

    echo "
		<p>
			<big-red>
				Ha iniciado sesión y se le ha concedido acceso a Internet.
			</big-red>
		</p>
		<hr>
		<med-blue>You are connected to $client_zone</med-blue><br>
		<p>
			<italic-black>
				Puedes utilizar el navegador, el correo electrónico y otras aplicaciones de red como lo harías normalmente.
			</italic-black>
		</p>
		<p>
		(Your device originally requested $redir)
		<hr>
		Haga clic o toque Continuar para mostrar el estado de su cuenta.
		</p>
		<form>
			<input type=\"button\" VALUE=\"Continuar\" onClick=\"location.href='" . $gatewayurl_tiktok . "'\" >
		</form>
		<hr>
	";

    read_terms();
    flush();
}

function splash_header()
{
    $imagepath = $GLOBALS["imagepath"];
    $gatewayname = $GLOBALS["gatewayname"];
    $gatewayname = htmlentities(rawurldecode($gatewayname), ENT_HTML5, "UTF-8", FALSE);

    // Add headers to stop browsers from cacheing
    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
    header("Cache-Control: no-cache");
    header("Pragma: no-cache");

    // Output the common header html
    echo "<!DOCTYPE html>\n<html>\n<head>
		<meta charset=\"utf-8\" />
		<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
		<link rel=\"shortcut icon\" href=$imagepath type=\"image/x-icon\">
		<title>$gatewayname</title>
        <link rel=\"stylesheet\" href=\"./src/css/styl3.css\">
        <link rel=\"stylesheet\" href=\"./src/css/sweetalert2.min.css\">

        <script src= \"./src/js/jquery.min.js\"></script>
        <script src= \"./src/js/sweetalert2.all.min.js\"></script>
        <script src= \"./src/js/main.js\"></script>
		<style>
	";
    flush();
    // insert_css();
    // flush();
    echo "
		</style>
		</head>
		<body>
		<div class=\"offset\">
		<med-blue>
			$gatewayname
		</med-blue><br>
		<div class=\"insert\">
	";
    flush();
}

function err403()
{
    $imagepath = $GLOBALS["imagepath"];
    // Add headers to stop browsers from cacheing
    header('HTTP/1.1 403 Forbidden');
    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
    header("Cache-Control: no-cache");
    header("Pragma: no-cache");


    echo "<!DOCTYPE html>\n<html>\n<head>
		<meta charset=\"utf-8\" />
		<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
		<link rel=\"shortcut icon\" href=$imagepath type=\"image/x-icon\">
		<title>Forbidden</title>
		<style>
	";
    flush();
    // insert_css();
    // flush();
    echo "
		</style>
		</head>
		<body>
		<div class=\"offset\">
		<div class=\"insert\">
		<hr>
		<b style=\"color:red; font-size:1.5em;\">Encryption Error <br> Access Forbidden</b><br>
	";
    flush();
    footer();
}

function footer()
{
    $imagepath = $GLOBALS["imagepath"];

    if (isset($GLOBALS["version"])) {
        $version = $GLOBALS["version"];
    } else {
        $version = "";
    }

    $year = date("Y");
    echo "
		</div>
		</div>
            <footer>
                <p>Novedades de temporada en la sección de <b>hombres del segundo piso</b>. ¡Visítanos ahora!</p>
            </footer>
            	</body>
		</html>
    ";
    // Portal Version: $version

    exit(0);
}

function read_terms()
{
    #terms of service button
    $me = $_SERVER['SCRIPT_NAME'];
    $fas = $GLOBALS["fas"];
    $iv = $GLOBALS["iv"];

    echo "
        <div class=\"full-content\">
            <form action=\"$me\" method=\"get\">
                <input type=\"hidden\" name=\"fas\" value=\"$fas\">
                <input type=\"hidden\" name=\"iv\" value=\"$iv\">
                <input type=\"hidden\" name=\"terms\" value=\"yes\">
                <input type=\"submit\" class=\"link-terms\" value=\"Lee los terminos del servicio\" >
            </form>
    </div>
	";
}

function display_terms()
{
    # This is the all important "Terms of service"
    # Edit this long winded generic version to suit your requirements.
    ####
    # WARNING #
    # It is your responsibility to ensure these "Terms of Service" are compliant with the REGULATIONS and LAWS of your Country or State.
    # In most locations, a Privacy Statement is an essential part of the Terms of Service.
    ####

    #Privacy
    echo "
		<b>Privacidad</b>
		<br><br>
		
            Al iniciar sesión en el sistema, otorgas tu permiso para que este sistema almacene cualquier dato que proporciones con el propósito de iniciar sesión, junto con los parámetros de red de tu dispositivo que el sistema requiere para funcionar.<br>
            Toda la información se almacena para tu conveniencia y para la protección tanto tuya como nuestra.<br>
            Toda la información recopilada por este sistema se almacena de manera segura y no es accesible por terceros.<br>
            A cambio, te concedemos acceso gratuito a Internet.
		<hr>
	";
    flush();

    # Terms of Service
    echo "
		<b>Condiciones de servicio para este Hotspot</b> <br><br>


        <b>Se otorga acceso en base a la confianza de que NO utilizarás ni abusarás de ese acceso de ninguna manera.</b><hr>

        <b>Por favor, desplázate hacia abajo para leer los Términos de Servicio completos o haz clic en el botón Continuar para regresar a la Página de Aceptación.</b>

		<form>
			<input type=\"button\" VALUE=\"Continuar\" onClick=\"history.go(-1);return true;\">
		</form>
	";
    flush();

    # Proper Use
    echo "
            <hr>
            <b>Uso Adecuado</b>

            <p>
            Este Punto de Acceso proporciona una red inalámbrica que te permite conectarte a Internet. <br>
            <b>El uso de esta conexión a Internet se proporciona a cambio de tu PLENA aceptación de estos Términos de Servicio.</b>
            </p>

            <p>
            <b>Aceptas</b> que eres responsable de proporcionar medidas de seguridad adecuadas para el uso previsto del Servicio.
            Por ejemplo, debes asumir la plena responsabilidad de tomar medidas adecuadas para proteger tus datos contra pérdidas.
            </p>

            <p>
            Aunque el Punto de Acceso realiza esfuerzos comercialmente razonables para proporcionar un servicio seguro,
            la efectividad de esos esfuerzos no puede garantizarse.
            </p>

            <p>
            <b>Puedes</b> utilizar la tecnología proporcionada por este Punto de Acceso únicamente con el fin
            de utilizar el Servicio tal como se describe aquí.
            Debes notificar inmediatamente al Propietario cualquier uso no autorizado del Servicio o cualquier otra violación de seguridad.<br><br>
            Te proporcionaremos una dirección IP cada vez que accedas al Punto de Acceso, y puede cambiar.
            <br>
            <b>No debes</b> programar ninguna otra dirección IP o MAC en tu dispositivo que acceda al Punto de Acceso.
            No puedes usar el Servicio por ninguna otra razón, incluyendo la reventa de cualquier aspecto del Servicio.
            Otros ejemplos de actividades impropias incluyen, entre otros:
            </p>

            <ol>
            <li>
            descargar o cargar volúmenes tan grandes de datos que el rendimiento del Servicio se degrade notablemente para otros usuarios durante un período significativo;
            </li>

            <li>
            intentar romper la seguridad, acceder, alterar o usar áreas no autorizadas del Servicio;
            </li>

            <li>
            eliminar cualquier aviso de derechos de autor, marca comercial u otros derechos de propiedad contenidos en o en el Servicio;
            </li>

            <li>
            intentar recopilar o mantener cualquier información sobre otros usuarios del Servicio
            (incluyendo nombres de usuario y/o direcciones de correo electrónico) u otros terceros con fines no autorizados;
            </li>

            <li>
            iniciar sesión en el Servicio bajo pretensiones falsas o fraudulentas;
            </li>

            <li>
            crear o transmitir comunicaciones electrónicas no deseadas como SPAM o cadenas de correo electrónico a otros usuarios
            o de otro modo interferir con el disfrute del servicio por parte de otros usuarios;
            </li>

            <li>
            transmitir virus, gusanos, defectos, troyanos u otros elementos de naturaleza destructiva; o
            </li>

            <li>
            usar el Servicio para cualquier propósito ilegal, acosador, abusivo, criminal o fraudulento.
            </li>
            </ol>
	";
    flush();

    # Content Disclaimer
    echo "
        <hr>
        <b>Descargo de Responsabilidad de Contenido</b>

        <p>
        Los Propietarios del Punto de Acceso no controlan y no son responsables de los datos, contenidos, servicios o productos
        que se acceden o descargan a través del Servicio.
        Los Propietarios pueden, pero no están obligados a, bloquear transmisiones de datos para proteger al Propietario y al público.
        </p>

        Los Propietarios, sus proveedores y sus licenciantes renuncian expresamente en la mayor medida permitida por la ley,
        a todas las garantías expresas, implícitas y estatutarias, incluidas, entre otras, las garantías de comerciabilidad
        o aptitud para un propósito particular.
        <br><br>
        Los Propietarios, sus proveedores y sus licenciantes renuncian expresamente en la mayor medida permitida por la ley
        a cualquier responsabilidad por infracción de derechos de propiedad y/o infracción de derechos de autor por parte de cualquier usuario del sistema.
        Los detalles de inicio de sesión y las identidades de los dispositivos pueden almacenarse y utilizarse como evidencia en un Tribunal de Justicia contra dichos usuarios.
        <br>
	";
    flush();

    # Limitation of Liability
    echo "

        <hr><b>Limitación de Responsabilidad</b>

        <p>
        En ningún caso los Propietarios, sus proveedores o sus licenciantes serán responsables ante ningún usuario o
        tercero por el uso o abuso de o la confianza en el Servicio.
        </p>

        <hr><b>Cambios en los Términos de Servicio y Terminación</b>

        <p>
        Podemos modificar o terminar el Servicio y estos Términos de Servicio y cualquier política adjunta,
        por cualquier motivo y sin previo aviso, incluido el derecho a terminar con o sin aviso,
        sin responsabilidad hacia usted, ningún usuario o ningún tercero. Por favor, revise estos Términos de Servicio
        de vez en cuando para que esté informado de cualquier cambio.
        </p>

        <p>
        Nos reservamos el derecho de terminar su uso del Servicio, por cualquier motivo y sin previo aviso.
        Tras dicha terminación, todos los derechos otorgados a usted por este Propietario de Punto de Acceso terminarán.
        </p>
	";
    flush();

    # Inemnity
    echo "
        <hr><b>Indemnización</b>
        <p>
        <b>Aceptas</b> indemnizar y eximir de responsabilidad a los Propietarios de este Punto de Acceso,
        sus proveedores y licenciantes, de cualquier reclamo de terceros que surja de
        o esté relacionado de alguna manera con tu uso del Servicio, incluyendo cualquier responsabilidad o gasto derivado de todos los reclamos,
        pérdidas, daños (reales y consecuentes), demandas, juicios, costos de litigios y honorarios legales, de todo tipo y naturaleza.
        </p>

		<hr>
		<form>
			<input type=\"button\" VALUE=\"Continuar\" onClick=\"history.go(-1);return true;\">
		</form>
	";
    flush();
}

// function insert_css()
// {
//     echo "

// ";
//     flush();
// }
