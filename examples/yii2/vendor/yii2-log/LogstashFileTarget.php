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

use Yii;
use yiilog\base\TargetTrait;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;

/**
 * @author Roman Levishchenko <index.0h@gmail.com>
 */
class LogstashFileTarget extends \yii\log\FileTarget
{
    use TargetTrait;

    public $logPath;
    public $logFileSuffix         = '';
    public $logFilePrefix         = '';
    public $logFileExt            = '.log';
    public $logFileNameDateFormat = 'Ymd';

    /**
     * Initializes the route.
     * This method is invoked after the route is created by the route manager.
     */
    public function init()
    {
        $this->logPath = rtrim(Yii::getAlias($this->logPath), '/');

        if ($this->maxLogFiles < 1) {
            $this->maxLogFiles = 1;
        }
        if ($this->maxFileSize < 1) {
            $this->maxFileSize = 1;
        }
        if (defined('BRIARBEAR_PATH')) {
            $this->initLogFile();
        }
    }

    /**
     * 初使化日志文件路径
     */
    protected function initLogFile()
    {
        $this->logFile = $this->logPath . '/' . $this->logFilePrefix . date($this->logFileNameDateFormat) . $this->logFileSuffix . $this->logFileExt;

        $logPath = dirname($this->logFile);
        if (!is_dir($logPath)) {
            FileHelper::createDirectory($logPath, $this->dirMode, true);
        }
    }

    /**
     * Writes log messages to a file.
     * @throws InvalidConfigException if unable to open the log file for writing
     */
    public function export()
    {
        $text = implode("\n", array_map([$this, 'formatMessage'], $this->messages)) . "\n";

        $this->initLogFile();

        if (($fp = @fopen($this->logFile, 'a')) === false) {
            throw new InvalidConfigException("Unable to append to log file: {$this->logFile}");
        }
        @flock($fp, LOCK_EX);
        if ($this->enableRotation) {
            // clear stat cache to ensure getting the real current file size and not a cached one
            // this may result in rotating twice when cached file size is used on subsequent calls
            clearstatcache();
        }
        if ($this->enableRotation && @filesize($this->logFile) > $this->maxFileSize * 1024) {
            $this->rotateFiles();
            @flock($fp, LOCK_UN);
            @fclose($fp);
            @file_put_contents($this->logFile, $text, FILE_APPEND | LOCK_EX);
        } else {
            @fwrite($fp, $text);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
        if ($this->fileMode !== null) {
            @chmod($this->logFile, $this->fileMode);
        }
    }
}
