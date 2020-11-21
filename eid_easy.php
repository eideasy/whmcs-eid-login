<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once realpath(dirname(__FILE__)) . '/lib.php';
use WHMCS\Database\Capsule;

function eid_easy_config()
{
    $configarray = [
        "name"        => "eID Easy login",
        "description" => "Register and identify users with eID methods, for example Estonian, Latvian, Lithuanian and Belgian ID cards, Mobile-ID solutions and Smart-ID app",
        "version"     => "2.1",
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

function eid_easy_upgrade($vars)
{
    $currentlyInstalledVersion = $vars['version'];

    if (version_compare('1.2', $currentlyInstalledVersion)) {
        if (!Capsule::schema()->hasTable('mod_eideasy_users')) {
            Capsule::schema()
                ->create(
                    'mod_eideasy_users',
                    function ($table) {
                        /** @var \Illuminate\Database\Schema\Blueprint $table */
                        $table->increments('id');
                        $table->text('idcode');
                        $table->text('country');
                        $table->text('firstname');
                        $table->text('lastname');
                        $table->integer('user_id')->unsigned();
                        $table->foreign('user_id')->references('id')->on('tblusers')->cascadeOnDelete();
                    }
                );
        }
    }
}

function eid_easy_activate()
{
    logActivity("eID Easy module activated, creating database");
    // Create custom tables and schema required by your module
    try {
        if (!Capsule::schema()->hasTable('mod_eideasy_users')) {
            Capsule::schema()
                ->create(
                    'mod_eideasy_users',
                    function ($table) {
                        /** @var \Illuminate\Database\Schema\Blueprint $table */
                        $table->increments('id');
                        $table->text('idcode');
                        $table->text('country');
                        $table->text('firstname');
                        $table->text('lastname');
                        $table->integer('user_id')->unsigned();
                        $table->foreign('user_id')->references('id')->on('tblusers')->cascadeOnDelete();
                    }
                );
        }
        return [
            // Supported values here include: success, error or info
            'status'      => 'success',
            'description' => 'eID Easy database created',
        ];
    } catch (\Exception $e) {
        return [
            // Supported values here include: success, error or info
            'status'      => "error",
            'description' => 'Unable to create eID Easy table mod_eideasy_users: ' . $e->getMessage(),
        ];
    }
}

