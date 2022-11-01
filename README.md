## Description

Very simple antispam plugin.
Users do not need to enter any captcha, working completely on background.
No need to administer: just install the plugin and site will be protected.
Fully compatible with Contact Form 7 and Formidable Forms*.

##  Algorithm
1. Plugin generates token on the backend side.
2. Plugin adds hidden field for token in each form which will use method "POST" after rendering of the page.
3. Plugin inserts token in the field after user's interaction with site.

## For developers

Antispam will automatically check submissions of the regular forms but ignores all AJAX requests.
If you want to change this behaviour, you can use filter `concise_antispam_need_to_validate_token`. 
See example:

_Disable Antispam for some forms:_
```php
add_filter("concise_antispam_need_to_validate_token", function($needToValidateToken) {
    if (!empty($_POST['do-not-check-antispam-token'])) {
        $needToValidateToken = false;
    }
    
    return $needToValidateToken;
});
```

You can also always manually call `\Concise\Antispam::validateTokenOrDie()` in your custom handler.

## Installation

1. Upload plugin to plugins directory
2. Active plugin through the "Plugins" menu in WordPress

## Formidable Forms integration

For proper work disable the honeypot checking in the forms

## Frequently Asked Questions

Please, report about any bug to developer@concise-studio.com
