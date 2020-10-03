<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once realpath(dirname(__FILE__)) . '/lib.php';

function eid_easy_config()
{
    $configarray = [
        "name"        => "eID Easy login",
        "description" => "Register and identify users with eID methods, for example Estonian, Latvian, Lithuanian and Belgian ID cards, Mobile-ID solutions and Smart-ID app",
        "version"     => "1.1",
        "author"      => "eID Easy",
        "language"    => "english",
        "fields"      => []
    ];

    $configarray['fields']['client_id'] = [
        "FriendlyName" => "eID Easy client_id",
        "Type"         => "text",
        "Size"         => "30",
        "Description"  => 'client_id will be created after registering <a href="https://id.eideasy.com/" target="_blank"><strong>eID Easy account</strong></a>.',
        "Default"      => ""
    ];

    $configarray['fields']['secret'] = [
        "FriendlyName" => "eID Easy secret",
        "Type"         => "password",
        "Size"         => "30",
        "Description"  => 'secret will be created after registering <a href="https://id.eideasy.com/" target="_blank"><strong>eID Easy account</strong></a>.',
        "Default"      => ""
    ];

    $configarray['fields']['api_url'] = [
        "FriendlyName" => "eID Easy API url",
        "Type"         => "text",
        "Size"         => "30",
        "Description"  => 'Default https://id.eideasy.com should work just fine unless you are using test environment',
        "Default"      => "https://id.eideasy.com"
    ];

    $configarray['fields']['custom_field_name'] = [
        "FriendlyName" => "ID code field name",
        "Type"         => "text",
        "Size"         => "30",
        "Description"  => 'Custom field name where to store user ID code (Setup > Custom client fields)',
        "Default"      => "idcode"
    ];

    // Read Providers
    $providers = eid_easy_providers();

    // Add to settings
    foreach ($providers as $data) {
        // Layout
        $configarray['fields']['provider_' . $data['action_type']] = [
            "FriendlyName" => $data['name'],
            "Type"         => "yesno",
            "Description"  => "Tick to enable " . $data['name'],
            "Default"      => 1
        ];
    }

    return $configarray;
}

