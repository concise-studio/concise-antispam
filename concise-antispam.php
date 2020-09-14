<?php
/*
Plugin Name: Concise Antispam
Description: Concise Antispam
Author: Concise Studio
Author URI: https://concise-studio.com
Version: 1.0.3
Description: Very simple antispam plugin. Users do not need to enter any captcha, works completely on background. No need to administer: just install the plugin and site will be protected. Fully compatible with Contact Form 7.
*/





/* Core autoload */
spl_autoload_register(function($class) {
    $prefix = "Concise";
    $baseDir = __DIR__ . "/Core/";
    $length = strlen($prefix);
    
    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $relativeClass = substr($class, $length);
    $file = $baseDir . str_replace("\\", "/", $relativeClass) . ".php";
    
    if (file_exists($file)) {
        require $file;
    }
});





/* Install */
register_activation_hook(__FILE__, ["\Concise\Antispam", "install"]);





/* Uninstall */
register_uninstall_hook(__FILE__, ["\Concise\Antispam", "uninstall"]);





/* Init the componenet */
\Concise\Antispam::init();
