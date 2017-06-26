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

namespace BriarBear\Exception;

class NoZookeeperServerException extends \Exception
{
    /**
     * @return string the user-friendly name of this exception
     */
    public function getName()
    {
        return 'No Zookeeper Servers:';
    }
}
