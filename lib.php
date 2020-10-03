<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function eid_easy_get_user_data($code)
{
    $clientId = eid_easy_conf('client_id');
    $secret   = eid_easy_conf('secret');
    $apiUrl   = eid_easy_conf('api_url');
    $redirect = eid_easy_get_current_url();

    // Get access token.
    $ch = curl_init("$apiUrl/oauth/access_token");
    curl_setopt($ch, CURLOPT_POSTFIELDS,
        [
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'redirect_uri'  => $redirect,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ]
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $tokenResponse = curl_exec($ch);
    $responseCode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $accessTokenData = json_decode($tokenResponse, true);
    if ($responseCode !== 200 || !$accessTokenData || !array_key_exists('access_token', $accessTokenData)) {
        logActivity("eID Easy access token failed: $responseCode - " . $tokenResponse, 0);
        return null;
    }

    // Get user data.
    $ch = curl_init("$apiUrl/api/v2/user_data");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $accessTokenData['access_token'],
    ]);
    $userResponse = curl_exec($ch);
    $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $userData = json_decode($userResponse, true);
    if ($responseCode !== 200 || !$userData || !is_array($userData)) {
        logActivity("eID Easy user data failed: $responseCode - " . $userResponse, 0);
        return null;
    }

    return $userData;
}

function eid_easy_get_client_ip()
{
    if (isset($_SERVER) && is_array($_SERVER)) {
        $keys   = array();
        $keys[] = 'HTTP_X_REAL_IP';
        $keys[] = 'HTTP_X_FORWARDED_FOR';
        $keys[] = 'HTTP_CLIENT_IP';
        $keys[] = 'REMOTE_ADDR';

        foreach ($keys as $key) {
            if (isset($_SERVER[$key])) {
                if (preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$/', $_SERVER[$key]) === 1) {
                    return $_SERVER[$key];
                }
            }
        }
    }

    return '';
}

function eid_easy_login_user($userid)
{
    global $cc_encryption_hash;

    $ip_address = eid_easy_get_client_ip();

    // Read user
    $entry = Capsule::table('tblclients')->where('id', '=', $userid)->first();
    if (is_object($entry) && isset($entry->id)) {
        if (!session_id()) {
            session_start();
        }

        // Added in more recent versions of WHMCS.
        if (method_exists('WHMCS\Authentication\Client', 'generateClientLoginHash')) {
            $_SESSION['uid'] = $entry->id;
            $_SESSION['upw'] = WHMCS\Authentication\Client::generateClientLoginHash($entry->id, '', $entry->password, $entry->email);
        } else {
            $_SESSION['uid'] = $entry->id;
            $_SESSION['upw'] = sha1($entry->id . $entry->password . $ip_address . substr(sha1($cc_encryption_hash), 0, 20));
        }

        // Persist
        session_write_close();


        $userid    = $entry->id;
        $ipaddress = $_SERVER['REMOTE_ADDR'];
        $host      = gethostbyaddr($ipaddress);
        $desc      = "eID Easy login from: $host";
        $user      = "Client";
        $nowTS     = date("Y-m-d H:i:s");

        $dataadd = ["date" => $nowTS, "userid" => $userid, "ipaddr" => $ipaddress, "description" => $desc, "user" => $user];
        Capsule::table("tblactivitylog")->insert($dataadd);
        Capsule::table('tblclients')->where('id', $entry->id)->update(['ip' => $ipaddress, 'lastlogin' => $nowTS, 'host' => $host]);

        // User logged in
        return true;
    }

    return false;
}

function eid_easy_get_existing_user($userData)
{
    $customField = Capsule::table('tblcustomfields')->where('fieldname', eid_easy_conf('custom_field_name'))->first();
    if (!$customField) {
        return null;
    }

    $fieldValue = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $customField->id)
        ->where('value', $userData['idcode'])
        ->first();

    if (!$fieldValue) {
        return null;
    }

    $user = Capsule::table('tblclients')->where('id', $fieldValue->relid)->where('country', $userData['country'])->first();
    if (!$user) {
        return null;
    }

    return $user->id;
}

