<?php

namespace EasyRequest;

use EasyRequest\Cookie\CookieJar;
use EasyRequest\Cookie\CookieJarInterface;
use EasyRequest\Cookie\FileCookieJar;
use EasyRequest\Handler\HandlerInterface;
use EasyRequest\Psr7\AppendStream;
use EasyRequest\Psr7\Request;
use EasyRequest\Psr7\Response;
use EasyRequest\Psr7\Uri;
use Exception;
use InvalidArgumentException;

/**
 * Light weight Http Client implements PSR-7.
 * Using socket and curl for sending request.
 *
 * @author Phan Thanh Cong <ptcong90@gmail.com>
 */
class Client
{
    const HANDLER_CURL = 'EasyRequest\Handler\Curl';
    const HANDLER_SOCKET = 'EasyRequest\Handler\Socket';

    /**
     * Supported proxy types (the values are same as CURLPROXY_*).
     */
    const PROXY_HTTP = 0;
    const PROXY_SOCKS4 = 4;
    const PROXY_SOCKS5 = 5;
    const PROXY_SOCKS4A = 6;

    /**
     * Array of default options.
     *
     * @var array
     */
    public static $defaultOptions = [
        'protocol_version' => '1.1',
        'method'           => 'GET',
        'header'           => [],
        'body'             => '',
        'body_as_json'     => false,
        'query'            => [],
        'form_param'       => [],
        'multipart'        => [],
        'default_header'   => true,    // add general headers as browser does
        'upload'           => false,   // wanna upload large files ?
        'cookie_jar'       => null,    // file path or CookieJarInterface

        'bindto'           => null,    // bind to an interface (IPV4 or IPV6), same as CURLOPT_INTERFACE
        'proxy'            => null,
        'proxy_type'       => null,
        'proxy_userpwd'    => null,
        'auth'             => null,

        'timeout'          => 10,      // connect timeout
        'nobody'           => false,   // get header only, you also can use HEAD method
        'follow_redirects' => false,   // boolean or integer (number of max follow redirect)
        'handler'          => null,    // handler for sending request
        'curl'             => [], // use more curl options ?
    ];

    /**
     * Array of options.
     *
     * @var array
     */
    protected $options = [];

    protected $requests = [];
    protected $responses = [];
    protected $error;
    protected $debug = [];

    /**
     * Create new request.
     *
     * @param string $url
     * @param string $method
     *
     * @return self
     */
    public static function request($url, $method = 'GET', array $options = [])
    {
        return new self($url, ['method' => $method] + $options);
    }

    /**
     * Create new instance.
     *
     * @param string $url
     * @param array  $options
     */
    private function __construct($url, array $options = [])
    {
        $this->requests[0] = new Request($url);
        $this->options = self::$defaultOptions;

        $this->withOption($options);
    }

    /**
     * Returns current request.
     *
     * @return \Psr\Http\Message\RequestInterface
     */
    public function getRequest()
    {
        return $this->requests[0];
    }

    /**
     * Returns response.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getResponse()
    {
        return $this->responses ? end($this->responses) : new Response;
    }

    /**
     * Returns current request uri.
     *
     * @return \Psr\Http\Message\UriInterface
     */
    public function getCurrentUri()
    {
        return end($this->requests)->getUri();
    }

    /**
     * Returns array of all requests (includes redirect responses).
     *
     * @return \Psr\Http\Message\RequestInterface[]
     */
    public function getRequests()
    {
        return $this->requests;
    }

    /**
     * Returns array of all responses (includes redirect responses).
     *
     * @return \Psr\Http\Message\ResponseInterface[]
     */
    public function getResponses()
    {
        return $this->responses;
    }

