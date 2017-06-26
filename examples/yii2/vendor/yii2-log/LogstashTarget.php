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
/**
 *
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */
/**
 * @link      https://github.com/yii-log/yii2-log
 * @copyright Copyright (c) 2014 Roman Levishchenko <index.0h@gmail.com>
 * @license   https://raw.github.com/yii-log/yii2-log/master/LICENSE
 */

namespace yiilog;

use yiilog\base\EmergencyTrait;
use yiilog\base\TargetTrait;

/**
 * @author Roman Levishchenko <index.0h@gmail.com>
 */
class LogstashTarget extends \yii\log\Target
{
    use TargetTrait;
    use EmergencyTrait;

    /** @var string Connection configuration to Logstash. */
    public $dsn = 'tcp://localhost:3333';

    /**
     * @inheritdoc
     */
    public function export()
    {
        try {
            $socket = stream_socket_client($this->dsn, $errorNumber, $error, 30);

            foreach ($this->messages as &$message) {
                fwrite($socket, $this->formatMessage($message) . "\r\n");
            }

            fclose($socket);
        } catch (\Exception $error) {
            $this->emergencyExport(
                [
                    'dsn'         => $this->dsn,
                    'error'       => $error->getMessage(),
                    'errorNumber' => $error->getCode(),
                    'trace'       => $error->getTraceAsString(),
                ]
            );
        }
    }
}
