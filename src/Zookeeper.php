<?php
/**
 * briarbear
 * User: lushuncheng<admin@lushuncheng.com>
 * Date: 2017/3/28
 * Time: 14:26
 * @link https://github.com/lscgzwd
 * @copyright Copyright (c) 2017 Lu Shun Cheng (https://github.com/lscgzwd)
 * @licence http://www.apache.org/licenses/LICENSE-2.0
 * @author Lu Shun Cheng (lscgzwd@gmail.com)
 */

namespace BriarBear;

use BriarBear\Exception\InvalidParamException;
use BriarBear\Exception\NoZookeeperServerException;

class Zookeeper extends Object
{
    const TYPE_MASTER_SLAVE         = 1; // 主备，主执行任务，备用机不执行
    const TYPE_CLUSTER              = 2; // 集群模式，主分配任务到集群机器执行
    const TYPE_SERVICE              = 3; // 服务模式，仅注册为服务提供方
    const TYPE_CLIENT               = 4; // 服务使用者，监控节点，获取所有的服务提供方
    public $hosts                   = ['100.73.16.2:2181', '100.73.16.3:2181', '100.73.16.4:2181'];
    protected $hostCursor           = 0;
    protected $hostErrors           = [];
    public $maxConnectionErrorTimes = 3; // 每个zk最大连续错误次数
    public $retryInterval           = 7200; // zk超过最大错误次数被踢除后，多少时间内重新尝试复活 单位秒
    public $namespace               = 'qiye-task';
    public $zkType                  = 1; // 默认主备模式
    public $serviceAddress          = null; // 服务模式时，必须提供，服务地址 ['tcp' => '', 'http' => '', 'websocket' => '']
    protected $acl                  = [
        [
            'perms'  => \Zookeeper::PERM_ALL,
            'scheme' => 'world',
            'id'     => 'anyone',
        ],
    ];

    /**
     * @var \Zookeeper
     */
    public $zk = null;
    /**
     * @var string
     */
    public $slaveHost = null;
    /**
     * @var bool 是否主服务
     */
    public $isMaster = false;

