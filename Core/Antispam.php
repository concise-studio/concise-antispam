<?php
namespace Concise;

class Antispam
{   
    const JS_TOKEN_VAR_NAME = "antispamToken";
    const REQUEST_BODY_TOKEN_VAR_NAME = "antispam_token";
    
    
    
    
    
    public static function init()
    {
        Antispam::createTokenAndIncludeInContent();
        Antispam::initCf7(); // automatically init integration for Contact Form 7

        if (Antispam::needToValidateToken()) {
            Antispam::validateTokenOrDie();
        } 
    }
    
    public static function validateTokenOrDie()
    {
        Antispam::clearExpiredTokens(); // that is not necessary and can be moved to cron
                    
        try {
            Antispam::validateToken();
        } catch (\RuntimeException $e) {
            error_log("Prevented attempt to send form. Message: {$e->getMessage()}. Request body: " . json_encode($_POST));
            header("{$_SERVER['SERVER_PROTOCOL']} 400 Bad Request", true, 400);
            die("Request failed to pass antispam checking");
        }        
    }
    
    public static function initCf7()
    {
        add_action("wpcf7_before_send_mail", ["\Concise\Antispam", "validateTokenOrDie"], 1);
    }
    
    public static function install()
    {
        global $wpdb;

        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}antispam_token (
                `code` char(64) NOT NULL,
                `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,  
                PRIMARY KEY (`code`)
            ) {$wpdb->get_charset_collate()}
        ");
    }
    
    public static function uninstall()
    {
        global $wpdb;

        $wpdb->query("
            DROP TABLE IF EXISTS {$wpdb->prefix}antispam_token
        ");
    }
    
    
    
    
    
    public static function needToValidateToken()
    {
        $needToValidateToken = (
            $_SERVER['REQUEST_METHOD'] === "POST" && // validate token only if it is POST request
            !Antispam::isWpLogin() && // do not validate wp-login.php page
            !\is_admin() && // do not validate if user is in admin panel
            Antispam::isRegularForm() // check content type, to make sure it is regualar form and not block ajax requests
        );        
        $needToValidateToken = (bool)apply_filters("concise_antispam_need_to_validate_token", $needToValidateToken);
        
        return $needToValidateToken;
    }
    
    public static function isWpLogin() 
    {
        $loginPath = rtrim(strtolower(parse_url(wp_login_url("", true), PHP_URL_PATH)), "/");
        $isWpLogin = rtrim(strtolower($_SERVER['REQUEST_URI']), "/") == $loginPath;

        return $isWpLogin;
    }
    
    public static function isRegularForm()
    {
        $isRegularForm = false;        
        $regularFormContentTypes = [
            "application/x-www-form-urlencoded",
            "multipart/form-data"
        ];        
        $ajaxHeaders = [
            'X-Requested-With' => "XMLHttpRequest",
            'Accept' => "application/json"
        ];
        $requestContentType = strtolower($_SERVER['CONTENT_TYPE']);
        $requestHeaders = getallheaders();
        
        // check that the content type is in allowlist
        foreach ($regularFormContentTypes as $regularFormContentType) {
            if (strpos($requestContentType, $regularFormContentType) !== false) {
                $isRegularForm = true;
                break;
            }
        }
        
        // check that headers and their values are not in denylist
        foreach ($ajaxHeaders as $ajaxHeaderName=>$ajaxHeaderValue) {
            if (
                array_key_exists($ajaxHeaderName, $requestHeaders) &&
                strpos($requestHeaders[$ajaxHeaderName], $ajaxHeaderValue) !== false
            ) {
                $isRegularForm = false;
                break;
            }
        }
        
        return $isRegularForm;
    }




   
    private static function createTokenAndIncludeInContent()
    {
        $jsVarName = Antispam::JS_TOKEN_VAR_NAME;
        $bodyVarName = Antispam::REQUEST_BODY_TOKEN_VAR_NAME;
        $token = Antispam::generateToken();
        
        Antispam::saveToken($token);
        
        // Publish token if some handlers wish to include token manually
        add_action("wp_footer", function() use ($jsVarName, $token) {  
            echo "<script> var {$jsVarName} = '{$token}'; </script> " . PHP_EOL;
        });
        
        // Include token in the all forms after loading of the page + 1 second
        add_action("wp_footer", function() use ($bodyVarName, $token) {  
            echo "
                <script> 
                    window.addEventListener('load', function() { 
                        var forms = document.querySelectorAll('form');

                        forms.forEach(function(form) { 
                            var method = form.getAttribute('method') || 'get';
                            if (method.toLowerCase() === 'post') {
                                var input = document.createElement('input');
                                input.setAttribute('type', 'hidden');
                                input.setAttribute('name', '{$bodyVarName}');
                                form.appendChild(input);
                            }
                        });   
                    });
                </script> 
            " . PHP_EOL;
        });
        
        // Fill token only after user's interaction + 1 second
        add_action("wp_footer", function() use ($bodyVarName, $jsVarName) {  
            echo "
                <script> 
                    function setAntispamTokens() {                        
                        setTimeout(function() {
                            var inputs = document.querySelectorAll('input[name=\"{$bodyVarName}\"]');

                            inputs.forEach(function(input) { 
                                input.value = {$jsVarName};
                            });   
                        }, 1000);
                        
                        window.removeEventListener('click', setAntispamTokens);
                    }
                
                    window.addEventListener('click', setAntispamTokens);                
                </script> 
            " . PHP_EOL;
        });
    }
    
    private static function validateToken()
    {
        $token = Antispam::fetchTokenFromRequestBody();
        
        if (empty($token)) {
            throw new \RuntimeException("Antispam Token not found in request body");
        }
        
        if (strlen($token) !== 64) {
            throw new \RuntimeException("Invalid Antispam Token");
        }
        
        global $wpdb;
        
        $isValid = (bool)$wpdb->get_var($wpdb->prepare("
            SELECT 
                COUNT(`code`) 
            FROM 
                {$wpdb->prefix}antispam_token
            WHERE 1
                AND `code` = %s
                AND `created` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ", $token));
        
        if (!$isValid) {
            throw new \RuntimeException("Antispam Token is invalid");
        }
        
        return true;
    }
    
    private static function saveToken(string $token)
    {
        if (strlen($token) !== 64) {
            throw new \RuntimeException("Unable to save token {$token} because expected length of the token is 64 symbols but " . strlen($token) . " given");
        }
        
        global $wpdb;
        
        return $wpdb->query($wpdb->prepare("
            INSERT INTO {$wpdb->prefix}antispam_token (`code`) VALUES (%s)
        ", $token));
    }
    
    private static function generateToken(int $length=64)
    {
        $bytesLength = ($length-($length%2))/2;
        
        if (function_exists("random_bytes")) {
            return bin2hex(random_bytes($bytesLength));
        } elseif (function_exists("mcrypt_create_iv")) {
            return bin2hex(mcrypt_create_iv($bytesLength, MCRYPT_DEV_URANDOM));
        } elseif (function_exists("openssl_random_pseudo_bytes")) {
            return bin2hex(openssl_random_pseudo_bytes($bytesLength));
        } 
        
        throw \Exception("There is no function to generate random sting");        
    }
    
    private static function clearExpiredTokens()
    {
        global $wpdb;

        return $wpdb->query("
            DELETE 
            FROM 
                {$wpdb->prefix}antispam_token 
            WHERE 
                `created` < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");        
    }
    
    private static function fetchTokenFromRequestBody()
    {
        $tokenVarName = Antispam::REQUEST_BODY_TOKEN_VAR_NAME;
        $token = null;
        
        if (array_key_exists($tokenVarName, $_POST)) {
            $token = sanitize_text_field($_POST[$tokenVarName]);
        }
        
        return $token;
    }
}