    /**
     * Returns error message.
     *
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Returns debug information.
     *
     * @return array
     */
    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * Process sending request and populate response object.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function send()
    {
        $request = $this->prepareRequest();
        $handler = $this->getHandler();
        $cookieJar = $this->options['cookie_jar'];
        $redirectedCount = 0;

        $this->debug['start'] = microtime(true);

        try {
            while (true) {
                $domain = $request->getHeaderLine('Host');
                $path = $request->getUri()->getPath();

                $jar = $cookieJar->getFor($domain, $path);
                if ($jar->count()) {
                    $request = $request->withHeader('Cookie', (string) $jar);
                }

                $this->requests[$redirectedCount] = $request;

                $this->responses[] = $response = $handler->send($request, $this->options);
                $cookieJar->fromResponse($response, $domain, $path);

                if ($hasRedirect = $response->hasHeader('Location')) {
                    // prepare next request
                    $request = $this->prepareFollowRedirect($request, $response);
                    $redirectedCount++;
                }

                if (! $hasRedirect
                    || ! $this->options['follow_redirects']
                    || $this->options['follow_redirects'] < $redirectedCount
                ) {
                    break;
                }
            }
        } catch (Exception $e) {
            $this->error = $e->getMessage();
        }
        $this->debug['end'] = microtime(true);
        $this->debug['time'] = $this->debug['end'] - $this->debug['start'];

        return $this->getResponse();
    }

    protected function prepareFollowRedirect($request, $response)
    {
        $uri = $request->getUri();
        $location = $response->getHeaderLine('Location');

        if (strpos($location, '://') !== false) {
            $uri = new Uri($location);
        } elseif (strpos($location, '/') === 0) {
            $uri = $uri->withPath($location);
        } else {
            $uri = $uri->withPath(preg_replace('#[^/]*$#', '', $uri->getPath()).$location);
        }

        $headers = $this->options['default_header'] ? $this->getGeneralHeaders() : [];

        if ($userAgent = $request->getHeaderLine('User-Agent')) {
            $headers['User-Agent'] = $userAgent;
        }

        return new Request($uri, 'GET', $headers, $request->getProtocolVersion());
    }

    protected function prepareRequest()
    {
        $request = $this->requests[0];
        $uri = $request->getUri();
        $uri = $uri->withQuery(trim($uri->getQuery().'&'.http_build_query_rfc3986($this->options['query']), '&'));

        $request = $request
            ->withProtocolVersion($this->options['protocol_version'])
            ->withMethod($this->options['method'])
            ->withUri($uri);

        if ($this->options['auth']) {
            $request = $request->withHeader('Authorization', 'Basic '.base64_encode($this->options['auth']));
        }

        if ($this->options['default_header']) {
            $this->withGeneralHeaders();
        }

        foreach ($this->options['header'] as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        if ($this->options['multipart']) {
            $this->options['boundary'] = $boundary = uniqid();
            $this->options['upload'] = true;

            if (! $request->hasHeader('Content-Type')) {
                $request = $request->withHeader('Content-Type', "multipart/form-data; boundary={$boundary}");
            }

            // convert form_param to multipart
            $formParams = http_build_query_rfc3986($this->options['form_param']);
            if (preg_match_all('#([^=&]+)=([^&]*)#i', $formParams, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    list(, $name, $contents) = array_map('urldecode', $match);
                    $this->withMultipart($name, $contents);
                }
            }

            $stream = new AppendStream;
            foreach ($this->options['multipart'] as $field) {
                $stream->add(stream_for($this->getMultipartHeaders($boundary, $field)));
                $stream->add(stream_for($field['contents']));
                $stream->add(stream_for("\r\n"));
            }
            $stream->add(stream_for("--{$boundary}--\r\n"));

            $request = $request->withBody($stream);
        } elseif ($this->options['form_param'] || $this->options['body']) {
            $this->options['body'] = $this->options['form_param']
                ? http_build_query_rfc3986($this->options['form_param'])
                : $this->options['body'];

            if (! $request->hasHeader('Content-Type')) {
                $request = $request->withHeader('Content-Type',
                    $this->options['body_as_json'] ? 'application/json' : 'application/x-www-form-urlencoded'
                );
            }

            if (! $request->hasHeader('Content-Length')) {
                $request = $request->withHeader('Content-Length', strlen($this->options['body']));
            }

            $request = $request->withBody(stream_for($this->options['body']));
        }

        if ($this->options['body'] || $this->options['upload']) {
            $request = $request->withHeader('Expect', '');
        }

        if ($this->options['upload']) {
            $request = $request
                ->withoutHeader('Content-Length')
                ->withHeader('Transfer-Encoding', 'chunked');
        }

        if ($this->options['proxy'] && $this->options['proxy_type'] === null) {
            $this->options['proxy_type'] = self::PROXY_HTTP;
        }

        // create new CookieJar if not set.
        $cookieJar = $this->options['cookie_jar'] ? $this->options['cookie_jar'] : new CookieJar;

        if ($request->hasHeader('Cookie')) {
            $domain = $request->getHeaderLine('Host');
            $path = $request->getUri()->getPath();

            $cookieJar->fromString($request->getHeaderLine('Cookie'), $domain, $path);
        }
        $this->options['cookie_jar'] = $cookieJar;

        return $this->requests[0] = $request;
    }

    protected function getGeneralHeaders()
    {
        return [
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Encoding' => 'gzip, deflate',
            'Accept-Language' => 'en-US,en;q=0.5',
            'User-Agent'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.11; rv:45.0) Gecko/20100101 Firefox/45.0'
        ];
    }

