<?php
namespace BriarBear\Helpers;

/**
 * briabear
 * User: lushuncheng<admin@lushuncheng.com>
 * Date: 2017/3/1
 * Time: 18:17
 * @link https://github.com/lscgzwd
 * @copyright Copyright (c) 2017 Lu Shun Cheng (https://github.com/lscgzwd)
 * @licence http://www.apache.org/licenses/LICENSE-2.0
 * @author Lu Shun Cheng (lscgzwd@gmail.com)
 */
class CronParseHelper
{
    public static $error;
    /**
     *  解析crontab的定时格式，linux只支持到分钟/，这个类支持到秒
     * @param string $crontabString :
     *
     *      0     1    2    3    4    5
     *      *     *    *    *    *    *
     *      -     -    -    -    -    -
     *      |     |    |    |    |    |
     *      |     |    |    |    |    +----- day of week (0 - 6) (Sunday=0)
     *      |     |    |    |    +----- month (1 - 12)
     *      |     |    |    +------- day of month (1 - 31)
     *      |     |    +--------- hour (0 - 23)
     *      |     +----------- min (0 - 59)
     *      +------------- sec (0-59)
     * @param int $startTime timestamp [default=current timestamp]
     * @return int unix timestamp - 下一分钟内执行是否需要执行任务，如果需要，则把需要在那几秒执行返回
     * @throws \InvalidArgumentException 错误信息
     */
    public static function parse($crontabString, $startTime = null)
    {
        if (!preg_match('/^((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)$/i', trim($crontabString))) {
            if (!preg_match('/^((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)$/i', trim($crontabString))) {
                static::$error = "Invalid cron string: " . $crontabString;
                return false;
            }
        }
        if ($startTime && !is_numeric($startTime)) {
            static::$error = "\$startTime must be a valid unix timestamp ($startTime given)";
            return false;
        }
        $cron  = preg_split("/[\s]+/i", trim($crontabString));
        $start = empty($startTime) ? time() : $startTime;
        $date  = [];
        if (count($cron) == 6) {
            $date = [
                'second'  => static::_parseCronNumber($cron[0], 0, 59),
                'minutes' => static::_parseCronNumber($cron[1], 0, 59),
                'hours'   => static::_parseCronNumber($cron[2], 0, 23),
                'day'     => static::_parseCronNumber($cron[3], 1, 31),
                'month'   => static::_parseCronNumber($cron[4], 1, 12),
                'week'    => static::_parseCronNumber($cron[5], 0, 6),
            ];
        } elseif (count($cron) == 5) {
            $date = [
                'second'  => [1 => 1],
                'minutes' => static::_parseCronNumber($cron[0], 0, 59),
                'hours'   => static::_parseCronNumber($cron[1], 0, 23),
                'day'     => static::_parseCronNumber($cron[2], 1, 31),
                'month'   => static::_parseCronNumber($cron[3], 1, 12),
                'week'    => static::_parseCronNumber($cron[4], 0, 6),
            ];
        }

        if (
            in_array(intval(date('i', $start)), $date['minutes']) &&
            in_array(intval(date('G', $start)), $date['hours']) &&
            in_array(intval(date('j', $start)), $date['day']) &&
            in_array(intval(date('w', $start)), $date['week']) &&
            in_array(intval(date('n', $start)), $date['month'])

        ) {

            return $date['second'];
        }
        return null;
    }

    /**
     * 解析单个配置的含义
     * @param $s
     * @param $min
     * @param $max
     * @return array
     */
    protected static function _parseCronNumber($s, $min, $max)
    {
        $result = [];
        $v1     = explode(",", $s);
        foreach ($v1 as $v2) {
            $v3   = explode("/", $v2);
            $step = empty($v3[1]) ? 1 : $v3[1];
            $v4   = explode("-", $v3[0]);
            $_min = count($v4) == 2 ? $v4[0] : ($v3[0] == "*" ? $min : $v3[0]);
            $_max = count($v4) == 2 ? $v4[1] : ($v3[0] == "*" ? $max : $v3[0]);
            for ($i = $_min; $i <= $_max; $i += $step) {
                $result[$i] = intval($i);
            }
        }
        ksort($result);
        return $result;
    }

    public static function _parseArray($crontabArray, $startTime)
    {
        $result = [];
        foreach ($crontabArray as $val) {
            if (count(explode(":", $val)) == 2) {
                $val = $val . ":01";
            }
            $time = strtotime($val);
            if ($time >= $startTime && $time < $startTime + 60) {
                $result[$time] = $time;
            }
        }
        return $result;
    }
}
