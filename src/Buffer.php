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

class Buffer extends Object
{
    /**
     * @var Request
     */
    public $request;
    /**
     * @var boolean
     */
    public $headerParsed = false;

    /**
     * @var int
     */
    public $acceptBodyLength = 0;
    /**
     * @var int
     */
    public $acceptTotalLength = 0;
    public $requestBegin      = 0;

    public $requestBeginFloat = 0;
    public $requestDataFinish = 0;
    /**
     * @var Buffer $instance
     */
    public static $instance = null;
    /**
     * @return int
     */
    public function getRequestBegin(): int
    {
        return $this->requestBegin;
    }

    /**
     * @return int
     */
    public function getRequestBeginFloat(): int
    {
        return $this->requestBeginFloat;
    }

    /**
     * @return int
     */
    public function getRequestDataFinish(): int
    {
        return $this->requestDataFinish;
    }

    public function init()
    {
        parent::init(); // TODO: Change the autogenerated stub
        $this->request           = Request::getInstance();
        $this->requestBegin      = time();
        $this->requestBeginFloat = microtime(true);
    }

    /**
     * @param bool $new
     * @return Buffer
     */
    public static function getInstance($new = true): Buffer
    {
        if (static::$instance === null) {
            static::$instance = \BriarBear\BriarBear::createObject('BriarBear\Buffer');
        }
        if ($new === true) {
            return clone static::$instance;
        }
        return static::$instance;
    }

    /**
     * 定义类的实例被克隆时的操作
     */
    public function __clone()
    {
        $this->requestBegin      = time();
        $this->requestBeginFloat = microtime(true);
        $this->request           = Request::getInstance();
        // TODO: Implement __clone() method.
    }

    /**
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @param Request $request
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @return bool
     */
    public function isHeaderParsed(): bool
    {
        return $this->headerParsed;
    }

    /**
     * @param bool $headerParsed
     */
    public function setHeaderParsed(bool $headerParsed)
    {
        $this->headerParsed = $headerParsed;
    }

    /**
     * @return int
     */
    public function getAcceptBodyLength(): int
    {
        return $this->acceptBodyLength;
    }

    /**
     * @param int $acceptBodyLength
     */
    public function setAcceptBodyLength(int $acceptBodyLength)
    {
        $this->acceptBodyLength = $acceptBodyLength;
    }

    /**
     * @return int
     */
    public function getAcceptTotalLength(): int
    {
        return $this->acceptTotalLength;
    }

    /**
     * @param int $acceptTotalLength
     */
    public function setAcceptTotalLength(int $acceptTotalLength)
    {
        $this->acceptTotalLength = $acceptTotalLength;
    }
}