    /**
     * Add general headers to request.
     *
     * @return self
     */
    public function withGeneralHeaders()
    {
        foreach ($this->getGeneralHeaders() as $name => $value) {
            if (! array_key_exists($name, $this->options['header'])) {
                $this->options['header'][$name] = $value;
            }
        }

        return $this;
    }

    protected function getHandler()
    {
        static $exists;
        if (! $exists) {
            $exists = [
                self::HANDLER_CURL   => function_exists('curl_init'),
                self::HANDLER_SOCKET => function_exists('stream_socket_client')
            ];
        }
        if ($this->options['handler'] !== null) {
            $handler = $this->options['handler'];
            if (is_string($handler) && empty($exists[$handler])) {
                throw new Exception(sprintf('Handler "%s" is not available.'));
            }

            if (is_string($handler)) {
                $handler = new $handler;
            }

            if (is_object($handler) && ! $handler instanceof HandlerInterface) {
                throw new Exception(sprintf('Handler must be instance of HandlerInterface'));
            }

            return $handler;
        }

        foreach ($exists as $handler => &$value) {
            if ($value) {
                if (! is_object($value)) {
                    $value = new $handler;
                }

                return new $value;
            }
        }

        throw new Exception('There is no handler available');
    }

    protected function getMultipartHeaders($boundary, $field)
    {
        $field['headers']['Content-Disposition'] = sprintf('form-data; name="%s"', $field['name'])
            .($field['filename'] ? sprintf('; filename="%s"', $field['filename']) : '');

        $header = '';
        foreach ($field['headers'] as $name => $value) {
            $header .= sprintf("%s: %s\r\n", $name, $value);
        }

        return "--{$boundary}\r\n{$header}\r\n";
    }

