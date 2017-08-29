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

use BriarBear\Exception\InvalidParamException;
use BriarBear\Helpers\CronParseHelper;

class Crontab extends Object
{
    /**
     * @var array
     * ['100.73.16.2:2181', '100.73.16.3:2181', '100.73.16.4:2181']
     */
    public $zookeeperHost      = null;
    public $zookeeperNamespace = 'qiye-crontab';
    public $ipAddress          = null;
    public $zkType             = Zookeeper::TYPE_MASTER_SLAVE;
    /**
     * @var array
     * [
     *      [
     *          'rule' => '* * * * * *',
     *          'class' => '',
     *          'method' => '',
     *          'multiple' => true, // 可否并发执行，即前面一次执行未结束，后面是否可以再执行
     *          'timeout' => 1800, // 超过多少分钟未结束报警
     *          'name' => 'test', // must been unique
     *      ],
     * ]
     */
    public $cronList = [];
    /**
     * @var Zookeeper
     */
    public $zk = null;
    /**
     * @var Server
     */
    public $server = null;
    public function init()
    {
        $this->preRun();
    }

    public function run($server)
    {
        cli_set_process_title("{$server->serverName} crontab");
        \BriarBear\BriarBear::info('run crontab');
        $this->server = $server;

        $this->zk = \BriarBear\BriarBear::createObject([
            'class'     => '\BriarBear\Zookeeper',
            'hosts'     => $this->zookeeperHost,
            'namespace' => $this->zookeeperNamespace,
            'zkType'    => $this->zkType,
        ]);
        // delay to connect to zookeeper, because after stop, the node may be not clear.
        // zookeeper的临时节点在服务停止后不是立即删除，而是根据心跳时间发现后才会删除，为了防止本次重启后新建节点发现节点存在，没有建立，而上次断开
        // 后心跳检测到后把节点删除，导致没有节点存在，此处延迟2分钟后再向zookeeper注册
        while (true) {
            if (swoole_timer_after(120000, [$this, 'registerSlave'])) {
                break;
            }
        }

        // add timer could return false
        while (true) {
            if (swoole_timer_tick(60000, [$this, 'loadCron'])) {
                break;
            }
        }
        while (true) {
            if (swoole_timer_tick(1000, [$this, 'doCron'])) {
                break;
            }
        }
    }
    public function registerSlave()
    {
        \BriarBear\BriarBear::info('crontab register zookeeper');
        $this->zk->registerSlave($this->ipAddress);
    }

    /**
     * 定时任务解析器，每分钟触发一次
     * @return bool
     */
    public function loadCron()
    {
        try {
            if ($this->zk->isMaster === true && $this->server->swooleServer !== null) {
                \BriarBear\BriarBear::info('master_load_cron');
                $time = time();
                foreach ($this->cronList as $key => $cron) {
                    if (!isset($cron['rule']) || !isset($cron['class'])) {
                        continue;
                    }
                    $execTime = CronParseHelper::parse($cron['rule'], $time);
                    if ($execTime) {
                        \BriarBear\BriarBear::info('crontab_next_minute_run:' . json_encode($cron));
                        CronHeap::setTask($execTime, $cron, $this->zookeeperNamespace);
                    }
                }
            }
        } catch (\Throwable $e) {
            \BriarBear\BriarBear::error('loadCronFail:' . $e->__toString());
        }
        return true;
    }
    /**
     * 定时任务执行器，每秒触发一次
     * @return bool
     */
    public function doCron()
    {
        try {
            if ($this->zk->isMaster === true && $this->server->swooleServer !== null) {
                $tasks = CronHeap::getTask($this->zookeeperNamespace);
                if (!empty($tasks)) {
                    \BriarBear\BriarBear::info('crontab_do_this_second:' . json_encode($tasks));
                    foreach ($tasks as $key => $task) {
                        $this->server->swooleServer->sendMessage(json_encode(['type' => 'task', 'task' => $task]), mt_rand(0, $this->server->setting['worker_num'] - 1));
                    }
                }
            }
        } catch (\Throwable $e) {
            \BriarBear\BriarBear::error('doCronFail:' . $e->__toString());
        }
        return true;
    }
    protected function preRun()
    {
        if (empty($this->zookeeperHost)) {
            throw new InvalidParamException('No Zookeeper host given.');
        }
        foreach ($this->zookeeperHost as $key => &$host) {
            $domain = false;
            if (substr($host, 0, 9) == 'domain://') {
                $domain = true;
                $host   = substr($host, 9);
            }
            $tmp = explode(':', $host);

            if (count($tmp) !== 2 || !is_numeric($tmp[1])) {
                throw new InvalidParamException('Wrong Zookeeper host given. usage: 127.0.0.1:2118 or domain://zk.test.com:2118');
            }

            // {1-255}.{0-255}.{0-255}.{0-255}
            if ($domain === false && !preg_match('/^([1-9]{1}\d{0,1}|1\d{2}|2[0-4]{1}\d{1})(\.([1-9]{1}\d{1}|1\d{2}|2[0-4]{1}\d{1}|\d{1})){3}$/', $tmp[0])) {
                throw new InvalidParamException('Wrong Zookeeper host given.usage: 127.0.0.1:2118 or domain://zk.test.com:2118');
            }
        }
    }
    public function setCronList(array $cronList)
    {
        $this->cronList = $cronList;
    }
}
