# eID Easy integration for WHMCS

## Installation
1. Get working WHMCS application. Tested with version 7.10
2. Copy the code into /modules/addons/eid_easy
3. Activate module from Setup > Addon Modules
4. Get client_id and secret from https://eideasy.com . Register domain/clientarea.php, domain/affiliates.php and any other pages where you want to display the eID login methods
5. Save client_id, secret and desired eID login methods under the configuration
6. Add below code to the login.tpl where the login buttons should appear

```
{if !$loggedin}
    {eid_easy_login_html}<br />
{/if}
```