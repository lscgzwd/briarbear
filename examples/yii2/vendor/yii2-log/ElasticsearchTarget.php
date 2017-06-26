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
use yii\log\Target;

/**
 * @author Roman Levishchenko <index.0h@gmail.com>
 */
class ElasticsearchTarget extends Target
{
    use TargetTrait;
    use EmergencyTrait;

    /** @var string Elasticsearch index name. */
    public $index = 'yii';

    /** @var string Elasticsearch type name. */
    public $type = 'log';

    /** @var string Yii Elasticsearch component name. */
    public $componentName = 'elasticsearch';

    /**
     * @inheritdoc
     */
    public function export()
    {
        try {
            $messages = array_map([$this, 'formatMessage'], $this->messages);
            foreach ($messages as &$message) {
                \Yii::$app->{$this->componentName}->post([$this->index, $this->type], [], $message);
            }
        } catch (\Exception $error) {
            $this->emergencyExport(
                [
                    'index'       => $this->index,
                    'type'        => $this->type,
                    'error'       => $error->getMessage(),
                    'errorNumber' => $error->getCode(),
                    'trace'       => $error->getTraceAsString(),
                ]
            );
        }
    }
}