function eid_easy_create_user($userData)
{
    $client_data              = array();
    $client_data['firstname'] = $userData['firstname'];
    $client_data['lastname']  = $userData['lastname'];
    $client_data['password2'] = bin2hex(openssl_random_pseudo_bytes(40));
    $client_data['country']   = $userData['country'];

    // Pass as true to ignore required fields validation.
    $client_data['skipvalidation'] = true;
    $client_data['noemail']        = true;

    // Admin Username.
    $admin_username = eid_easy_get_admin_username();

    // Add Client.
    $result = localAPI('AddClient', $client_data, $admin_username);
    if (is_array($result) && !empty($result['clientid'])) {
        $customField = Capsule::table('tblcustomfields')->where('fieldname', eid_easy_conf('custom_field_name'))->first();
        if (!$customField) {
            return null;
        }
        Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $customField->id, 'value' => $userData['idcode'], 'relid' => $result['clientid']]);

        return $result['clientid'];
    }
    logActivity("User create failed - " . json_encode($result));

    return null;
}

function eid_easy_get_admin_username()
{
    $username = null;

    $entry = Capsule::table('tbladmins')->select('username')->where('roleid', '=', 1)->first();
    if (is_object($entry) && isset($entry->username)) {
        $username = $entry->username;
    }

    return $username;
}

function eid_easy_conf($key)
{
    $table  = Capsule::table('tbladdonmodules');
    $result = $table->where('module', '=', 'eid_easy')->where('setting', $key)->first();
    if (!$result) {
        return null;
    }
    return $result->value;
}

// Returns the current url
function eid_easy_get_current_url()
{
    // Extract parts
    $request_uri      = $_SERVER['PHP_SELF'];
    $request_protocol = (eid_easy_login_is_https_on() ? 'https' : 'http');
    $request_host     = (isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']));

    // Port of this request
    $request_port = '';

    // We are using a proxy
    if (isset($_SERVER['HTTP_X_FORWARDED_PORT'])) {
        // SERVER_PORT is usually wrong on proxies, don't use it!
        $request_port = intval($_SERVER['HTTP_X_FORWARDED_PORT']);
    } // Does not seem like a proxy
    elseif (isset($_SERVER['SERVER_PORT'])) {
        $request_port = intval($_SERVER['SERVER_PORT']);
    }

    // Remove standard ports
    $request_port = (!in_array($request_port, array(80, 443)) ? $request_port : '');

    // Build url
    $current_url = $request_protocol . '://' . $request_host . (!empty($request_port) ? (':' . $request_port) : '') . $request_uri;

    return $current_url;
}

function eid_easy_login_is_https_on()
{
    if (!empty($_SERVER['SERVER_PORT'])) {
        if (trim($_SERVER['SERVER_PORT']) == '443') {
            return true;
        }
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        if (strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO'])) == 'https') {
            return true;
        }
    }

    if (!empty($_SERVER['HTTPS'])) {
        if (strtolower(trim($_SERVER['HTTPS'])) == 'on' or trim($_SERVER['HTTPS']) == '1') {
            return true;
        }
    }

    return false;
}

function eid_easy_providers()
{
    return [
        [
            'action_type' => 'ee-id-card',
            'name'        => 'Estonian ID kaart',
            'icon'        => 'eid_idkaart_mark.png'
        ],
        [
            'action_type' => 'lv-id-card',
            'name'        => 'Latvian ID kaart',
            'icon'        => 'latvia-id-card.png',
        ],
        [
            'action_type' => 'lt-id-card',
            'name'        => 'Lithuanian ID kaart',
            'button_text' => 'AT kortelė',
        ], [
            'action_type' => 'be-id-card',
            'name'        => 'Belgium ID kaart',
            'icon'        => 'belgia-id-card.svg',
        ], [
            'action_type' => 'mid-login',
            'name'        => 'Mobile-ID',
            'icon'        => 'eid_mobiilid_mark.png',
        ], [
            'action_type' => 'lt-mobile-id',
            'name'        => 'Lithuania Mobile-ID',
            'button_text' => 'M. parašas',
        ], [
            'action_type' => 'smartid',
            'name'        => 'Smart-ID',
            'icon'        => 'Smart-ID_login_btn.png',
        ], [
            'action_type' => 'lv-eparaksts-mobile-login',
            'name'        => 'Latvian eParaksts Mobile',
            'icon'        => 'eparaksts-mobile.png',
        ]
    ];
}