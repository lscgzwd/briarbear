<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\httpclient;

use yii\base\Exception;
use yii\web\Cookie;
use yii\web\HeaderCollection;

/**
 * Response represents HTTP request response.
 *
 * @property string $statusCode response status code.
 * @property boolean $isOk whether response is OK.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.0
 */
class Response extends Message
{
    /**
     * @inheritdoc
     */
    public function getData()
    {
        $data = parent::getData();
        if ($data === null) {
            $content = $this->getContent();
            if (!empty($content)) {
                $data = $this->getParser()->parse($this);
                $this->setData($data);
            }
        }
        return $data;
    }

    /**
     * @inheritdoc
     */
    public function getCookies()
    {
        $cookieCollection = parent::getCookies();
        if ($cookieCollection->getCount() === 0 && $this->getHeaders()->has('set-cookie')) {
            $cookieStrings = $this->getHeaders()->get('set-cookie', [], false);
            foreach ($cookieStrings as $cookieString) {
                $cookieCollection->add($this->parseCookie($cookieString));
            }
        }
        return $cookieCollection;
    }

    /**
     * Returns status code.
     * @throws Exception on failure.
     * @return string status code.
     */
    public function getStatusCode()
    {
        $headers = $this->getHeaders();
        if ($headers->has('http-code')) {
            // take into account possible 'follow location'
            $statusCodeHeaders = $headers->get('http-code', null, false);
            return empty($statusCodeHeaders) ? null : end($statusCodeHeaders);
        }
        throw new Exception('Unable to get status code: referred header information is missing.');
    }

    /**
     * Checks if response status code is OK (status code = 20x)
     * @return boolean whether response is OK.
     */
    public function getIsOk()
    {
        return strncmp('20', $this->getStatusCode(), 2) === 0;
    }

    /**
     * Returns default format automatically detected from headers and content.
     * @return null|string format name, 'null' - if detection failed.
     */
    protected function defaultFormat()
    {
        $format = $this->detectFormatByHeaders($this->getHeaders());
        if ($format === null) {
            $format = $this->detectFormatByContent($this->getContent());
        }
        return $format;
    }

    /**
     * Detects format from headers.
     * @param HeaderCollection $headers source headers.
     * @return null|string format name, 'null' - if detection failed.
     */
    protected function detectFormatByHeaders(HeaderCollection $headers)
    {
        $contentType = $headers->get('content-type');
        if (!empty($contentType)) {
            if (stripos($contentType, 'json') !== false) {
                return Client::FORMAT_JSON;
            }
            if (stripos($contentType, 'urlencoded') !== false) {
                return Client::FORMAT_URLENCODED;
            }
            if (stripos($contentType, 'xml') !== false) {
                return Client::FORMAT_XML;
            }
        }
        return null;
    }

    /**
     * Detects response format from raw content.
     * @param string $content raw response content.
     * @return null|string format name, 'null' - if detection failed.
     */
    protected function detectFormatByContent($content)
    {
        if (preg_match('/^\\{.*\\}$/is', $content)) {
            return Client::FORMAT_JSON;
        }
        if (preg_match('/^[^=|^&]+=[^=|^&]+(&[^=|^&]+=[^=|^&]+)*$/', $content)) {
            return Client::FORMAT_URLENCODED;
        }
        if (preg_match('/^<.*>$/s', $content)) {
            return Client::FORMAT_XML;
        }
        return null;
    }

    /**
     * Parses cookie value string, creating a [[Cookie]] instance.
     * @param string $cookieString cookie header string.
     * @return Cookie cookie object.
     */
    private function parseCookie($cookieString)
    {
        $params = [];
        $pairs = explode(';', $cookieString);
        foreach ($pairs as $number => $pair) {
            $pair = trim($pair);
            if (strpos($pair, '=') === false) {
                $params[$this->normalizeCookieParamName($pair)] = true;
            } else {
                list($name, $value) = explode('=', $pair, 2);
                if ($number === 0) {
                    $params['name'] = $name;
                    $params['value'] = urldecode($value);
                } else {
                    $params[$this->normalizeCookieParamName($name)] = urldecode($value);
                }
            }
        }
        return new Cookie($params);
    }

    /**
     * @param string $rawName raw cookie parameter name.
     * @return string name of [[Cookie]] field.
     */
    private function normalizeCookieParamName($rawName)
    {
        static $nameMap = [
            'expires' => 'expire',
            'httponly' => 'httpOnly',
        ];
        $name = strtolower($rawName);
        if (isset($nameMap[$name])) {
            $name = $nameMap[$name];
        }
        return $name;
    }

    /**
     * @return ParserInterface message parser instance.
     */
    private function getParser()
    {
        return $this->client->getParser($this->getFormat());
    }
}