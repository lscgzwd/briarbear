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

class Request extends Object
{
    /**
     * @var array
     */
    public $head = [];
    /**
     * @var array
     */
    public $get = [];
    /**
     * @var array
     */
    public $post = [];
    /**
     * @var array
     */
    public $files = [];
    /**
     * @var array
     */
    public $cookies = [];
    /**
     * @var array
     */
    public $server = [];
    /**
     * @var string
     */
    public $putRawTempFile = null;
    /**
     * @var string
     */
    public $postRawTempFile = null;
    /**
     * @var string
     */
    public $rawContent = '';
    /**
     * @var string
     */
    public $uploadTempDir = '';
    /**
     * @var Request $instance
     */
    public static $instance = null;

    const RAW_POST_FILE = 'POST_FILE';
    const RAW           = 'RAW';
    const RAW_PUT_FILE  = 'PUT_FILE';

    public $rawType = self::RAW;

    /**
     * @param string $rawType
     */
    public function setRawType(string $rawType)
    {
        $this->rawType = $rawType;
    }

    /**
     * @param bool $new
     * @return Request
     */
    public static function getInstance($new = true): Request
    {
        if (static::$instance === null) {
            static::$instance = \BriarBear\BriarBear::createObject('BriarBear\Request');
        }
        if ($new === true) {
            return clone static::$instance;
        }
        return static::$instance;
    }

    /**
     * @return string
     */
    public function getUploadTempDir(): string
    {
        return $this->uploadTempDir;
    }

    /**
     * @param string $uploadTempDir
     */
    public function setUploadTempDir(string $uploadTempDir)
    {
        $this->uploadTempDir = $uploadTempDir;
    }

    /**
     * @return array
     */
    public function getHead(): array
    {
        return $this->head;
    }

    /**
     * @param array $head
     */
    public function setHead(array $head)
    {
        $this->head = $head;
    }

    /**
     * @return array
     */
    public function getGet(): array
    {
        return $this->get;
    }

    /**
     * @param array $get
     */
    public function setGet(array $get)
    {
        $this->get = $get;
    }

    /**
     * @return array
     */
    public function getPost(): array
    {
        return $this->post;
    }

    /**
     * @param array $post
     */
    public function setPost(array $post)
    {
        $this->post = $post;
    }

    /**
     * @return array
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * @param array $files
     */
    public function setFiles(array $files)
    {
        $this->files = $files;
    }

    /**
     * @return array
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * @param array $cookies
     */
    public function setCookies(array $cookies)
    {
        $this->cookies = $cookies;
    }

    /**
     * @return array
     */
    public function getServer(): array
    {
        return $this->server;
    }

    /**
     * @param array $server
     */
    public function setServer(array $server)
    {
        $this->server = $server;
    }

    /**
     * @return string
     */
    public function getPutRawTempFile()
    {
        if ($this->putRawTempFile == null) {
            $tmpRawFile           = tempnam($this->uploadTempDir, 'briarbear_put_');
            $this->putRawTempFile = $tmpRawFile;
        }
        return $this->putRawTempFile;
    }

    /**
     * @param string $putRawTempFile
     */
    public function setPutRawTempFile($putRawTempFile)
    {
        $this->putRawTempFile = $putRawTempFile;
    }

    /**
     * @param string $tmpDir
     * @return string
     */
    public function getPostRawTempFile()
    {
        if ($this->postRawTempFile == null) {
            $tmpRawFile            = tempnam($this->uploadTempDir, 'briarbear_post_');
            $this->postRawTempFile = $tmpRawFile;
        }
        return $this->postRawTempFile;
    }

    /**
     * @param string $postRawTempFile
     */
    public function setPostRawTempFile($postRawTempFile)
    {
        $this->postRawTempFile = $postRawTempFile;
    }

    /**
     * @return string
     */
    public function getRawContent(): string
    {
        switch ($this->rawType) {
            case static::RAW:
                return $this->rawContent;
                break;
            case static::RAW_POST_FILE:
                return file_get_contents($this->getPostRawTempFile());
                break;
            case static::RAW_PUT_FILE:
                return file_get_contents($this->getPutRawTempFile());
                break;
        }
        return '';
    }

    /**
     * @param string $rawContent
     */
    public function setRawContent(string $rawContent)
    {
        $this->rawContent = $rawContent;
    }

    /**
     * @param string $data
     */
    public function appendRawContent(string $data)
    {
        $this->rawContent .= $data;
    }

    /**
     * like: php://input
     * @return string
     */
    public function getRawPut()
    {
        return file_get_contents($this->putRawTempFile);
    }

    /**
     * like: php://input
     * @return string
     */
    public function getRawPost()
    {
        return file_get_contents($this->postRawTempFile);
    }
}
