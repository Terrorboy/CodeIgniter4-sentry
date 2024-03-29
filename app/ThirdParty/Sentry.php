<?php namespace CodeIgniter\Log;

define('sentryDSN', '__DSN__');

use Psr\Log\LoggerInterface;
use CodeIgniter\Log\Exceptions\LogException;

/**
 * The CodeIgntier Logger
 *
 * The message MUST be a string or object implementing __toString().
 *
 * The message MAY contain placeholders in the form: {foo} where foo
 * will be replaced by the context data in key "foo".
 *
 * The context array can contain arbitrary data, the only assumption that
 * can be made by implementors is that if an Exception instance is given
 * to produce a stack trace, it MUST be in a key named "exception".
 *
 * @package CodeIgniter\Log
 */
class Logger implements LoggerInterface
{
    protected $logPath;
    protected $logLevels = [
        'emergency' => 1,
        'alert'     => 2,
        'critical'  => 3,
        'error'     => 4,
        'warning'   => 5,
        'notice'    => 6,
        'info'      => 7,
        'debug'     => 8,
    ];
    protected $loggableLevels = [];
    protected $filePermissions = 0644;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $fileExt;
    protected $handlers = [];
    protected $handlerConfig = [];
    public $logCache;
    protected $cacheLogs = false;
    protected $sentryDSN;

    //--------------------------------------------------------------------

    /**
     * Constructor.
     *
     * @param  \Config\Logger $config
     * @param  boolean        $debug
     * @throws \RuntimeException
     */
    public function __construct($config, bool $debug = CI_DEBUG)
    {
        $this->loggableLevels = is_array($config->threshold) ? $config->threshold : range(1, (int) $config->threshold);

        // Now convert loggable levels to strings.
        // We only use numbers to make the threshold setting convenient for users.
        if ($this->loggableLevels) {
            $temp = [];
            foreach ($this->loggableLevels as $level) {
                $temp[] = array_search((int) $level, $this->logLevels);
            }

            $this->loggableLevels = $temp;
            unset($temp);
        }

        $this->dateFormat = $config->dateFormat ?? $this->dateFormat;

        if (! is_array($config->handlers) || empty($config->handlers)) {
            throw LogException::forNoHandlers('LoggerConfig');
        }

        // Save the handler configuration for later.
        // Instances will be created on demand.
        $this->handlerConfig = $config->handlers;

        $this->cacheLogs = $debug;
        if ($this->cacheLogs) {
            $this->logCache = [];
        }

        // LDD - snetry(composer require sentry/sdk)
        if (defined('sentryDSN') === true) {
            if (sentryDSN != '__DSN__') {
                \Sentry\init(['dsn' => sentryDSN ]);
                \Sentry\captureLastError();
            }
        }
    }

    //--------------------------------------------------------------------

    /**
     * System is unusable.
     *
     * @param string $message
     * @param array  $context
     *
     * @return boolean
     */
    public function emergency($message, array $context = []): bool
    {
        return $this->log('emergency', $message, $context);
    }

    //--------------------------------------------------------------------

    /**
     * Action must be taken immediately.
     *
     * Example: Entire website down, database unavailable, etc. This should
     * trigger the SMS alerts and wake you up.
     *
     * @param string $message
     * @param array  $context
     *
     * @return boolean
     */
    public function alert($message, array $context = []): bool
    {
        return $this->log('alert', $message, $context);
    }

    //--------------------------------------------------------------------

    /**
     * Critical conditions.
     *
     * Example: Application component unavailable, unexpected exception.
     *
     * @param string $message
     * @param array  $context
     *
     * @return boolean
     */
    public function critical($message, array $context = []): bool
    {
        return $this->log('critical', $message, $context);
    }

    //--------------------------------------------------------------------

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param string $message
     * @param array  $context
     *
     * @return boolean
     */
    public function error($message, array $context = []): bool
    {
        return $this->log('error', $message, $context);
    }

    //--------------------------------------------------------------------

    /**
     * Exceptional occurrences that are not errors.
     *
     * Example: Use of deprecated APIs, poor use of an API, undesirable things
     * that are not necessarily wrong.
     *
     * @param string $message
     * @param array  $context
     *
     * @return boolean
     */
    public function warning($message, array $context = []): bool
    {
        return $this->log('warning', $message, $context);
    }

    //--------------------------------------------------------------------

    /**
     * Normal but significant events.
     *
     * @param string $message
     * @param array  $context
     *
     * @return boolean
     */
    public function notice($message, array $context = []): bool
    {
        return $this->log('notice', $message, $context);
    }

    //--------------------------------------------------------------------

    /**
     * Interesting events.
     *
     * Example: User logs in, SQL logs.
     *
     * @param string $message
     * @param array  $context
     *
     * @return boolean
     */
    public function info($message, array $context = []): bool
    {
        return $this->log('info', $message, $context);
    }

    //--------------------------------------------------------------------

    /**
     * Detailed debug information.
     *
     * @param string $message
     * @param array  $context
     *
     * @return boolean
     */
    public function debug($message, array $context = []): bool
    {
        return $this->log('debug', $message, $context);
    }

