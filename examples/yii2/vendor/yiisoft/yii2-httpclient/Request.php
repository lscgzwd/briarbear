<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\httpclient;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;

/**
 * Request represents HTTP request.
 *
 * @property string $url target URL.
 * @property string $method request method.
 * @property array $options request options. See [[setOptions()]] for details.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.0
 */
class Request extends Message
{
    /**
     * @var string target URL.
     */
    private $_url;
    /**
     * @var string request method.
     */
    private $_method = 'get';
    /**
     * @var array request options.
     */
    private $_options = [];


    /**
     * @param string $url target URL
     * @return $this self reference.
     */
    public function setUrl($url)
    {
        $this->_url = $url;
        return $this;
    }

    /**
     * @return string target URL
     */
    public function getUrl()
    {
        return $this->_url;
    }

    /**
     * @param string $method request method
     * @return $this self reference.
     */
    public function setMethod($method)
    {
        $this->_method = $method;
        return $this;
    }

    /**
     * @return string request method
     */
    public function getMethod()
    {
        return $this->_method;
    }

    /**
     * Following options are supported:
     * - timeout: integer, the maximum number of seconds to allow request to be executed.
     * - proxy: string, URI specifying address of proxy server. (e.g. tcp://proxy.example.com:5100).
     * - userAgent: string, the contents of the "User-Agent: " header to be used in a HTTP request.
     * - followLocation: boolean, whether to follow any "Location: " header that the server sends as part of the HTTP header.
     * - maxRedirects: integer, the max number of redirects to follow.
     * - sslVerifyPeer: boolean, whether verification of the peer's certificate should be performed.
     * - sslCafile: string, location of Certificate Authority file on local filesystem which should be used with
     *   the 'sslVerifyPeer' option to authenticate the identity of the remote peer.
     * - sslCapath: string, a directory that holds multiple CA certificates.
     *
     * You may set options using keys, which are specific to particular transport, like `[CURLOPT_VERBOSE => true]` in case
     * there is a necessity for it.
     *
     * @param array $options request options.
     * @return $this self reference.
     */
    public function setOptions(array $options)
    {
        $this->_options = $options;
        return $this;
    }

    /**
     * @return array request options.
     */
    public function getOptions()
    {
        return $this->_options;
    }

    /**
     * Adds more options to already defined ones.
     * Please refer to [[setOptions()]] on how to specify options.
     * @param array $options additional options
     * @return $this self reference.
     */
    public function addOptions(array $options)
    {
        $this->options = ArrayHelper::merge($this->options, $options); // `array_merge()` will produce invalid result for cURL options
        return $this;
    }

    /**
     * Adds a content part for multi-part content request.
     * @param string $name part (form input) name.
     * @param string $content content.
     * @param array $options content part options, valid options are:
     *  - contentType - string, part content type
     *  - fileName - string, name of the uploading file
     *  - mimeType - string, part content type in case of file uploading
     * @return $this self reference.
     */
    public function addContent($name, $content, $options = [])
    {
        $multiPartContent = $this->getContent();
        if (!is_array($multiPartContent)) {
            $multiPartContent = [];
        }
        $options['content'] = $content;
        $multiPartContent[$name] = $options;
        $this->setContent($multiPartContent);
        return $this;
    }

    /**
     * Adds a file for upload as multi-part content.
     * @see addContent()
     * @param string $name part (form input) name
     * @param string $fileName full name of the source file.
     * @param array $options content part options, valid options are:
     *  - fileName - string, base name of the uploading file, if not set it base name of the source file will be used.
     *  - mimeType - string, file mime type, if not set it will be determine automatically from source file.
     * @return $this
     */
    public function addFile($name, $fileName, $options = [])
    {
        $content = file_get_contents($fileName);
        if (!isset($options['mimeType'])) {
            $options['mimeType'] = FileHelper::getMimeType($fileName);
        }
        if (!isset($options['fileName'])) {
            $options['fileName'] = basename($fileName);
        }
        return $this->addContent($name, $content, $options);
    }

    /**
     * Adds a string as a file upload.
     * @see addContent()
     * @param string $name part (form input) name
     * @param string $content file content.
     * @param array $options content part options, valid options are:
     *  - fileName - string, base name of the uploading file.
     *  - mimeType - string, file mime type, if not set it 'application/octet-stream' will be used.
     * @return $this
     */
    public function addFileContent($name, $content, $options = [])
    {
        if (!isset($options['mimeType'])) {
            $options['mimeType'] = 'application/octet-stream';
        }
        if (!isset($options['fileName'])) {
            $options['fileName'] = $name . '.dat';
        }
        return $this->addContent($name, $content, $options);
    }

