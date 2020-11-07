# eID Easy integration for WHMCS
This module enables national eID Methods/ID cards to be used for WHMCS login and user registration

## Installation
1. Get working WHMCS application. Only WHMCS is 8 supported. For version 7 support go to https://github.com/eideasy/whmcs-eid-login/tree/whmcs-version-7
2. Copy the code into /modules/addons/eid_easy
3. Activate module from Configuration > System Settings > Addon Modules
4. Get client_id and secret from https://eideasy.com . Register domain/clientarea.php, domain/affiliates.php and any other pages where you want to display the eID login methods
5. Save client_id, secret and desired eID login methods under the configuration
6. Add below code to the login.tpl where the login buttons should appear

```
{if !$loggedin}
    {$eid_easy_login_html}
{/if}
```

## Supported methods
- Estonian ID card
- Latvian ID card
- Lithuanian ID card
- Belgium ID card
- Smart-ID mobile app (Estonia, Latvia, Lithuania)
- Mobile-id (Estonia, Lithuania)
- Latvian eParaksts Mobile
- Others from OAuth 2.0 login view like Portugese ID card and Serbian ID card.