    /**
     * Set options, this method will override all existing values.
     *
     * @param int|array $key
     * @param mixed     $value
     *
     * @return self
     */
    public function withOption($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->withOption($k, $v);
            }
        } else {
            switch ($key) {
                case 'cookie_jar':
                    if (is_string($value) && file_exists($value)) {
                        $value = new FileCookieJar($value);
                    }
                    if (! $value instanceof CookieJarInterface) {
                        throw new InvalidArgumentException('Value of "cookie_jar" option '
                                                           .'must be instance of CookieJarInterface');
                    }
                    $this->options['cookie_jar'] = $value;

                    break;

                case 'header':
                    $this->options['header'] = [];
                    if (! is_array($value)) {
                        throw new InvalidArgumentException('Value of "header" option must be array');
                    }
                    foreach ($value as $key => $values) {
                        if (is_int($key) && is_string($values)) {
                            list($k, $v) = explode(':', $values, 2);
                            if (! isset($this->options['header'][$k])) {
                                $this->options['header'][$k] = [];
                            }
                            $this->options['header'][$k][] = $v;
                        } else {
                            $this->options['header'][$key] = $values;
                        }
                    }

                    $this->options['header'] = $value;
                    break;

                case 'query':
                    if (! is_string($value) && ! is_array($value)) {
                        throw new InvalidArgumentException('Value of "form_param" option must be array or string');
                    }
                    $this->withQuery($value, null, false, false);
                    break;

                case 'form_param':
                    if (! is_string($value) && ! is_array($value)) {
                        throw new InvalidArgumentException('Value of "form_param" option must be array or string');
                    }
                    $this->withFormParam($value, null, false, false);
                    break;

                case 'multipart':
                    $this->options['multipart'] = [];

                    foreach ($value as $part) {
                        $this->withMultipart(
                            $part['name'], $part['contents'],
                            isset($part['filename']) ? $part['filename'] : null,
                            isset($part['headers']) ? $part['headers'] : []
                        );
                    }
                    break;

                case 'follow_redirects':
                    if (is_bool($value) || is_numeric($value) && ($value = intval($value)) >= 0) {
                        $this->options['follow_redirects'] = $value;
                    } else {
                        throw new InvalidArgumentException('Value of "follow_redirects" option'
                                                           .' must be a digit number or "true".');
                    }
                    break;

                case 'timeout':
                    if (! is_numeric($value) || $value <= 0) {
                        throw new InvalidArgumentException('Value of "timeout" option must be a digit number.');
                    }
                    $this->options['timeout'] = (int) $value;
                    break;

                default:
                    $this->options[$key] = $value;
                    break;
            }
        }

        return $this;
    }

    /**
     * Add query string to request.
     *
     * @param string|array $name      This value may be:
     *                                - a query string
     *                                - array of query string
     * @param null|string  $value
     * @param bool         $append
     * @param bool         $recursive
     *
     * @return self
     */
    public function withQuery($name, $value = null, $append = true, $recursive = false)
    {
        add_param_to_array($this->options['query'], $name, $value, $append, $recursive);

        return $this;
    }

    /**
     * Add form param to request.
     *
     * @param string|array $name      This value may be:
     *                                - query string
     *                                - array of query string
     * @param null|string  $value
     * @param bool         $append
     * @param bool         $recursive
     *
     * @return self
     */
    public function withFormParam($name, $value = null, $append = true, $recursive = false)
    {
        add_param_to_array($this->options['form_param'], $name, $value, $append, $recursive);

        return $this;
    }

    /**
     * Add a form file to request.
     *
     * @param string      $name
     * @param string      $path
     * @param null|string $filename
     * @param array       $headers
     *
     * @return self
     */
    public function withFormFile($name, $path, $filename = null, array $headers = [])
    {
        $headers += ['Content-Transfer-Encoding' => 'binary'];

        if (empty($headers['Content-Type']) && $mimeType = mimetype_from_filename($path)) {
            $headers['Content-Type'] = $mimeType;
        }

        return $this->withMultipart($name, fopen($path, 'r'), $filename ? $filename : basename($path), $headers);
    }

    /**
     * Add multipart data to reuqest.
     *
     * @param name            $name
     * @param string|resource $contents
     * @param null|string     $filename
     * @param array           $headers
     *
     * @return self
     */
    public function withMultipart($name, $contents, $filename = null, array $headers = [])
    {
        $this->options['multipart'][$name] = [
            'name'     => $name,
            'contents' => $contents,
            'filename' => $filename,
            'headers'  => $headers
        ];

        return $this;
    }

    /**
     * Add request body.
     *
     * @param string|resource $body
     *
     * @return self
     */
    public function withBody($body)
    {
        return $this->withOption([
            'body_as_json' => false,
            'body'         => $body
        ]);
    }

    /**
     * Used to easily upload JSON encoded data as the body
     * of a request. A Content-Type header of application/json will be added
     * if no Content-Type header is already present on the message.
     *
     * @param array|string $json Json string or array
     *
     * @throws \InvalidArgumentException if value is invalid
     *
     * @return self
     *
     */
    public function withJson($json)
    {
        if (is_string($json)) {
            $json = json_decode($json, true);
            if ($json === null) {
                throw new InvalidArgumentException('Json value must be an array or json string.');
            }
        }

        return $this->withOption([
            'body_as_json' => true,
            'body'         => json_encode($json)
        ]);
    }

    /**
     * Gets current options for given key.
     *
     * @param null|int $key
     *
     * @return mixed
     */
    public function getOption($key = null)
    {
        $options = $this->options + self::$defaultOptions;

        if ($key === null) {
            return $options;
        }

        if (! array_key_exists($key, $options)) {
            throw new InvalidArgumentException(sprintf('Options "%s" is invalid', $key));
        }

        return $options[$key];
    }

    /**
     * Sets proxy option.
     *
     * @param null|string $proxy   ip:port
     * @param null|string $userPwd user:pass
     * @param int
     *
     * @throws \InvalidArgumentException if proxy is invalid.
     *
     * @return self
     */
    public function withProxy($proxy, $userPwd = null, $type = self::PROXY_HTTP)
    {
        if ($proxy === null) {
            return $this->withOption([
                'proxy'         => null,
                'proxy_userpwd' => null,
                'proxy_type'    => null,
            ]);
        }

        if ($userPwd !== null && ! preg_match('#[\w-_]+(?::[\w-_]+)?#', $userPwd)) {
            throw new InvalidArgumentException('Proxy user pass must be one of: '
                                               .'string with format "user:pass" or "null".');
        }

        return $this->withOption([
            'proxy'         => $proxy,
            'proxy_userpwd' => $userPwd,
            'proxy_type'    => $type,
        ]);
    }

    /**
     * Sets HTTP proxy.
     *
     * @param string      $proxy
     * @param null|string $userPwd
     *
     * @throws \InvalidArgumentException if proxy is invalid.
     *
     * @return self
     */
    public function withHttpProxy($proxy, $userPwd = null)
    {
        return $this->withProxy($proxy, $userPwd, self::PROXY_HTTP);
    }

    /**
     * Sets Socks5 proxy.
     *
     * @param string      $proxy
     * @param null|string $userPwd
     *
     * @throws \InvalidArgumentException if proxy is invalid.
     *
     * @return self
     */
    public function withSocks5Proxy($proxy, $userPwd = null)
    {
        return $this->withProxy($proxy, $userPwd, self::PROXY_SOCKS5);
    }

    /**
     * Sets Socks4 proxy.
     *
     * @param string $proxy
     *
     * @return self
     */
    public function withSocks4Proxy($proxy)
    {
        return $this->withProxy($proxy, null, self::PROXY_SOCKS4);
    }

    /**
     * Dynamic method to create and send request quickly.
     *
     * @param string $method
     * @param array  $args
     *
     * @throws \Exception if method given is not defined.
     *
     * @return self
     *
     */
    public static function __callStatic($method, $args)
    {
        static $methods = [
            'OPTIONS' => 1,
            'GET'     => 1,
            'HEAD'    => 1,
            'POST'    => 1,
            'PUT'     => 1,
            'DELETE'  => 1,
            'TRACE'   => 1,
            'CONNECT' => 1
        ];

        if (! empty($methods[strtoupper($method)])) {
            return self::request($args[0], $method, isset($args[1]) ? $args[1] : [])->send();
        }

        throw new Exception(sprintf('Method "%s" is not defined.', $method));
    }

    /**
     * Dynamic method to custom request.
     *
     * @param string $method
     * @param array  $args
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (method_exists($this->requests[0], $method)) {
            if (stripos($method, 'with') === 0) {
                $this->requests[0] = call_user_func_array([$this->requests[0], $method], $args);

                return $this;
            }
        }
        throw new Exception(sprintf('Call to undefined method %s::%s', __CLASS__, $method));
    }
}