    /**
     * Prepares this request instance for sending.
     * This method should be invoked by transport before sending a request.
     * Do not call this method unless you know what you are doing.
     * @return $this self reference.
     */
    public function prepare()
    {
        if (!empty($this->client->baseUrl)) {
            $url = $this->getUrl();
            if (!preg_match('/^https?:\\/\\//i', $url)) {
                $this->setUrl($this->client->baseUrl . '/' . $url);
            }
        }
        $content = $this->getContent();
        if ($content === null) {
            $this->getFormatter()->format($this);
        } elseif (is_array($content)) {
            $this->prepareMultiPartContent($content);
        }
        return $this;
    }

    /**
     * Prepares multi-part content.
     * @param array $content multi part content.
     */
    private function prepareMultiPartContent(array $content)
    {
        static $disallowedChars = ["\0", '"', "\r", "\n"];

        $contentParts = [];

        $data = $this->getData();
        if (!empty($data)) {
            foreach ($this->composeFormInputs($data) as $name => $value) {
                $name = str_replace($disallowedChars, '_', $name);
                $contentDisposition = 'Content-Disposition: form-data; name="' . $name . '";';
                $contentParts[] = implode("\r\n", [$contentDisposition, '', $value]);
            }
        }

        // process content parts :
        foreach ($content as $name => $contentParams) {
            $headers = [];
            $name = str_replace($disallowedChars, '_', $name);
            $contentDisposition = 'Content-Disposition: form-data; name="' . $name . '";';
            if (isset($contentParams['fileName'])) {
                $fileName = str_replace($disallowedChars, '_', $contentParams['fileName']);
                $contentDisposition .= ' filename="' . $fileName . '"';
            }
            $headers[] = $contentDisposition;
            if (isset($contentParams['contentType'])) {
                $headers[] = 'Content-Type: ' . $contentParams['contentType'];
            } elseif (isset($contentParams['mimeType'])) {
                $headers[] = 'Content-Type: ' . $contentParams['mimeType'];
            }
            $contentParts[] = implode("\r\n", [implode("\r\n", $headers), '', $contentParams['content']]);
        }

        // generate safe boundary :
        do {
            $boundary = '---------------------' . md5(mt_rand() . microtime());
        } while (preg_grep("/{$boundary}/", $contentParts));

        // add boundary for each part :
        array_walk($contentParts, function (&$part) use ($boundary) {
            $part = "--{$boundary}\r\n{$part}";
        });

        // add final boundary :
        $contentParts[] = "--{$boundary}--";
        $contentParts[] = '';

        $this->getHeaders()->set('content-type', "multipart/form-data; boundary={$boundary}");
        $this->setContent(implode("\r\n", $contentParts));
    }

    /**
     * Composes given data as form inputs submitted values, taking in account nested arrays.
     * Converts `['form' => ['name' => 'value']]` to `['form[name]' => 'value']`.
     * @param array $data
     * @param string $baseKey
     * @return array
     */
    private function composeFormInputs(array $data, $baseKey = '')
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (!empty($baseKey)) {
                $key = $baseKey . '[' . $key . ']';
            }
            if (is_array($value)) {
                $result = array_merge($result, $this->composeFormInputs($value, $key));
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function composeHeaderLines()
    {
        $headers = parent::composeHeaderLines();
        if ($this->hasCookies()) {
            $headers[] = $this->composeCookieHeader();
        }
        return $headers;
    }

    /**
     * Sends this request.
     * @return Response response instance.
     */
    public function send()
    {
        return $this->client->send($this);
    }

    /**
     * @inheritdoc
     */
    public function toString()
    {
        $result = strtoupper($this->getMethod()) . ' ' . $this->getUrl();

        $parentResult = parent::toString();
        if ($parentResult !== '') {
            $result .= "\n" . $parentResult;
        }

        return $result;
    }

    /**
     * @return string cookie header value.
     */
    private function composeCookieHeader()
    {
        $parts = [];
        foreach ($this->getCookies() as $cookie) {
            $parts[] = $cookie->name . '=' . urlencode($cookie->value);
        }
        return 'Cookie: ' . implode(';', $parts);
    }

    /**
     * @return FormatterInterface message formatter instance.
     */
    private function getFormatter()
    {
        return $this->client->getFormatter($this->getFormat());
    }
}