    public function open()
    {
        if ($this->zk === null) {
            $zk = new \Zookeeper();
            while (true) {
                $host = null;
                foreach ($this->hosts as $key => $_host) {
                    if (!isset($this->hostErrors[$key])
                        || $this->hostErrors[$key]['times'] < $this->maxConnectionErrorTimes
                        || ($this->hostErrors[$key]['times'] >= $this->maxConnectionErrorTimes && (time() - $this->hostErrors[$key]['lastErrorTime']) > $this->retryInterval)) {
                        $this->hostCursor = $key;
                        $host             = $_host;
                        break;
                    }
                }
                if ($host === null) {
                    \BriarBear\BriarBear::error('Connect to zookeeper fail. No more server to try.');
                    throw new NoZookeeperServerException('Connect to zookeeper fail. No more server to try.');
                    break;
                }
                try {
                    $zk->connect($host);
                    $this->zk = $zk;
                    break;
                } catch (\Throwable $e) {
                    if (!isset($this->hostErrors[$this->hostCursor])) {
                        $this->hostErrors[$this->hostCursor] = ['times' => 1, 'lastErrorTime' => time()];
                    } else {
                        $this->hostErrors[$this->hostCursor]['times']++;
                        $this->hostErrors[$this->hostCursor]['lastErrorTime'] = time();
                    }
                }
            }
        }
    }
    public function close()
    {
        $this->zk = null;
    }
    public function registerService($serviceAddress = null)
    {
        if (null === $this->serviceAddress && !is_array($serviceAddress) && !is_array($this->serviceAddress)) {
            throw new InvalidParamException('Can not register as service, serviceAddress is empty');
        }
        if ($serviceAddress === null || !is_array($serviceAddress)) {
            $serviceAddress = $this->serviceAddress;
        }
        $paths = $this->resolveServicePath($serviceAddress);
        if (empty($paths)) {
            throw new InvalidParamException('Can not register as service, serviceAddress is empty');
        }
        try {
            $this->createPaths($paths);
        } catch (\Throwable $exception) {
            if ($exception instanceof NoZookeeperServerException) {
                throw $exception;
            } else {
                \BriarBear\BriarBear::error($exception->__toString());
                $this->recordError();
                $this->close();
                $this->registerService($serviceAddress);
            }
        }
        $this->zkType = static::TYPE_SERVICE;
    }
    public function resolveServicePath($serviceAddress)
    {
        $paths = [];
        foreach ($serviceAddress as $type => $address) {
            switch ($type) {
                case 'tcp':
                case 'http':
                case 'websocket':
                    $paths[] = $this->namespace . '/' . $type . '/' . $address;
                    break;
            }
        }
        return $paths;
    }
    public function createPaths(array $paths)
    {
        $this->open();
        foreach ($paths as $key => $path) {
            $path = trim($path, '/\\ ');
            $this->createPathRecursion($path);
        }
    }
    protected function createPathRecursion(string $path)
    {
        $path = explode('/', $path);
        $key  = '';
        while (true) {
            $key .= '/' . array_shift($path);
            if (!$this->zk->exists($key)) {
                if (count($path) === 0) {
                    $this->zk->create($key, microtime(true), $this->acl, \Zookeeper::EPHEMERAL);
                    break;
                } else {
                    $this->zk->create($key, microtime(true), $this->acl);
                }
            } else {
                if (count($path) === 0) {
                    break;
                }
            }
        }
    }
    public function registerSlave(string $host)
    {
        $path = $this->namespace . '/slave/' . $host;
        try {
            // 注册slave
            $this->createPaths([$path]);

            $this->slaveHost = $host;
            $this->zkType    = static::TYPE_MASTER_SLAVE;
            // 选主
            $first = $this->watchFirstRegisterSlave();
            if ($first == $host) {
                $this->isMaster = true;
            } else {
                $this->isMaster = false;
            }
            // 监控slave节点，如果有节点退出，重新选主
            $this->watchMaster();
        } catch (\Throwable $exception) {
            $this->isMaster = false;
            if ($exception instanceof NoZookeeperServerException) {
                throw $exception;
            } else {
                \BriarBear\BriarBear::error($exception->__toString());
                $this->recordError();
                $this->close();
                $this->registerSlave($host);
            }
        }

    }
    public function recordError()
    {
        if (!isset($this->hostErrors[$this->hostCursor])) {
            $this->hostErrors[$this->hostCursor] = ['times' => 1, 'lastErrorTime' => time()];
        } else {
            $this->hostErrors[$this->hostCursor]['times']++;
            $this->hostErrors[$this->hostCursor]['lastErrorTime'] = time();
        }
    }
    public function watchMaster()
    {
        $path = '/' . $this->namespace . '/slave';
        $this->zk->getChildren($path, [$this, 'watchMasterCallback']);
    }
    public function watchMasterCallback()
    {
        $first = $this->watchFirstRegisterSlave();
        if ($first == $this->slaveHost) {
            $this->isMaster = true;
        } else {
            $this->isMaster = false;
        }

        $this->watchMaster();
    }
    public function watchFirstRegisterSlave(): string
    {
        $path      = '/' . $this->namespace . '/slave';
        $hosts     = $this->zk->getChildren($path);
        $minTime   = null;
        $firstHost = null;
        foreach ($hosts as $key => $host) {
            $registerTime = $this->zk->get($path . '/' . $host);
            if ($minTime === null || $minTime > $registerTime) {
                $firstHost = $host;
                $minTime   = $registerTime;
            }
        }
        return $firstHost;
    }

    public function __call($name, $params)
    {
        if (method_exists($this->zk, $name)) {
            return call_user_func_array([$this->zk, $name], $params);
        }
        parent::__call($name, $params); // TODO: Change the autogenerated stub
    }
}
