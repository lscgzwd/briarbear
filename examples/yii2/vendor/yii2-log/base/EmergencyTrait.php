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

namespace yiilog\base;

use yii\helpers\ArrayHelper;

/**
 * Current class needs to write logs on external service exception.
 *
 * @property array messages The messages that are retrieved from the logger so far by this log target.
 *
 * @author Roman Levishchenko <index.0h@gmail.com>
 */
trait EmergencyTrait
{
    /** @var string Alias of log file. */
    public $emergencyLogFile = '@runtime/logs/logService.log';

    /**
     * @param array $data Additional information to log messages from target.
     */
    public function emergencyExport($data)
    {
        $this->emergencyPrepareMessages($data);
        $text = implode("\n", array_map([$this, 'formatMessage'], $this->messages)) . "\n";

        file_put_contents(\Yii::getAlias($this->emergencyLogFile), $text, FILE_APPEND);
    }

    /**
     * @param array $data Additional information to log messages from target.
     */
    protected function emergencyPrepareMessages($data)
    {
        foreach ($this->messages as &$message) {
            $message[0] = ArrayHelper::merge($message[0], ['emergency' => $data]);
        }
    }
}
