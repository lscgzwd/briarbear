<?php
/**
 * briarbear
 * User: lushuncheng<admin@lushuncheng.com>
 * Date: 2017/3/1
 * Time: 18:17
 * @link https://github.com/lscgzwd
 * @copyright Copyright (c) 2017 Lu Shun Cheng (https://github.com/lscgzwd)
 * @licence http://www.apache.org/licenses/LICENSE-2.0
 * @author Lu Shun Cheng (lscgzwd@gmail.com)
 */

namespace BriarBear;

use BriarBear\Exception\InvalidConfigException;
use BriarBear\Helpers\ArrayHelper;
use BriarBear\Log\Logger;

class BriarBearBase
{
    /**
     * @var array singleton objects indexed by their types
     */
    protected static $singletons = [];
    /**
     * @var Server
     */
    public static $server;
    /**
     * @var array application config
     */
    public $config = [
        'logger' => [
            'class'   => 'BriarBear\\Log\\FileLogger',
            'logPath' => BRIARBEAR_PATH . '../runtime/log',
            'levels'  => ['info', 'error', 'warning'],
        ],
        'server' => [
            'setting'           => [
                'worker_num'        => 4, //worker process num
                'backlog'           => 16, //listen backlog
                'max_request'       => 5000,
                'task_worker_num'   => 4,
                'task_worker_max'   => 16,
                'dispatch_mode'     => 3,
                'open_tcp_nodelay'  => 1,
                'enable_reuse_port' => 1,
                'log_file'          => BRIARBEAR_PATH . '../runtime/log/BriarBearServer.log',
                'log_level'         => 0,
                'daemonize'         => 1,
                // 'user'               => 'rrxuser',
                // 'group'              => 'rrxuser',
            ],
            'host'              => '0.0.0.0',
            'port'              => '9501',
            'keepalive'         => 0,
            'gzip'              => 0,
            'gzipLevel'         => 7,
            'openHttpProtocol'  => true,
            'httpGetMaxSize'    => 8192,
            'httpPostMaxSize'   => 52428800, // 50MB
            'tcpMaxPackageSize' => 1024000, // 1MB
            'pidFile'           => BRIARBEAR_PATH . '../runtime/run/server.pid',
            'serverIP'          => '',
            'serverName'        => 'Briar Bear', // server process name
            'webSocket'         => [
                'port' => '9502',
                'host' => '0.0.0.0',
            ],
            'class'             => 'BriarBear\\Server',
        ],
    ];
    /**
     * Returns a string representing the current version of the BriarBear framework.
     * @return string the version of BriarBear framework
     */
    public static function getVersion()
    {
        return '0.0.1-dev';
    }

    /**
     * @param array $config
     */
    public function run($config)
    {
        $this->config = ArrayHelper::merge($this->config, $config);
        static::setLogger(static::createObject($this->config['logger']));
        static::getLogger()->init();
        static::$server = static::createObject($this->config['server']);
        static::$server->run();
    }
    /**
     * Configures an object with the initial property values.
     * @param object $object the object to be configured
     * @param array $properties the property initial values given in terms of name-value pairs.
     * @return object the object itself
     */
    public static function configure($object, $properties)
    {
        foreach ($properties as $name => $value) {
            $object->$name = $value;
        }

        return $object;
    }
    /**
     * Creates a new object using the given configuration.
     *
     * You may view this method as an enhanced version of the `new` operator.
     * The method supports creating an object based on a class name, a configuration array
     *
     * Below are some usage examples:
     *
     * ```php
     * // create an object using a class name
     * $object = BaiarBear::createObject('BaiarBear\Db\Connection');
     *
     * // create an object using a configuration array
     * $object = BaiarBear::createObject([
     *     'class' => 'BaiarBear\Db\Connection',
     *     'dsn' => 'mysql:host=127.0.0.1;dbname=demo',
     *     'username' => 'root',
     *     'password' => '',
     *     'charset' => 'utf8',
     * ]);
     *
     * // create an object with two constructor parameters
     * $object = \BaiarBear::createObject('MyClass', [$param1, $param2]);
     * ```
     *
     * @param string|array $type the object type. This can be specified in one of the following forms:
     *
     * - a string: representing the class name of the object to be created
     * - a configuration array: the array must contain a `class` element which is treated as the object class,
     *   and the rest of the name-value pairs will be used to initialize the corresponding object properties
     *
     * @param array $params the constructor parameters
     * @return object the created object
     * @throws InvalidConfigException if the configuration is invalid.
     */
    public static function createObject($type, array $params = [], $singleton = true)
    {
        if (is_string($type)) {
            $class = $type;
        } elseif (is_array($type) && isset($type['class'])) {
            $class = $type['class'];
            unset($type['class']);
        } elseif (is_array($type)) {
            throw new InvalidConfigException('Object configuration must be an array containing a "class" element.');
        } else {
            throw new InvalidConfigException('Unsupported configuration type: ' . gettype($type));
        }
        if ($singleton && isset(static::$singletons[$class])) {
            return static::$singletons[$class];
        }
        $reflection = new \ReflectionClass($class);
        if (is_array($type)) {
            $params[count($params) - 1] = $type;
        }
        $object = $reflection->newInstanceArgs($params);

        if ($singleton) {
            static::$singletons[$class] = $object;
        }
        return $object;
    }
    /**
     * Logs a trace message.
     * Trace messages are logged mainly for development purpose to see
     * the execution work flow of some code.
     * @param string $message the message to be logged.
     * @param string $category the category of the message.
     */
    public static function trace($message, $category = 'application')
    {
        static::getLogger()->log($message, Logger::LEVEL_TRACE, $category);
    }

    /**
     * Logs an error message.
     * An error message is typically logged when an unrecoverable error occurs
     * during the execution of an application.
     * @param string $message the message to be logged.
     * @param string $category the category of the message.
     */
    public static function error($message, $category = 'application')
    {
        static::getLogger()->log($message, Logger::LEVEL_ERROR, $category);
    }

    /**
     * Logs a warning message.
     * A warning message is typically logged when an error occurs while the execution
     * can still continue.
     * @param string $message the message to be logged.
     * @param string $category the category of the message.
     */
    public static function warning($message, $category = 'application')
    {
        static::getLogger()->log($message, Logger::LEVEL_WARNING, $category);
    }

    /**
     * Logs an informative message.
     * An informative message is typically logged by an application to keep record of
     * something important (e.g. an administrator logs in).
     * @param string $message the message to be logged.
     * @param string $category the category of the message.
     */
    public static function info($message, $category = 'application')
    {
        static::getLogger()->log($message, Logger::LEVEL_INFO, $category);
    }

    /**
     * @var Log\Logger
     */
    private static $_logger;
    /**
     * @return Log\Logger message logger
     */
    public static function getLogger()
    {
        if (self::$_logger !== null) {
            return self::$_logger;
        } else {
            /**
             * @var Logger
             */
            return self::$_logger = static::createObject('BriarBear\Log\Logger');
        }
    }

    /**
     * Sets the logger object.
     * @param Logger $logger the logger object.
     */
    public static function setLogger($logger)
    {
        self::$_logger = $logger;
    }
}
