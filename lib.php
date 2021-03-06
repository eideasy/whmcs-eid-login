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

    return '-';
}

function eid_easy_login_user($userId)
{
    $ipaddress = eid_easy_get_client_ip();

    // Read user
    $entry = Capsule::table('tblusers')->where('id', '=', $userId)->first();
    if (is_object($entry) && isset($entry->id)) {
        $clientId = eid_easy_get_first_client($userId);
        if (!$clientId) {
            $clientId = eid_easy_add_default_client($userId);
        }

        $command  = 'CreateSsoToken';
        $postData = [
            'client_id' => $clientId,
            'user_id'   => $userId,
        ];

        $postData['client_id'] = $clientId;
        $result                = localAPI($command, $postData);

        if (!is_array($result) || !$result['result'] === "success") {
            logActivity("eID Easy login token creation failed: " . json_encode($result));
            return false;
        }

        $userId = $entry->id;
        $host   = gethostbyaddr($ipaddress) ?: "-";
        $desc   = "eID Easy login user=$userId client=$clientId from: $host";
        $user   = "Client";
        $nowTS  = date("Y-m-d H:i:s");

        $dataadd = ["date" => $nowTS, "userid" => $userId, "ipaddr" => $ipaddress, "description" => $desc, "user" => $user];
        Capsule::table("tblactivitylog")->insert($dataadd);
        Capsule::table('tblusers')->where('id', $userId)->update(['last_ip' => $ipaddress, 'last_login' => $nowTS, 'last_hostname' => $host]);

        // User logged in
        return $result['redirect_url'];
    }

    return false;
}

function eid_easy_get_first_client($userId)
{
    $clientUser = Capsule::table('tblusers_clients')->where('auth_user_id', $userId)->first();
    if ($clientUser) {
        return $clientUser->client_id;
    }
    return null;
}

function eid_easy_get_existing_user($userData)
{
    $eidEasyUser = Capsule::table('mod_eideasy_users')
        ->where('idcode', $userData['idcode'])
        ->where('country', $userData['country'])
        ->first();

    if (!$eidEasyUser) {
        return null;
    }

    $user = Capsule::table('tblusers')->find($eidEasyUser->user_id);
    if (!$user) {
        return null;
    }

    $client = eid_easy_get_first_client($user->id);
    // We have existing user but it has no clients. Broken user.
    if (!$client) {
        // Using custom client fields is deprecated since WHMCS 8.
        $customField = Capsule::table('tblcustomfields')->where('fieldname', 'Kontaktisiku isikukood')->first();
        if ($customField) {
            $customFieldValue = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $customField->id)->where('value', $userData['idcode'])->first();
            if ($customFieldValue) {
                // Delete duplicate user
                Capsule::table('mod_eideasy_users')
                    ->where('idcode', $userData['idcode'])
                    ->where('country', $userData['country'])
                    ->delete();
                Capsule::table('tblusers')->where('id', $eidEasyUser->user_id)->delete();

                // Create new user (That will be connected the the old client)
                return eid_easy_create_user($userData);
            }
        }
    }

    return $user->id;
}

