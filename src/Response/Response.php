<?php
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

namespace BriarBear\Response;

use BriarBear\Exception\InvalidConfigException;
use BriarBear\Object;

abstract class Response extends Object
{
    protected static $instances = [];
    public static function getInstance($type)
    {
        $type = strtoupper($type);
        switch ($type) {
            case 'TCP':
                if (!isset(static::$instances[$type])) {
                    static::$instances[$type] = new TcpResponse();
                }
                break;
            case 'HTTP':
                if (!isset(static::$instances[$type])) {
                    static::$instances[$type] = new HttpResponse();
                }
                break;
            default:
                throw new InvalidConfigException('Unknown response type:' . $type);
                break;
        }
        return clone static::$instances[$type];
    }
}
