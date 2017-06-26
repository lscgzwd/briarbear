<?php
/**
 * briarbear
 * User: lushuncheng<admin@lushuncheng.com>
 * Date: 2017/2/24
 * Time: 16:10
 * @link https://github.com/lscgzwd
 * @copyright Copyright (c) 2017 Lu Shun Cheng (https://github.com/lscgzwd)
 * @licence http://www.apache.org/licenses/LICENSE-2.0
 * @author Lu Shun Cheng (lscgzwd@gmail.com)
 * @todo 数据接收超时；keepalive超时
 */

namespace BriarBear;

use BriarBear\Exception\InvalidCallException;
use BriarBear\Helpers\ArrayHelper;
use BriarBear\Helpers\FileHelper;
use BriarBear\Response\HttpResponse;
use BriarBear\Response\TcpResponse;
use Swoole\Http\Response;
use Swoole\Process;
use Swoole\WebSocket\Server as WebSocketServer;

class Server extends Object
{
    public $setting             = [];
    public $webSocket           = false; // ['port' => 9502, 'host' => '0.0.0.0']
    public $openHttpProtocol    = false; // false not open http protocol parse, true enable http server base on tcp server
    public $httpGetMaxSize      = 8192; // max get size
    public $httpPostMaxSize     = null; // post max size, default php.ini  set
    public $httpPostMaxFileSize = null; // the max size for each upload file, default php.ini set
    public $httpPutMaxSize      = 1073741824; // 1G
    public $httpPostMaxFile     = null; // max upload files each request, default php.ini set
    public $httpMaxHeaderSize   = 16384; // 16K
    public $httpMaxInputVars    = 200;
    public $httpStaticRoot      = '';
    public $httpStaticCacheTime = 86400; // unit second.
    public $pidFile             = BRIARBEAR_PATH . 'runtime/run/server.pid';
    public $serverIP            = ''; // the server ip to used register with zookeeper
    public $tcpMaxPackageSize   = 1048576; // 1M
    public $serverName          = 'Briar Bear';
    public $host                = '0.0.0.0'; // null given to disable tcp server and http server
    public $port                = '9501';
    public $gzip                = 0;
    public $gzipLevel           = 3;
    public $gzipMinLength       = 1024; // byte
    public $gzipTypes           = [
        'text/plain',
        'application/x-javascript',
        'text/css',
        'application/xml',
        'text/javascript',
        'text/html',
        'application/xhtml+xml',
        'application/json',
        'text/xml',
    ];
    public $keepalive            = 0;
    protected $tcpServer         = null;
    protected $httpPostBoundary  = null;
    protected $tmpUploadDir      = null;
    public $server               = null;
    protected static $buffers    = [];
    const REQUEST_TYPE_TCP       = 'TCP';
    const REQUEST_TYPE_HTTP      = 'HTTP';
    const REQUEST_TYPE_WEBSOCKET = 'WEBSOCKET';
    public static $backupServer  = [];
    public $accessLog            = 0;
    public $logRawPost           = false;
    /**
     * 定时任务配置，false to close
     * @var array|Crontab
     */
    public $crontab = [
        'class'         => '\BriarBear\Crontab',
        'ipAddress'     => '127.0.0.1',
        'cronList'      => [],
        'zookeeperHost' => [],
    ];

    /***
     * callback
     * [
     *      'workerStart' => $var, // callable or array define to BriarBear::createObject
     *      'httpRequest' => $var,
     *      'tcpReceive' => $var,
     *      'webSocketMessage' => $var,
     *      'webSocketOpen' => $var,
     *      'task' => $var,
     *      'taskFinish' => $var,
     *      'handShake' => $var,
     * ]
     * @var array null
     */
    public $callback = null;

    /**
     * @var \Swoole\Server null
     */
    public $swooleServer = null;
    /**
     * @var Process
     */
    public $crontabProcess = null;