function eid_easy_create_user($userData)
{
    $client_data              = [];
    $client_data['firstname'] = $userData['firstname'];
    $client_data['lastname']  = $userData['lastname'];
    $client_data['password2'] = bin2hex(openssl_random_pseudo_bytes(40));
    if ($userData['country'] == 'EE') {
        $client_data['email'] = $userData['idcode'] . "@eesti.ee";
    } else {
        $client_data['email'] = $userData['country'] . "-" . $userData['idcode'] . "@placeholder.localhost";
    }

    logActivity("eID Easy creating new user: " . json_encode($userData));

    // Using custom client fields is deprecated since WHMCS 8.
    $customField = Capsule::table('tblcustomfields')->where('fieldname', 'Kontaktisiku isikukood')->first();
    if ($customField) {
        $customFieldValue = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $customField->id)->where('value', $userData['idcode'])->first();
        if ($customFieldValue) {
            $clientid  = $customFieldValue->relid;
            $userCount = Capsule::table('tblusers_clients')->where('client_id', $clientid)->count();
            if ($userCount > 1) {
                logActivity("eID Easy invalid number of client users:" . $userCount);
                return null;
            } elseif ($userCount === 0) {
                // Client without users and idcode matches with current login account
                // Create user and connect with this client
                $result = localApi('AddUser', $client_data);
                if (!is_array($result) || empty($result['user_id'])) {
                    logActivity("eID Easy User create failed 2 - " . json_encode($result));

                    return null;
                }
                Capsule::table('tblusers_clients')->insert([
                    'auth_user_id' => $result['user_id'],
                    'client_id'    => $clientid,
                    'owner'        => 1,
                ]);
            }

            // User exists and client exists but WHMCS 8.X style mapping is missing. Create mapping.
            $clientUser = Capsule::table('tblusers_clients')->where('client_id', $clientid)->first();
            if ($clientUser) {
                Capsule::table('mod_eideasy_users')->insert([
                    'user_id'   => $clientUser->auth_user_id,
                    'idcode'    => $userData['idcode'],
                    'country'   => $userData['country'],
                    'firstname' => $userData['firstname'],
                    'lastname'  => $userData['lastname']
                ]);

                return $clientUser->auth_user_id;
            }
        }
    }

    $result = localApi('AddUser', $client_data);
    if (!is_array($result) || empty($result['user_id'])) {
        logActivity("eID Easy User create failed - " . json_encode($result));

        return null;
    }

    logActivity("eID Easy User created: " . json_encode($result));
    $userId = $result['user_id'];

    eid_easy_add_default_client($userId);

    Capsule::table('mod_eideasy_users')->insert([
        'user_id'   => $result['user_id'],
        'idcode'    => $userData['idcode'],
        'country'   => $userData['country'],
        'firstname' => $userData['firstname'],
        'lastname'  => $userData['lastname']
    ]);

    return $userId;
}

// Needed due CORE-16167
function eid_easy_add_default_client($userId)
{
    $user = Capsule::table('tblusers')->find($userId);

    $client_data = [
        'owner_user_id'  => $user->id,
        'skipvalidation' => true,
        'country'        => 'EE'
    ];
    $result      = localApi('AddClient', $client_data);
    if (!is_array($result) || empty($result['clientid'])) {
        logActivity("eID Easy default Client create failed - " . json_encode([$result]));

        return null;
    }

    return $result['clientid'];
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
    $request_uri      = parse_url($_SERVER['REQUEST_URI'])['path'];
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
            'name'        => 'Estonian ID card',
            'icon'        => 'eid_idkaart_mark.png'
        ],
        [
            'action_type' => 'lv-id-card',
            'name'        => 'Latvian ID card',
            'icon'        => 'latvia-id-card.png',
        ],
        [
            'action_type' => 'lt-id-card',
            'name'        => 'Lithuanian ID card',
            'button_text' => 'AT kortelė',
        ], [
            'action_type' => 'be-id-card',
            'name'        => 'Belgium ID card',
            'icon'        => 'belgia-id-card.svg',
        ], [
            'action_type' => 'mid-login',
            'name'        => 'Estonian Mobile-ID',
            'icon'        => 'eid_mobiilid_mark.png',
        ], [
            'action_type' => 'lt-mobile-id',
            'name'        => 'Lithuania Mobile-ID',
            'button_text' => 'M. parašas',
        ], [
            'action_type' => 'smartid',
            'name'        => 'Smart-ID mobile app',
            'icon'        => 'Smart-ID_login_btn.png',
        ], [
            'action_type' => 'lv-eparaksts-mobile-login',
            'name'        => 'Latvian eParaksts Mobile app',
            'icon'        => 'eparaksts-mobile.png',
        ]
    ];
}
