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

/**
 * Class CronHeap
 * Spl内存堆
 * @package BriarBear
 */
class CronHeap extends \SplHeap
{
    public static $instance = [];

    /**
     * @param $type
     * @return CronHeap
     */
    public static function getInstance($type)
    {
        if (!isset(static::$instance[$type])) {
            static::$instance[$type] = new static();
        }
        return static::$instance[$type];
    }

    protected function compare($val1, $val2)
    {
        if ($val1['tick'] === $val2['tick']) {
            return 0;
        }

        return $val1['tick'] < $val2['tick'] ? 1 : -1;
    }

    public static function setTask($secList, $task, $type)
    {
        $time = time();
        foreach ($secList as $sec) {
            if ($sec > 60) {
                static::getInstance($type)->insert(array('tick' => $sec, 'task' => $task));
            } else {
                static::getInstance($type)->insert(array('tick' => $time + $sec, 'task' => $task));
            }
        }
    }

    public static function getTask($type)
    {
        $time  = time();
        $ticks = array();
        while (static::getInstance($type)->valid()) {
            $data = static::getInstance($type)->extract();
            if ($data['tick'] > $time) {
                static::getInstance($type)->insert($data);
                break;
            } else {
                $ticks[] = $data['task'];
            }
        }
        return $ticks;
    }
}
