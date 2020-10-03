<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// Include Tools
require_once realpath(dirname(__FILE__)) . '/lib.php';

use WHMCS\Database\Capsule;

function eid_easy_login_html()
{
    global $CONFIG;
    $clientId = eid_easy_conf('client_id');
    if (strlen($clientId) < 5) {
        return "<strong>ERROR: eID Easy client ID is missing</strong>";
    }
    $secret = eid_easy_conf('secret');
    if (strlen($secret) < 5) {
        return "<strong>ERROR: eID Easy secret is missing</strong>";
    }
    $apiUrl = eid_easy_conf('api_url');
    if (strlen($apiUrl) < 5) {
        return "<strong>ERROR: eID Easy API url is missing</strong>";
    }
    $fieldName = eid_easy_conf('custom_field_name');
    if (strlen($fieldName) < 2) {
        return "<strong>ERROR: eID Easy ID code field name missing</strong>";
    }

    $redirect     = eid_easy_get_current_url();
    $state        = "eideasy_" . bin2hex(openssl_random_pseudo_bytes(10));
    $authorizeUrl = "$apiUrl/oauth/authorize?client_id=$clientId&redirect_uri=$redirect&response_type=code&state=$state";

    $lang     = "en";
    $sessLang = isset($_SESSION['Language']) ? $_SESSION['Language'] : null;
    if ($sessLang === "estonian") {
        $lang = "et";
    } elseif ($sessLang === "russian") {
        $lang = "ru";
    } elseif ($sessLang === "latvian") {
        $lang = "lv";
    } elseif ($sessLang === "lithuanian") {
        $lang = "lt";
    }

    $providers = eid_easy_providers();

    $authorizeHtml = '<style>.eid-easy-button {height: 46px;margin: 5px}</style>';
    foreach ($providers as $data) {
        if (!eid_easy_conf("provider_" . $data['action_type'])) {
            continue;
        }
        if (array_key_exists('icon', $data)) {
            $buttonValue = "<img src=\"/modules/addons/eid_easy/img/" . $data['icon'] . "\" class=\"eid-easy-button\">";
        } else {
            $buttonValue = "<button  value=\"" . $data['button_text'] . "\" class=\"eid-easy-button btn btn-social\">" . $data['button_text'] . "</button>";
        }
        $authorizeHtml .= "<a href=\"$authorizeUrl&method=" . $data['action_type'] . "&start=" . $data['action_type'] . "&lang=" . $lang . "\">$buttonValue</a>";
    }
    $authorizeHtml .= "<br>";


    $code  = array_key_exists('code', $_GET) ? $_GET['code'] : null;
    $state = array_key_exists('state', $_GET) ? $_GET['state'] : null;
    if ($code && $state && substr($state, 0, 8) === "eideasy_") {
        $userData = eid_easy_get_user_data($code);
        if (!$userData) {
            return "<strong>eID Login failed</strong></br> $authorizeHtml";
        }

        $userId = eid_easy_get_existing_user($userData);
        if (!$userId) {
            $userId = eid_easy_create_user($userData);
        }

        if (eid_easy_login_user($userId)) {
            $redirect_to = eid_easy_get_current_url();

            // Redirect
            header("Location: " . $redirect_to);
            exit;
        }

        logActivity("User login failed $userId.", 0);
        return "<strong>eID Login failed</strong></br>.$authorizeHtml";
    }

    return $authorizeHtml;
}


// Adds the shortcodes to display the eID Easy login icons
function eid_easy_shortcodes()
{
    return [
        'eid_easy_login_html' => eid_easy_login_html(),
    ];
}

add_hook('AddonConfig', 1, function ($vars) {
    logActivity("AddonConfig", 0);
});

//Obtain the values defined in the AddonConfig hook point and save them as required
add_hook('AddonConfigSave', 1, function ($vars) {
    logActivity("AddonConfigSave", 0);
});

add_hook("ClientAreaPage", 1, "eid_easy_shortcodes");