    //--------------------------------------------------------------------

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     *
     * @return boolean
     */
    public function log($level, $message, array $context = []): bool
    {
        if (is_numeric($level)) {
            $level = array_search((int) $level, $this->logLevels);
        }

        // Is the level a valid level?
        if (! array_key_exists($level, $this->logLevels)) {
            throw LogException::forInvalidLogLevel($level);
        }

        // Does the app want to log this right now?
        if (! in_array($level, $this->loggableLevels)) {
            return false;
        }

        // Parse our placeholders
        $message = $this->interpolate($message, $context);

        if (! is_string($message)) {
            $message = print_r($message, true);
        }

        if ($this->cacheLogs) {
            $this->logCache[] = [
                'level' => $level,
                'msg'   => $message,
            ];
        }
        if (defined('sentryDSN') === true) {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($level, $message): void {
                if (in_array($level, ['alert', 'notice', 'info'])) {
                    $scope->setLevel(\Sentry\Severity::info());
                } elseif ($level == 'debug') {
                    $scope->setLevel(\Sentry\Severity::debug());
                } elseif ($level == 'error') {
                    $scope->setLevel(\Sentry\Severity::error());
                } else {
                    $scope->setLevel(\Sentry\Severity::warning());
                }
                \Sentry\captureMessage($message);
            });
        }

        foreach ($this->handlerConfig as $className => $config) {
            if (! array_key_exists($className, $this->handlers)) {
                $this->handlers[$className] = new $className($config);
            }

            /**
             * @var \CodeIgniter\Log\Handlers\HandlerInterface
             */
            $handler = $this->handlers[$className];

            if (! $handler->canHandle($level)) {
                continue;
            }

            // If the handler returns false, then we
            // don't execute any other handlers.
            if (! $handler->setDateFormat($this->dateFormat)->handle($level, $message)) {
                break;
            }
        }

        return true;
    }

    //--------------------------------------------------------------------

    /**
     * Replaces any placeholders in the message with variables
     * from the context, as well as a few special items like:
     *
     * {session_vars}
     * {post_vars}
     * {get_vars}
     * {env}
     * {env:foo}
     * {file}
     * {line}
     *
     * @param mixed $message
     * @param array $context
     *
     * @return mixed
     */
    protected function interpolate($message, array $context = [])
    {
        if (! is_string($message)) {
            return $message;
        }

        // build a replacement array with braces around the context keys
        $replace = [];

        foreach ($context as $key => $val) {
            // Verify that the 'exception' key is actually an exception
            // or error, both of which implement the 'Throwable' interface.
            if ($key === 'exception' && $val instanceof \Throwable) {
                $val = $val->getMessage() . ' ' . $this->cleanFileNames($val->getFile()) . ':' . $val->getLine();
            }

            // todo - sanitize input before writing to file?
            $replace['{' . $key . '}'] = $val;
        }

        // Add special placeholders
        $replace['{post_vars}'] = '$_POST: ' . print_r($_POST, true);
        $replace['{get_vars}']  = '$_GET: ' . print_r($_GET, true);
        $replace['{env}']       = ENVIRONMENT;

        // Allow us to log the file/line that we are logging from
        if (strpos($message, '{file}') !== false) {
            list($file, $line) = $this->determineFile();

            $replace['{file}'] = $file;
            $replace['{line}'] = $line;
        }

        // Match up environment variables in {env:foo} tags.
        if (strpos($message, 'env:') !== false) {
            preg_match('/env:[^}]+/', $message, $matches);

            if ($matches) {
                foreach ($matches as $str) {
                    $key                 = str_replace('env:', '', $str);
                    $replace["{{$str}}"] = $_ENV[$key] ?? 'n/a';
                }
            }
        }

        if (isset($_SESSION)) {
            $replace['{session_vars}'] = '$_SESSION: ' . print_r($_SESSION, true);
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    //--------------------------------------------------------------------

    /**
     * Determines the current file/line that the log method was called from.
     * by analyzing the backtrace.
     *
     * @return array
     */
    public function determineFile(): array
    {
        // Determine the file and line by finding the first
        // backtrace that is not part of our logging system.
        $trace = debug_backtrace();
        $file  = null;
        $line  = null;

        foreach ($trace as $row) {
            if (in_array($row['function'], ['interpolate', 'determineFile', 'log', 'log_message'])) {
                continue;
            }

            $file = $row['file'] ?? isset($row['object']) ? get_class($row['object']) : 'unknown';
            $line = $row['line'] ?? $row['function'] ?? 'unknown';
            break;
        }

        return [
            $file,
            $line,
        ];
    }

    //--------------------------------------------------------------------

    /**
     * Cleans the paths of filenames by replacing APPPATH, SYSTEMPATH, FCPATH
     * with the actual var. i.e.
     *
     *  /var/www/site/app/Controllers/Home.php
     *      becomes:
     *  APPPATH/Controllers/Home.php
     *
     * @param $file
     *
     * @return string
     */
    protected function cleanFileNames(string $file): string
    {
        $file = str_replace(APPPATH, 'APPPATH/', $file);
        $file = str_replace(SYSTEMPATH, 'SYSTEMPATH/', $file);
        $file = str_replace(FCPATH, 'FCPATH/', $file);

        return $file;
    }

    //--------------------------------------------------------------------
}
