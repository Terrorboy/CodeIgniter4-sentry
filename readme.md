# CodeIgniter4 sentry

1. composer require sentry/sdk
2. Modify `app/config/Autoload.php`
```
$classmap = [
    'CodeIgniter\Log\Logger' => APPPATH.'ThirdParty/Sentry.php', // Sentry
];
```
3. Modify `app/ThirdParty/Sentry.php`
```
define('sentryDSN', '__DSN__');
```