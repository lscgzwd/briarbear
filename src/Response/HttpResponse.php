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

use BriarBear\Exception\InvalidParamException;
use BriarBear\Response\Response;

class HttpResponse extends Response
{
    public $httpProtocol        = 'HTTP/1.1';
    public $httpStatus          = 200;
    public $head                = [];
    public $cookie              = [];
    public $body                = null;
    protected $file             = null;
    public static $HTTP_HEADERS = array(
        100 => '100 Continue',
        101 => '101 Switching Protocols',
        200 => '200 OK',
        201 => '201 Created',
        204 => '204 No Content',
        206 => '206 Partial Content',
        300 => '300 Multiple Choices',
        301 => '301 Moved Permanently',
        302 => '302 Found',
        303 => '303 See Other',
        304 => '304 Not Modified',
        307 => '307 Temporary Redirect',
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        405 => '405 Method Not Allowed',
        406 => '406 Not Acceptable',
        408 => '408 Request Timeout',
        410 => '410 Gone',
        411 => '411 Length Required',
        413 => '413 Request Entity Too Large',
        414 => '414 Request URI Too Long',
        415 => '415 Unsupported Media Type',
        416 => '416 Requested Range Not Satisfiable',
        417 => '417 Expectation Failed',
        500 => '500 Internal Server Error',
        501 => '501 Method Not Implemented',
        503 => '503 Service Unavailable',
        506 => '506 Variant Also Negotiates',
    );
    /**
     * 设置Http状态
     * @param $code
     */
    public function setHttpStatus($code)
    {
        $this->head[0]    = $this->httpProtocol . ' ' . self::$HTTP_HEADERS[$code];
        $this->httpStatus = $code;
    }
    public function isFileDownload()
    {
        return $this->file !== null;
    }
    /**
     * 设置Http头信息
     * @param $key
     * @param $value
     */
    public function setHeader($key, $value)
    {
        $this->head[$key] = $value;
        return $this;
    }
    public function setBody($body)
    {
        $this->body = $body;
        return $this;
    }
    public function addBody($body)
    {
        $this->body .= $body;
        return $this;
    }
    public function getBody()
    {
        return $this->body;
    }
    public function getBodyLength()
    {
        return strlen($this->body);
    }
    public function sendFile($file, $attachmentName = '', $attach = true)
    {
        if (!is_file($file)) {
            throw new InvalidParamException('Can not send file, file not exist:' . $file);
        }
        if ($attach === true) {
            $this->setHeader('Pragma', 'public')
                ->setHeader('Accept-Ranges', 'bytes')
                ->setHeader('Expires', '0')
                ->setHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
                ->setHeader('Content-Disposition', "attachment; filename=\"{$attachmentName}\"");
        } else {
            $this->setHeader('Cache-Control', 'max-age=' . \BriarBear\BriarBear::$server->httpStaticCacheTime)
                ->setHeader('Pragma', 'cache')
                ->setHeader('Last-Modified', date('D, d M Y H:i:s \G\M\T', filemtime($file)))
                ->setHeader('Expires', date('D, d M Y H:i:s \G\M\T', time()+\BriarBear\BriarBear::$server->httpStaticCacheTime));
        }

        clearstatcache(true, $file);

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file);
        $this->setHeader('Content-Type', $mimeType);

        $this->setHeader('Content-Length', filesize($file));
        unset($finfo);
        $this->file = $file;
    }
    public function getFile()
    {
        return $this->file;
    }
    public function gzipSendFile()
    {
        $this->setBody(file_get_contents($this->file));
        $this->file = null;
    }
    /**
     * 设置COOKIE
     * @param $name
     * @param null $value
     * @param null $expire
     * @param string $path
     * @param null $domain
     * @param null $secure
     * @param null $httponly
     */
    public function setCookie($name, $value = null, $expire = null, $path = '/', $domain = null, $secure = null, $httponly = null)
    {
        if ($value == null) {
            $value = 'deleted';
        }
        $cookie = "$name=$value";
        if ($expire) {
            $cookie .= "; expires=" . date("D, d-M-Y H:i:s T", $expire);
        }
        if ($path) {
            $cookie .= "; path=$path";
        }
        if ($secure) {
            $cookie .= "; secure";
        }
        if ($domain) {
            $cookie .= "; domain=$domain";
        }
        if ($httponly) {
            $cookie .= '; httponly';
        }
        $this->cookie[] = $cookie;
    }
    /**
     * 添加http header
     * @param $header
     */
    public function addHeaders(array $header)
    {
        $this->head = array_merge($this->head, $header);
    }
    public function getHeader($fastcgi = false)
    {
        // session
        if (!isset($_COOKIE[session_name()]) && session_status() == PHP_SESSION_ACTIVE) {
            $this->setCookie(
                session_name()
                , session_id()
                , ini_get('session.cookie_lifetime')
                , ini_get('session.cookie_path')
                , ini_get('session.cookie_domain')
                , ini_get('session.cookie_secure')
                , ini_get('session.cookie_httponly')
            );
        }
        $out = '';
        if ($fastcgi) {
            $out .= 'Status: ' . $this->httpStatus . ' ' . self::$HTTP_HEADERS[$this->httpStatus] . "\r\n";
        } else {
            //Protocol
            if (isset($this->head[0])) {
                $out .= $this->head[0] . "\r\n";
                unset($this->head[0]);
            } else {
                $out = "HTTP/1.1 200 OK\r\n";
            }
        }
        //fill header
        if (!isset($this->head['Server'])) {
            $this->head['Server'] = \BriarBear\BriarBear::$server->serverName . ' ' . \BriarBear\BriarBear::getVersion();
        }
        if (!isset($this->head['Content-Type'])) {
            $this->head['Content-Type'] = 'text/html; charset=UTF-8';
        }
        if (!isset($this->head['Content-Length']) && !is_null($this->body)) {
            $this->head['Content-Length'] = strlen($this->body);
        }
        //Headers
        foreach ($this->head as $k => $v) {
            $out .= $k . ': ' . $v . "\r\n";
        }
        //Cookies
        if (!empty($this->cookie) and is_array($this->cookie)) {
            foreach ($this->cookie as $v) {
                $out .= "Set-Cookie: $v\r\n";
            }
        }
        //End
        $out .= "\r\n";
        return $out;
    }
    public function noCache()
    {
        $this->head['Cache-Control'] = 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0';
        $this->head['Pragma']        = 'no-cache';
    }
}