    public function run()
    {
        try {
            FileHelper::createDirectory(dirname($this->pidFile));
            if (false === $this->initConfig()) {
                die('config error.');
            }

            global $argv;
            $action = isset($argv[1]) ? strtolower($argv[1]) : '';

            switch ($action) {
                case 'start':
                    $pid       = is_file($this->pidFile) ? trim(file_get_contents($this->pidFile)) : '{"masterPid": null, "managerPid": null}';
                    $pidArr    = json_decode($pid, true);
                    $masterPid = $pidArr['masterPid'];
                    if (!empty($masterPid) && posix_kill($masterPid, 0)) {
                        \BriarBear::info('Server already running, exit.');
                        return;
                    }
                    $this->startListen();
                    break;
                case 'restart':
                    $pid        = is_file($this->pidFile) ? trim(file_get_contents($this->pidFile)) : '{"masterPid": null, "managerPid": null}';
                    $pidArr     = json_decode($pid, true);
                    $masterPid  = $pidArr['masterPid'];
                    $managerPid = $pidArr['managerPid'];
                    if (empty($masterPid) || !posix_kill($masterPid, 0)) {
                        \BriarBear::info('Server not running, start it.');
                    } else {
                        $tick = 0;
                        while (true) {
                            if ($tick < 10) {
                                // 尝试发送友好结束命令给管理进程和主进程
                                posix_kill($managerPid, SIGTERM);
                                posix_kill($masterPid, SIGTERM);
                                if (!posix_kill($masterPid, 0)) {
                                    break;
                                }
                            } elseif ($tick < 15) {
                                // 尝试两次都不行直接执行强杀
                                posix_kill($managerPid, SIGKILL);
                                posix_kill($masterPid, SIGKILL);
                                if (!posix_kill($masterPid, 0)) {
                                    break;
                                }
                            } else {
                                if (!posix_kill($masterPid, 0)) {
                                    break;
                                } else {
                                    // 强杀不掉，退出，可能由于权限等，或者进程假死，无法正常退出
                                    die('服务停止失败，请手动重试');
                                }
                            }
                            sleep(2);
                            $tick++;
                        }
                        // 线上可能存在脏进程，强杀一次
                        exec('ps axu | grep ' . $this->serverName . ' | grep -v grep | awk \'{print $2}\'| xargs kill -9');
                    }
                    $this->startListen();
                    break;
                case 'reload':
                    $pid        = is_file($this->pidFile) ? trim(file_get_contents($this->pidFile)) : '{"masterPid": null, "managerPid": null}';
                    $pidArr     = json_decode($pid, true);
                    $masterPid  = $pidArr['masterPid'];
                    $managerPid = $pidArr['managerPid'];
                    if (empty($masterPid) || !posix_kill($masterPid, 0)) {
                        \BriarBear::info('Server not running, start it.');
                        $this->startListen();
                    } else {
                        posix_kill($masterPid, SIGUSR1);
                        posix_kill($managerPid, SIGUSR1);
                        posix_kill($masterPid, SIGUSR2);
                        posix_kill($managerPid, SIGUSR2);
                    }
                    break;
                case 'stop':
                    $pid        = is_file($this->pidFile) ? trim(file_get_contents($this->pidFile)) : '{"masterPid": null, "managerPid": null}';
                    $pidArr     = json_decode($pid, true);
                    $masterPid  = $pidArr['masterPid'];
                    $managerPid = $pidArr['managerPid'];
                    if (empty($masterPid) || !posix_kill($masterPid, 0)) {
                        \BriarBear::info('Server not running, exit.');
                    } else {
                        $tick = 0;
                        while (true) {
                            if ($tick < 10) {
                                // 尝试发送友好结束命令给管理进程和主进程
                                posix_kill($managerPid, SIGTERM);
                                posix_kill($masterPid, SIGTERM);
                                if (!posix_kill($masterPid, 0)) {
                                    break;
                                }
                            } elseif ($tick < 15) {
                                // 尝试两次都不行直接执行强杀
                                posix_kill($managerPid, SIGKILL);
                                posix_kill($masterPid, SIGKILL);
                                if (!posix_kill($masterPid, 0)) {
                                    break;
                                }
                            } else {
                                if (!posix_kill($masterPid, 0)) {
                                    break;
                                } else {
                                    // 强杀不掉，退出，可能由于权限等，或者进程假死，无法正常退出
                                    die('服务停止失败，请手动重试');
                                }
                            }
                            sleep(2);
                            $tick++;
                        }
                        // 线上可能存在脏进程，强杀一次
                        exec('ps axu | grep ' . $this->serverName . ' | grep -v grep | awk \'{print $2}\'| xargs kill -9');
                    }
                    break;
                default:
                    \BriarBear::error("Unknown command;Usage: Path_to_php/php {$argv[0]} start|reload|stop, exit.");
                    return;
                    break;
            }
        } catch (\Throwable $e) {
            var_dump($e->__toString());
        }
    }
    protected function initConfig()
    {
        $webSocket = false;
        if (is_array($this->webSocket) &&
            (!isset($this->webSocket['host'])
                || !isset($this->webSocket['port'])
                || !is_numeric($this->webSocket['port'])
                || !isset($this->callback['message'])
                || !is_callable($this->callback['message'])
            )
        ) {
            \BriarBear::info('Bad config, web socket host, port or message callback not set. skip');
            $this->webSocket = false;
        } else {
            $webSocket = true;
        }
        if ($webSocket === false && (empty($this->host) || empty($this->port) || !is_numeric($this->port))) {
            \BriarBear::info('Bad config, no server config given, nothing need to start, exit.');
            return false;
        }
        if (empty($this->serverIP)) {
            $ips = swoole_get_local_ip();
            foreach ($ips as $interface => $ip) {
                $this->serverIP = $ip;
                break;
            }
        }
        $this->httpGetMaxSize      = $this->httpGetMaxSize ?: 8192;
        $this->httpPostMaxSize     = $this->httpPostMaxSize ?: ini_get('post_max_size');
        $this->httpPostMaxFile     = $this->httpPostMaxFile ?: ini_get('max_file_uploads');
        $this->httpPostMaxFileSize = $this->httpPostMaxFileSize ?: ini_get('upload_max_filesize');
        $maxInputVars              = ini_get('max_input_vars');
        if ($maxInputVars) {
            $this->httpMaxInputVars = $maxInputVars;
        }
        $this->tmpUploadDir = ini_get('upload_tmp_dir');
        // static resources home dir
        $this->httpStaticRoot = $this->httpStaticRoot ? rtrim($this->httpStaticRoot, '/') . '/' : rtrim(sys_get_temp_dir(), '/') . '/';
        if ($this->tmpUploadDir == '') {
            $this->tmpUploadDir = sys_get_temp_dir();
        }
        // access log
        if ($this->accessLog) {
            if (substr($this->accessLog, 0, 1) != '/') {
                $this->accessLog = BRIARBEAR_PATH . 'runtime/log/' . $this->accessLog;
            }
            FileHelper::createDirectory(dirname($this->accessLog));
        }
        $this->serverName = str_replace(' ', '', $this->serverName);
        return true;
    }
    protected function startListen()
    {
        $server    = null;
        $tcpServer = null;
        $webSocket = false;
        if (is_array($this->webSocket)) {
            $server    = new WebSocketServer($this->webSocket['host'], $this->webSocket['port'], SWOOLE_PROCESS);
            $webSocket = true;
        }
        if (!empty($this->host)) {
            if ($server === null) {
                $server    = new \Swoole\Server($this->host, $this->port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
                $tcpServer = $server;
            } else {
                $tcpServer = $server->addlistener($this->host, $this->port, SWOOLE_SOCK_TCP);
            }
        }
        if (null === $server) {
            \BriarBear::info('No server config given, exit.');
            return;
        }
        // 必须是2 ， 固定模式，每个连接固定一个worker，才能拼接到完整的TCP包
        $this->setting['dispatch_mode'] = 2;
        $server->set($this->setting);
        $server->on('Start', [$this, 'onStart']);
        if ($tcpServer) {
            $tcpServer->on('Receive', [$this, 'onReceive']);
            $tcpServer->set([
                'open_http_protocol'      => false,
                'open_websocket_protocol' => false,
            ]);
        }
        $server->on('Shutdown', [$this, 'onShutdown']);
        $server->on('Task', [$this, 'onTask']);
        $server->on('Finish', [$this, 'onTaskFinish']);
        $server->on('ManagerStart', [$this, 'onManagerStart']);
        $server->on('WorkerStart', [$this, 'onWorkerStart']);
        $server->on('Close', [$this, 'onClose']);
        $server->on('WorkerStop', [$this, 'onWorkerStop']);
        $server->on('pipeMessage', [$this, 'onPipeMessage']);
        if ($webSocket) {
            $server->on('Message', [$this, 'onMessage']);
            $server->on('Open', [$this, 'onOpen']);
            if (isset($this->callback['handShake'])) {
                $server->on('HandShake', [$this, 'onHandShake']);
            }

        }
        static::$backupServer = $_SERVER;
        // start crontab
        if (is_array($this->crontab) && (!defined('START_CRONTAB') || START_CRONTAB === true)) {
            \BriarBear::info('begin to start crontab');
            $this->crontab['ipAddress'] = $this->serverIP;
            $this->crontab['class']     = '\BriarBear\Crontab';
            $this->crontab              = \BriarBear::createObject($this->crontab);
            $bs                         = $this;
            $process                    = new Process(function ($process) use ($bs) {
                $bs->crontab->run($bs);
            });
            $this->crontabProcess = $process;
            $server->addProcess($process);
        }

        $this->swooleServer = $server;
        $server->start();
        // can not be exec more code

    }
    public function onStart(\Swoole\Server $server)
    {
        cli_set_process_title("{$this->serverName} master");
        $pidArr = [
            'masterPid'  => $server->master_pid,
            'managerPid' => $server->manager_pid,
        ];
        file_put_contents($this->pidFile, json_encode($pidArr));
    }
    /**
     * callback for manager process start
     * @param \Swoole\Server $server
     */
    public function onManagerStart(\Swoole\Server $server)
    {
        cli_set_process_title("{$this->serverName} manager");
    }

    /**
     * callback when master process get SIGTREM
     * @param \Swoole\Server $server
     */
    public function onShutdown(\Swoole\Server $server)
    {
        $pid       = is_file($this->pidFile) ? trim(file_get_contents($this->pidFile)) : '{"masterPid": null, "managerPid": null}';
        $pidArr    = json_decode($pid, true);
        $masterPid = $pidArr['masterPid'];

        if (empty($masterPid) || !posix_kill($masterPid, 0)) {
            \BriarBear::info('Server stopped success, exit.');
            unlink($this->pidFile);
        } else {
            \BriarBear::info("Server stopped failed, exit. PID: {$masterPid}");
        }
    }
    /**
     * callback for worker start
     * @param \Swoole\Server $server
     * @param $workerId
     */
    public function onWorkerStart(\Swoole\Server $server, int $workerId)
    {
        // set process mark
        if ($server->taskworker === true) {
            cli_set_process_title("{$this->serverName} task");
        } else {
            cli_set_process_title("{$this->serverName} worker");
        }
        if (isset($this->callback['workerStart']) && is_callable($this->callback['workerStart'])) {
            call_user_func_array($this->callback['workerStart'], [$this, $server, $workerId]);
        }
    }

    /**
     * @param \Swoole\Server $server
     * @param int $workerId
     */
    public function onWorkerStop(\Swoole\Server $server, int $workerId)
    {
        $this->unsetGlobal(0);
    }

    public function onClose(\Swoole\Server $server, int $fd, int $fromId)
    {
        // callback when client or server close the connection
        if (isset($this->callback['close']) && is_callable($this->callback['close'])) {
            call_user_func_array($this->callback['close'], [$this, $server, $fd, $fromId]);
        }
    }

    public function onTask(\Swoole\Server $server, int $taskId, int $workerId, $data)
    {
        if (isset($this->callback['task']) && is_callable($this->callback['task'])) {
            call_user_func_array($this->callback['task'], [$this, $server, $workerId, $taskId, $data]);
        }
    }
    public function onPipeMessage(\Swoole\Server $server, int $fromWorkerId, string $message)
    {
        $json = json_decode($message, true);
        if ($json['type'] == 'task') {
            $server->task($json['task']);
        }
    }
    public function onTaskFinish(\Swoole\Server $server, int $taskId, string $data)
    {
        if (isset($this->callback['taskFinish']) && is_callable($this->callback['taskFinish'])) {
            call_user_func_array($this->callback['taskFinish'], [$this, $server, $taskId, $data]);
        }
    }

    /**
     * swoole server receive callback
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $fromId
     * @param string $data
     */
    public function onReceive(\Swoole\Server $server, int $fd, int $fromId, string $data)
    {
        try {
            // 初始化请求缓冲类，自己拼接完整数据包
            $buffer = $this->initBuffer($fd);
            /**
             * @var Buffer $buffer
             */
            $buffer->getRequest()->appendRawContent($data);
            $buffer->setAcceptTotalLength($buffer->getAcceptTotalLength() + strlen($data));
            $buffer->setAcceptBodyLength($buffer->getAcceptBodyLength() + strlen($data));

            if ($buffer->isHeaderParsed() === false) {
                // HTTP 协议头和BODY部分分隔标记： \r\n\r\n
                if (strpos($data, "\r\n\r\n") !== false) {
                    // REMOTE_ADDR REMOTE_PORT
                    $_SERVER['REMOTE_ADDR'] = $server->connection_info($fd)['remote_ip'];
                    $_SERVER['REMOTE_PORT'] = $server->connection_info($fd)['remote_port'];

                    $parts = explode("\r\n\r\n", $buffer->getRequest()->getRawContent(), 2);
                    // 解析头部
                    $this->parseHeader($parts[0], $buffer->getRequest());
                    $buffer->setHeaderParsed(true);
                    // 剩下部分赋值给请求body
                    $buffer->getRequest()->setRawContent($parts[1]);
                    $buffer->setAcceptBodyLength(strlen($parts[1]));
                    // 判断请求头里面的内容大小和服务器设置允许的大小
                    if (isset($_SERVER['CONTENT_LENGTH'])) {
                        switch ($_SERVER['REQUEST_METHOD']) {
                            case 'PUT':
                                if ($_SERVER['CONTENT_LENGTH'] > $this->httpPutMaxSize) {
                                    throw new InvalidCallException('Request Entity Too Large', 413);
                                }
                                break;
                            case 'POST':
                                if ($_SERVER['CONTENT_LENGTH'] > $this->httpPostMaxSize) {
                                    throw new InvalidCallException('Request Entity Too Large', 413);
                                }
                                break;
                            case 'TCP':
                                if ($_SERVER['CONTENT_LENGTH'] > $this->tcpMaxPackageSize) {
                                    throw new InvalidCallException('Request Entity Too Large', 413);
                                }
                                break;
                        }
                    }

                    $headers = $buffer->getRequest()->getHead();
                    // if GET HEAD DELETE OPTIONS , Do not accept any client body data.
                    if (isset($headers['Expect']) && $headers['Expect'] === '100-continue') {
                        # 支持 100-continue
                        # 返回状态后，客户端会立即发送数据上来
                        $server->send($fd, "HTTP/1.1 100 Continue\r\n\r\n");
                    }
                } elseif ($buffer->getAcceptTotalLength() > $this->httpMaxHeaderSize) {
                    throw new InvalidCallException('Header Too Large', 400);
                }
            } else {
                // 超全局变量在swoole work中会被重置，每次onReceive,从静态堆中恢复
                $_SERVER = $buffer->getRequest()->getServer();
                $_GET    = $buffer->getRequest()->getGet();
                $_POST   = $buffer->getRequest()->getPost();
                $_COOKIE = $buffer->getRequest()->getCookies();
                $_FILES  = $buffer->getRequest()->getFiles();
            }
            // 如果第一个包收到的不是完整的头部，则手动截取包前七个字符，并按空格分割后初使化请求方法$_SERVER['REQUEST_METHOD']
            if (!isset($_SERVER['REQUEST_METHOD'])) {
                $_SERVER['REQUEST_METHOD'] = explode(' ', substr($buffer->getRequest()->getRawContent(), 0, 7))[0];
            }
            // determine already receive length and limit
            switch ($_SERVER['REQUEST_METHOD']) {
                case 'PUT':
                    if ($buffer->getAcceptBodyLength() > $this->httpPutMaxSize) {
                        throw new InvalidCallException('Request Entity Too Large', 413);
                    }
                    $this->checkContentLength($buffer->getAcceptBodyLength());
                    $tmpRawFile = $buffer->getRequest()->getPutRawTempFile();
                    file_put_contents($tmpRawFile, $buffer->getRequest()->getRawContent(), FILE_APPEND);
                    $buffer->getRequest()->setRawContent('');
                    $buffer->getRequest()->setRawType(Request::RAW_PUT_FILE);
                    if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] == $buffer->getAcceptBodyLength()) {
                        clearstatcache(true, $tmpRawFile);
                        $this->doHttpRequest($server, $fd, $fromId);
                    }
                    break;
                case 'POST':
                    if ($buffer->getAcceptBodyLength() > $this->httpPostMaxSize) {
                        throw new InvalidCallException('Request Entity Too Large', 413);
                    }
                    $this->checkContentLength($buffer->getAcceptBodyLength());
                    if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] == $buffer->getAcceptBodyLength()) {
                        if ($_SERVER['CONTENT_TYPE'] == 'application/x-www-form-urlencoded' || strpos($_SERVER['CONTENT_TYPE'], 'application/x-www-form-urlencoded') === 0) {
                            if (substr_count($buffer->getRequest()->getRawContent(), '&') > $this->httpMaxInputVars) {
                                throw new InvalidCallException('Too Much Post Items', 400);
                            }
                            parse_str($buffer->getRequest()->getRawContent(), $_POST);
                            $_REQUEST = ArrayHelper::merge($_REQUEST, $_POST);
                            $buffer->getRequest()->setPost($_POST);
                        } elseif ($_SERVER['CONTENT_TYPE'] == 'multipart/form-data') {
                            if (substr_count($buffer->getRequest()->getRawContent(), $this->httpPostBoundary) > $this->httpMaxInputVars) {
                                throw new InvalidCallException('Too Much Post Items', 400);
                            }
                            $this->parseUploadFiles($buffer->getRequest()->getRawContent(), $this->httpPostBoundary);
                            $_REQUEST = ArrayHelper::merge($_REQUEST, $_POST);
                            $buffer->getRequest()->setPost($_POST);
                        }
                        // post content maybe  very large, we storage it to file
                        if ($this->logRawPost) {
                            $postTempFile = $buffer->getRequest()->getPostRawTempFile();
                            file_put_contents($postTempFile, $buffer->getRequest()->getRawContent());
                            clearstatcache(true, $postTempFile);
                            $buffer->getRequest()->setRawContent('');
                            $buffer->getRequest()->setRawType(Request::RAW_POST_FILE);
                        }
                        $this->doHttpRequest($server, $fd, $fromId);
                    }
                    break;
                case 'TCP':
                    if ($buffer->getAcceptBodyLength() > $this->tcpMaxPackageSize) {
                        throw new InvalidCallException('Request Entity Too Large', 413);
                    }
                    $this->checkContentLength($buffer->getAcceptBodyLength());
                    if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] == $buffer->getAcceptBodyLength()) {
                        $_POST    = json_decode($buffer->getRequest()->getRawContent(), true);
                        $_REQUEST = ArrayHelper::merge($_REQUEST, $_POST);
                        $buffer->getRequest()->setPost($_POST);
                        $this->doTcpRequest($server, $fd, $fromId);
                    }
                    break;
                case 'GET':
                case 'OPTIONS':
                case 'HEAD':
                case 'DELETE':
                    if ($buffer->getAcceptTotalLength() > $this->httpGetMaxSize) {
                        throw new InvalidCallException('Request Entity Too Large', 413);
                    }
                    // 已经接受到完整的Header头，这四种请求不接受BODY部分数据
                    if ($buffer->isHeaderParsed()) {
                        // GET DELETE OPTIONS HEAD , WE DO NOT ACCEPT BODY
                        $this->doHttpRequest($server, $fd, $fromId);
                    }
                    break;
                default:
                    // 如果接受的字符长度已经超过七，但请求的方法却不是支持的，直接断开连接
                    // Bad Request check
                    if ($buffer->getAcceptTotalLength() >= 7) {
                        $this->logAccess($server, $fd);
                        $this->unsetGlobal($fd);
                        $server->close($fd, $fromId);
                    }
                    break;
            }
        } catch (\Throwable $e) {
            \BriarBear::error('Connection was closed and exception caused:' . $e->__toString());
            if (!isset($_SERVER['REQUEST_METHOD'])) {
                $_SERVER['REQUEST_METHOD'] = explode(' ', substr($this->initBuffer($fd)->getRequest()->getRawContent(), 0, 7))[0];
            }
            switch ($_SERVER['REQUEST_METHOD']) {
                case 'TCP':
                    $response = \BriarBear\Response\Response::getInstance('TCP');
                    $response->setStatus($e->getCode());
                    $response->setMessage($e->getMessage());
                    $server->send($fd, $response->__toString());
                    $this->logAccess($server, $fd);
                    $this->unsetGlobal($fd);
                    break;
                case 'PUT':
                case 'GET':
                case 'POST':
                case 'HEAD':
                case 'DELETE':
                case 'OPTIONS':
                    $response = \BriarBear\Response\Response::getInstance('HTTP');
                    // exception result close the connection.
                    $response->setHeader('Connection', 'close');
                    $response->setBody($e->getMessage());
                    if ($this->openHttpProtocol == false) {
                        $response->setHttpStatus(501);
                    } else {
                        $statusCode = $e->getCode();
                        if (array_key_exists($statusCode, HttpResponse::$HTTP_HEADERS)) {
                            $response->setHttpStatus($statusCode);
                        } else {
                            $response->setHttpStatus(500);
                        }
                    }
                    $response->setHeader('KeepAlive', 'off');
                    $response->setHeader('Connection', 'close');
                    $server->send($fd, $response->getHeader() . $response->getBody(), $fromId);

                    $this->afterHttpResponse($server, $fd, $response);
                    break;
                default:
                    $this->logAccess($server, $fd);
                    $this->unsetGlobal($fd);
                    $server->close($fd, $fromId);
                    break;
            }
        }
    }

    /**
     * 记录请求日志
     * @param \Swoole\Server $server
     * @param $fd
     */
    protected function logAccess(\Swoole\Server $server, $fd)
    {
        if ($this->accessLog) {
            $accessLog = [
                'method'      => $_SERVER['REQUEST_METHOD'] ?? '',
                'uri'         => $_SERVER['REQUEST_URI'] ?? '',
                'time'        => date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ?? time()),
                'remote_addr' => $server->connection_info($fd)['remote_ip'],
            ];
            file_put_contents($this->accessLog, json_encode($accessLog) . "\r\n", FILE_APPEND);
        }
    }

    /**
     * 判断header头中的长度和内容长度
     * @param $bodyLength
     */
    protected function checkContentLength($bodyLength)
    {
        if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] < $bodyLength) {
            throw new InvalidCallException('Bad Request', 400);
        }
    }

    /**
     * @param WebSocketServer $server
     * @param \Swoole\Http\Request $req
     * @return mixed
     */
    public function onOpen(\Swoole\Websocket\Server $server, \Swoole\Http\Request $req)
    {
        if (isset($this->callback['webSocketOpen']) && is_callable($this->callback['webSocketOpen'])) {
            return call_user_func_array($this->callback['webSocketOpen'], [$this, $server, $req]);
        }
        return true;
    }

    /**
     * @param \Swoole\Http\Request $request
     * @param Response $response
     * @return bool|mixed
     */
    public function onHandShake(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        if (isset($this->callback['handShake']) && is_callable($this->callback['handShake'])) {
            return call_user_func_array($this->callback['handShake'], [$this, $request, $response]);
        }
        return true;
    }

    /**
     * @param WebSocketServer $server
     * @param \Swoole\Websocket\Frame $frame
     */
    public function onMessage(\Swoole\WebSocket\Server $server, \Swoole\Websocket\Frame $frame)
    {
        if (isset($this->callback['webSocketMessage']) && is_callable($this->callback['webSocketMessage'])) {
            $data = call_user_func_array($this->callback['webSocketMessage'], [$this, $server, $frame]);
            $server->push($frame->fd, $data, $frame->opcode);
        } else {
            $server->push($frame->fd, 'Please set webSocketMessage callback first.', $frame->opcode);
        }
    }

    /**
     * execute http request
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $fromId
     *
     */
    protected function doHttpRequest(\Swoole\Server $server, int $fd, int $fromId)
    {
        try {
            $response = $this->processStatic();
            $content  = false;
            if ($response === false) {
                ob_start();
                $response = call_user_func_array($this->callback['httpRequest'], [$this, $server, $fd, $this->initBuffer($fd)->getRequest()]);
                $content  = ob_get_clean();
            }

            if (!$response instanceof HttpResponse) {
                $response = \BriarBear\Response\Response::getInstance('HTTP');
                $response->setHttpStatus(500);
                $response->setBody('Unknown response type');
            }
            if ($content !== false) {
                $response->addBody($content);
            }
            if (!isset($response->head['Date'])) {
                $response->setHeader('Date', gmdate("D, d M Y H:i:s \\G\\M\\T"));
            }
            $headers = $this->initBuffer($fd)->getRequest()->getHead();
            if (!isset($response->head['Connection'])) {
                //keepalive
                if ($this->keepalive && (isset($headers['Connection']) && strtolower($headers['Connection']) == 'keep-alive')) {
                    $response->setHeader('KeepAlive', 'on');
                    $response->setHeader('Connection', 'keep-alive');
                } else {
                    $response->setHeader('KeepAlive', 'off');
                    $response->setHeader('Connection', 'close');
                }
            }
            //压缩
            if ($this->gzip && $response->getBodyLength() > $this->gzipMinLength) {
                if (!empty($headers['Accept-Encoding'])) {
                    //gzip
                    if (strpos($headers['Accept-Encoding'], 'gzip') !== false) {
                        $response->setHeader('Content-Encoding', 'gzip');
                        $response->setBody(gzencode($response->getBody(), $this->gzipLevel));
                        $response->setHeader('Content-Length', $response->getBodyLength());
                    }
                    //deflate
                    elseif (strpos($headers['Accept-Encoding'], 'deflate') !== false) {
                        $response->setHeader('Content-Encoding', 'deflate');
                        $response->setBody(gzdeflate($response->getBody(), $this->gzipLevel));
                        $response->setHeader('Content-Length', $response->getBodyLength());
                    } else {
                        \BriarBear::info("Unsupported compression type : {$headers['Accept-Encoding']}.", 'info');
                    }
                }
            }
            $server->send($fd, $response->getHeader());
            if ($response->isFileDownload()) {
                $server->sendfile($fd, $response->getFile());
            } else {
                $server->send($fd, $response->getBody());
            }
            $this->afterHttpResponse($server, $fd, $response);
            unset($response);
        } catch (\Throwable $e) {
            \BriarBear::error('Connection was closed and exception caused:' . $e->__toString());
            if ($server->getClientInfo($fd) !== false) {
                $response   = \BriarBear\Response\Response::getInstance('HTTP');
                $statusCode = $e->getCode();
                if (array_key_exists($statusCode, HttpResponse::$HTTP_HEADERS)) {
                    $response->setHttpStatus($statusCode);
                } else {
                    $response->setHttpStatus(500);
                }
                $response->setBody($e->__toString());
                $response->setHeader('KeepAlive', 'off');
                $response->setHeader('Connection', 'close');
                $server->send($fd, $response->getHeader() . $response->getBody());
                $server->close($fd);
                $this->afterHttpResponse($server, $fd, $response);
            }
        }
    }
    protected function afterHttpResponse(\Swoole\Server $server, int $fd, HttpResponse $response)
    {
        if (!$this->keepalive || $response->head['Connection'] == 'close') {
            $server->close($fd);
        }
        $this->logAccess($server, $fd);
        $this->unsetGlobal($fd);
    }

    protected function processStatic()
    {
        $uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $filename = basename($uri);
        $icons    = [
            'favicon.ico',
            'favicon.png',
            'apple-touch-icon.png',
            'apple-touch-icon-precomposed.png',
            'apple-touch-icon-57x57.png',
            'apple-touch-icon-57x57-precomposed.png',
            'apple-touch-icon-72x72.png',
            'apple-touch-icon-72x72-precomposed.png',
            'apple-touch-icon-76x76.png',
            'apple-touch-icon-76x76-precomposed.png',
            'apple-touch-icon-114x114.png',
            'apple-touch-icon-114x114-precomposed.png',
            'apple-touch-icon-120x120.png',
            'apple-touch-icon-120x120-precomposed.png',
            'apple-touch-icon-144x144.png',
            'apple-touch-icon-144x144-precomposed.png',
            'apple-touch-icon-152x152.png',
            'apple-touch-icon-152x152-precomposed.png',
            'apple-touch-icon-180x180.png',
            'apple-touch-icon-180x180-precomposed.png',
            'favicon-16x16.png',
            'favicon-32x32.png',
            'favicon-128x128.png',
            'mstile-150x150.png',
            'safari-pinned-tab.svg',
        ];
        if (in_array($filename, $icons)) {
            $response = \BriarBear\Response\Response::getInstance('HTTP');
            if (is_file($this->httpStaticRoot . ltrim($uri, '/'))) {
                $response->sendFile($this->httpStaticRoot . ltrim($uri, '/'), '', false);
            } elseif (is_file($this->httpStaticRoot . $filename)) {
                $response->sendFile($this->httpStaticRoot . $filename, '', false);
            } else {
                $response->setHttpStatus(404);
                $response->setBody('404 NOT FOUND');
            }
            return $response;
        }
        $file = $this->httpStaticRoot . ltrim($uri, '/');
        if (is_file($file)) {
            $response = \BriarBear\Response\Response::getInstance('HTTP');
            $response->sendFile($file, '', false);
            if (filesize($file) > $this->gzipMinLength) {
                $finfo    = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file);
                if (in_array($mimeType, $this->gzipTypes)) {
                    $response->gzipSendFile();
                }
            }

            return $response;
        }
        return false;
    }

    /**
     * @param \Swoole\Server $server
     * @param int $fd
     * @param int $fromId
     */
    protected function doTcpRequest(\Swoole\Server $server, int $fd, int $fromId)
    {
        try {
            $response = call_user_func_array($this->callback['tcpReceive'], [$this, $server, $fd, $this->initBuffer($fd)->getRequest()]);
            if (!$response instanceof TcpResponse) {
                $response = \BriarBear\Response\Response::getInstance('TCP');
                $response->setStatus(500);
                $response->setMessage('Unknown response type');
            }
            $server->send($fd, $response->__toString());
        } catch (\Throwable $e) {
            \BriarBear::error('Connection was closed and exception caused:' . $e->__toString());
            if ($server->getClientInfo($fd) !== false) {
                $response = \BriarBear\Response\Response::getInstance('TCP');
                $response->setStatus($e->getCode());
                $response->setMessage($e->__toString());
                $server->send($fd, $response->__toString());
            }
        }
        $this->logAccess($server, $fd);
        $this->unsetGlobal($fd);
    }
    /**
     * Parse $_FILES.
     *
     * @param string $httpBody
     * @param string $httpPostBoundary
     * @return void
     */
    protected function parseUploadFiles($httpBody, $httpPostBoundary)
    {
        $httpBody          = substr($httpBody, 0, strlen($httpBody) - (strlen($httpPostBoundary) + 4));
        $boundaryDataArray = explode($httpPostBoundary . "\r\n", $httpBody);
        if ($boundaryDataArray[0] === '') {
            unset($boundaryDataArray[0]);
        }
        foreach ($boundaryDataArray as $boundaryDataBuffer) {
            list($boundaryHeaderBuffer, $boundaryValue) = explode("\r\n\r\n", $boundaryDataBuffer, 2);
            // Remove \r\n from the end of buffer.
            $boundaryValue = substr($boundaryValue, 0, -2);
            $isFile        = false;
            $type          = 'application/octet-stream';
            $name          = '';
            $value         = '';
            foreach (explode("\r\n", $boundaryHeaderBuffer) as $item) {
                list($headerKey, $headerValue) = explode(": ", $item);
                $headerKey                     = strtolower($headerKey);
                switch ($headerKey) {
                    case "content-disposition":
                        // Is file data.
                        if (preg_match('/name="(.*?)"; filename="(.*?)"$/', $headerValue, $match)) {
                            $isFile   = true;
                            $name     = $match[1];
                            $fileName = $match[2];
                            $tmpFile  = tempnam($this->tmpUploadDir, 'briarbear_upload_');
                            file_put_contents($tmpFile, $boundaryValue);
                            $value = [
                                'name'     => $fileName,
                                'type'     => '',
                                'tmp_name' => $tmpFile,
                                'size'     => strlen($boundaryValue),
                                'error'    => UPLOAD_ERR_OK,
                            ];
                            continue;
                        } // Is post field.
                        else {
                            // Parse $_POST.
                            if (preg_match('/name="(.*?)"$/', $headerValue, $match)) {
                                $name  = $match[1];
                                $value = $boundaryValue;
                            }
                        }
                        break;
                    case 'content-type':
                        $type = trim($headerValue);
                        break;
                }
            }

            $pos = strpos($name, '[');
            if ($pos === false) {
                if ($isFile) {
                    $value['type'] = $type;
                    $_FILES[$name] = $value;
                } else {
                    $_POST[$name] = $value;
                }
            } else {
                if (preg_match('/[^a-zA-Z_\-\[\]]+/', $name)) {
                    throw new InvalidCallException('Name For Post Field Not Correct.' . $name, 400);
                }
                if ($isFile) {
                    $value['type'] = $type;
                    $code          = '\$_FILES[\'' . substr($name, 0, $pos) . '\']' . substr($name, $pos) . '=\$value;';
                } else {
                    $code = '\$_POST[\'' . substr($name, 0, $pos) . '\']' . substr($name, $pos) . '=\$value;';
                }
                eval($code);
            }
        }
    }

    /**
     * 解析Form表单请求头
     * @param string $headerString
     * @param Request $request
     */
    protected function parseHeader($headerString, Request $request)
    {
        $parts = explode("\r\n", $headerString);
        // parse request uri and method
        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ', $parts[0]);
        switch ($_SERVER['REQUEST_METHOD']) {
            case "GET":
                if ($this->openHttpProtocol == false) {
                    throw new InvalidCallException('Method Not Implemented', 501);
                }
                if ($_SERVER['REQUEST_METHOD'] == 'GET' && strlen($headerString) > $this->httpGetMaxSize) {
                    throw new InvalidCallException('Bad Request', 400);
                }
                break;
            case 'POST':
            case 'PUT':
            case 'DELETE':
            case 'HEAD':
            case 'OPTIONS':
                if ($this->openHttpProtocol == false) {
                    throw new InvalidCallException('Method Not Implemented', 501);
                }
                if (strlen($headerString) > $this->httpMaxHeaderSize) {
                    throw new InvalidCallException('Header Too Large', 400);
                }
                break;
            case 'TCP':
                if (strlen($headerString) > $this->httpMaxHeaderSize) {
                    throw new InvalidCallException('Header Too Large', 400);
                }
                break;
            default:
                throw new InvalidCallException('Method Not Allowed', 405);
                break;
        }
        // path info
        $_SERVER['PATH_INFO'] = $_SERVER['REQUEST_URI'];
        // multipart/form-data
        $httpPostBoundary = '';
        unset($parts[0]); // POST /user/update
        $headers = [];
        foreach ($parts as $content) {
            // \r\n\r\n
            if (empty($content)) {
                continue;
            }
            list($key, $value)       = explode(':', $content, 2);
            $headerKey               = ucwords(strtolower($key), '-');
            $key                     = str_replace('-', '_', strtoupper($key));
            $value                   = trim($value);
            $_SERVER['HTTP_' . $key] = $value;
            $headers[$headerKey]     = $value;
            switch ($key) {
                // HTTP_HOST
                case 'HOST':
                    $tmp                    = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if (isset($tmp[1])) {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                // cookie
                case 'COOKIE':
                    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
                // content-type
                case 'CONTENT_TYPE':
                    if (!preg_match('/boundary="?(\S+)"?/', $value, $match)) {
                        $_SERVER['CONTENT_TYPE'] = $value;
                    } else {
                        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
                        $httpPostBoundary        = '--' . $match[1];
                    }
                    break;
                case 'CONTENT_LENGTH':
                    $_SERVER['CONTENT_LENGTH'] = $value;
                    break;
            }
        }
        if (in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST', 'TCP']) && !isset($_SERVER['CONTENT_LENGTH'])) {
            throw new InvalidCallException('Length Required', 411);
        }
        // Parse $_POST.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'multipart/form-data') {
                $this->httpPostBoundary = $httpPostBoundary;
            }
        }
        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if ($_SERVER['QUERY_STRING']) {
            // $GET
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        } else {
            $_SERVER['QUERY_STRING'] = '';
        }
        $request->setGet($_GET);
        $request->setCookies($_COOKIE);
        $request->setServer($_SERVER);
        $request->setHead($headers);
    }

    /**
     * reset global array when request end
     * @param int $fd
     */
    protected function unsetGlobal($fd)
    {
        $_POST   = $_GET   = $_COOKIE   = $_REQUEST   = $_SESSION   = $_FILES   = $_SERVER   = array();
        $_SERVER = static::$backupServer;

        $buffer = static::$buffers[$fd] ?? null;
        if ($buffer) {
            $tmpRawFile = $buffer->getRequest()->getPutRawTempFile();
            if (is_file($tmpRawFile)) {
                unlink($tmpRawFile);
            }
            $postTempFile = $buffer->getRequest()->getPostRawTempFile();
            if (is_file($postTempFile)) {
                unlink($postTempFile);
            }
        }
        unset($buffer);
        unset(static::$buffers[$fd]);
    }

    /**
     * init the buffer for request
     * @param $fd
     * @return Buffer
     */
    protected function initBuffer($fd)
    {
        if (!isset(static::$buffers[$fd])) {
            static::$buffers[$fd] = Buffer::getInstance();
            static::$buffers[$fd]->getRequest()->setUploadTempDir($this->tmpUploadDir);
            $_SERVER['REQUEST_TIME']       = static::$buffers[$fd]->getRequestBegin();
            $_SERVER['REQUEST_TIME_FLOAT'] = static::$buffers[$fd]->getRequestBeginFloat();
        }
        return static::$buffers[$fd];
    }
}
