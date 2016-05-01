<?php
namespace Psr\Http\Message {
interface MessageInterface
{
    public function getProtocolVersion();
    public function withProtocolVersion($version);
    public function getHeaders();
    public function hasHeader($name);
    public function getHeader($name);
    public function getHeaderLine($name);
    public function withHeader($name, $value);
    public function withAddedHeader($name, $value);
    public function withoutHeader($name);
    public function getBody();
    public function withBody(StreamInterface $body);
}
}

namespace Psr\Http\Message {
interface RequestInterface extends MessageInterface
{
    public function getRequestTarget();
    public function withRequestTarget($requestTarget);
    public function getMethod();
    public function withMethod($method);
    public function getUri();
    public function withUri(UriInterface $uri, $preserveHost = false);
}
}

namespace Psr\Http\Message {
interface ResponseInterface extends MessageInterface
{
    public function getStatusCode();
    public function withStatus($code, $reasonPhrase = '');
    public function getReasonPhrase();
}
}

namespace Psr\Http\Message {
interface ServerRequestInterface extends RequestInterface
{
    public function getServerParams();
    public function getCookieParams();
    public function withCookieParams(array $cookies);
    public function getQueryParams();
    public function withQueryParams(array $query);
    public function getUploadedFiles();
    public function withUploadedFiles(array $uploadedFiles);
    public function getParsedBody();
    public function withParsedBody($data);
    public function getAttributes();
    public function getAttribute($name, $default = null);
    public function withAttribute($name, $value);
    public function withoutAttribute($name);
}
}

namespace Psr\Http\Message {
interface StreamInterface
{
    public function __toString();
    public function close();
    public function detach();
    public function getSize();
    public function tell();
    public function eof();
    public function isSeekable();
    public function seek($offset, $whence = SEEK_SET);
    public function rewind();
    public function isWritable();
    public function write($string);
    public function isReadable();
    public function read($length);
    public function getContents();
    public function getMetadata($key = null);
}
}

namespace Psr\Http\Message {
interface UploadedFileInterface
{
    public function getStream();
    public function moveTo($targetPath);
    public function getSize();
    public function getError();
    public function getClientFilename();
    public function getClientMediaType();
}
}

namespace Psr\Http\Message {
interface UriInterface
{
    public function getScheme();
    public function getAuthority();
    public function getUserInfo();
    public function getHost();
    public function getPort();
    public function getPath();
    public function getQuery();
    public function getFragment();
    public function withScheme($scheme);
    public function withUserInfo($user, $password = null);
    public function withHost($host);
    public function withPort($port);
    public function withPath($path);
    public function withQuery($query);
    public function withFragment($fragment);
    public function __toString();
}
}

namespace EasyRequest {
use EasyRequest\Cookie\CookieJar;
use EasyRequest\Cookie\CookieJarInterface;
use EasyRequest\Cookie\FileCookieJar;
use EasyRequest\Handler\HandlerInterface;
use EasyRequest\Psr7\AppendStream;
use EasyRequest\Psr7\Request;
use EasyRequest\Psr7\Uri;
use Exception;
use InvalidArgumentException;
class Client
{
    const HANDLER_CURL = 'EasyRequest\\Handler\\Curl';
    const HANDLER_SOCKET = 'EasyRequest\\Handler\\Socket';
    const PROXY_HTTP = 0;
    const PROXY_SOCKS4 = 4;
    const PROXY_SOCKS5 = 5;
    const PROXY_SOCKS4A = 6;
    public static $defaultOptions = array('protocol_version' => '1.1', 'method' => 'GET', 'header' => array(), 'body' => '', 'body_as_json' => false, 'query' => array(), 'form_param' => array(), 'multipart' => array(), 'default_header' => true, 'upload' => false, 'cookie_jar' => null, 'bindto' => null, 'proxy' => null, 'proxy_type' => null, 'proxy_userpwd' => null, 'auth' => null, 'timeout' => 10, 'nobody' => false, 'follow_redirects' => false, 'handler' => null, 'curl' => array());
    protected $options = array();
    protected $requests = array();
    protected $responses = array();
    protected $error;
    protected $debug = array();
    public static function request($url, $method = 'GET', array $options = array())
    {
        return new self($url, array('method' => $method) + $options);
    }
    private function __construct($url, array $options = array())
    {
        $this->requests[0] = new Request($url);
        $this->options = self::$defaultOptions;
        $this->withOption($options);
    }
    public function getRequest()
    {
        return $this->requests[0];
    }
    public function getResponse()
    {
        return $this->responses ? end($this->responses) : null;
    }
    public function getCurrentUri()
    {
        return end($this->requests)->getUri();
    }
    public function getRequests()
    {
        return $this->requests;
    }
    public function getResponses()
    {
        return $this->responses;
    }
    public function getError()
    {
        return $this->error;
    }
    public function getDebug()
    {
        return $this->debug;
    }
    public function send()
    {
        $request = $this->prepareRequest();
        $handler = $this->getHandler();
        $cookieJar = $this->options['cookie_jar'];
        $redirectedCount = 0;
        $this->debug['start'] = microtime(true);
        try {
            while (true) {
                if ($redirectedCount > 0) {
                    $this->requests[] = $request;
                }
                $domain = $request->getHeaderLine('Host');
                $path = $request->getUri()->getPath();
                $jar = $cookieJar->getFor($domain, $path);
                if ($jar->count()) {
                    $request = $request->withHeader('Cookie', (string) $jar);
                }
                $this->responses[] = $response = $handler->send($request, $this->options);
                $cookieJar->fromResponse($response, $domain, $path);
                if ($hasRedirect = $response->hasHeader('Location')) {
                    $request = $this->prepareFollowRedirect($request, $response);
                    $redirectedCount++;
                }
                if (!$hasRedirect || !$this->options['follow_redirects'] || $this->options['follow_redirects'] < $redirectedCount) {
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
            $uri = $uri->withPath(preg_replace('#[^/]*$#', '', $uri->getPath()) . $location);
        }
        $headers = $this->options['default_header'] ? $this->getGeneralHeaders() : array();
        if ($userAgent = $request->getHeaderLine('User-Agent')) {
            $headers['User-Agent'] = $userAgent;
        }
        return new Request($uri, 'GET', $headers, $request->getProtocolVersion());
    }
    protected function prepareRequest()
    {
        $request = $this->requests[0];
        $uri = $request->getUri();
        $uri = $uri->withQuery(trim($uri->getQuery() . '&' . http_build_query_rfc3986($this->options['query']), '&'));
        $request = $request->withProtocolVersion($this->options['protocol_version'])->withMethod($this->options['method'])->withUri($uri);
        if ($this->options['auth']) {
            $request = $request->withHeader('Authorization', 'Basic ' . base64_encode($this->options['auth']));
        }
        foreach ($this->options['header'] as $name => $values) {
            $request = $request->withHeader($name, $values);
        }
        if ($this->options['multipart']) {
            $this->options['boundary'] = $boundary = uniqid();
            $this->options['upload'] = true;
            if (!$request->hasHeader('Content-Type')) {
                $request = $request->withHeader('Content-Type', "multipart/form-data; boundary={$boundary}");
            }
            $formParams = http_build_query_rfc3986($this->options['form_param']);
            if (preg_match_all('#([^=&]+)=([^&]*)#i', $formParams, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    list(, $name, $contents) = array_map('urldecode', $match);
                    $this->withMultipart($name, $contents);
                }
            }
            $stream = new AppendStream();
            foreach ($this->options['multipart'] as $field) {
                $stream->add(stream_for($this->getMultipartHeaders($boundary, $field)));
                $stream->add(stream_for($field['contents']));
                $stream->add(stream_for("\r\n"));
            }
            $stream->add(stream_for("--{$boundary}--\r\n"));
            $request = $request->withBody($stream);
        } elseif ($this->options['form_param'] || $this->options['body']) {
            $this->options['body'] = $this->options['form_param'] ? http_build_query_rfc3986($this->options['form_param']) : $this->options['body'];
            if (!$request->hasHeader('Content-Type')) {
                $request = $request->withHeader('Content-Type', $this->options['body_as_json'] ? 'application/json' : 'application/x-www-form-urlencoded');
            }
            if (!$request->hasHeader('Content-Length')) {
                $request = $request->withHeader('Content-Length', strlen($this->options['body']));
            }
            $request = $request->withBody(stream_for($this->options['body']));
        }
        if ($this->options['body'] || $this->options['upload']) {
            $request = $request->withHeader('Expect', '');
        }
        if ($this->options['upload']) {
            $request = $request->withoutHeader('Content-Length')->withHeader('Transfer-Encoding', 'chunked');
        }
        if ($this->options['proxy'] && $this->options['proxy_type'] === null) {
            $this->options['proxy_type'] = self::PROXY_HTTP;
        }
        if ($this->options['default_header']) {
            $this->withGeneralHeaders();
        }
        $cookieJar = $this->options['cookie_jar'] ? $this->options['cookie_jar'] : new CookieJar();
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
        return array('Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Encoding' => 'gzip, deflate', 'Accept-Language' => 'en-US,en;q=0.5', 'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.11; rv:45.0) Gecko/20100101 Firefox/45.0');
    }
    public function withGeneralHeaders()
    {
        foreach ($this->getGeneralHeaders() as $name => $value) {
            if (!array_key_exists($name, $this->options['header'])) {
                $this->options['header'][$name] = $value;
            }
        }
        return $this;
    }
    protected function getHandler()
    {
        static $exists;
        if (!$exists) {
            $exists = array(self::HANDLER_CURL => function_exists('curl_init'), self::HANDLER_SOCKET => function_exists('stream_socket_client'));
        }
        if ($this->options['handler'] !== null) {
            $handler = $this->options['handler'];
            if (is_string($handler) && empty($exists[$handler])) {
                throw new Exception(sprintf('Handler "%s" is not available.'));
            }
            if (is_string($handler)) {
                $handler = new $handler();
            }
            if (is_object($handler) && !$handler instanceof HandlerInterface) {
                throw new Exception(sprintf('Handler must be instance of HandlerInterface'));
            }
            return $handler;
        }
        foreach ($exists as $handler => &$value) {
            if ($value) {
                if (!is_object($value)) {
                    $value = new $handler();
                }
                return new $value();
            }
        }
        throw new Exception('There is no handler available');
    }
    protected function getMultipartHeaders($boundary, $field)
    {
        $field['headers']['Content-Disposition'] = sprintf('form-data; name="%s"', $field['name']) . ($field['filename'] ? sprintf('; filename="%s"', $field['filename']) : '');
        $header = '';
        foreach ($field['headers'] as $name => $value) {
            $header .= sprintf("%s: %s\r\n", $name, $value);
        }
        return "--{$boundary}\r\n{$header}\r\n";
    }
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
                    if (!$value instanceof CookieJarInterface) {
                        throw new InvalidArgumentException('Value of "cookie_jar" option ' . 'must be instance of CookieJarInterface');
                    }
                    $this->options['cookie_jar'] = $value;
                    break;
                case 'header':
                    $this->options['header'] = array();
                    if (!is_array($value)) {
                        throw new InvalidArgumentException('Value of "header" option must be array');
                    }
                    foreach ($value as $key => $values) {
                        if (is_int($key) && is_string($values)) {
                            list($k, $v) = explode(':', $values, 2);
                            if (!isset($this->options['header'][$k])) {
                                $this->options['header'][$k] = array();
                            }
                            $this->options['header'][$k][] = $v;
                        } else {
                            $this->options['header'][$key] = $values;
                        }
                    }
                    $this->options['header'] = $value;
                    break;
                case 'query':
                    if (!is_string($value) && !is_array($value)) {
                        throw new InvalidArgumentException('Value of "form_param" option must be array or string');
                    }
                    $this->withQuery($value, null, false, false);
                    break;
                case 'form_param':
                    if (!is_string($value) && !is_array($value)) {
                        throw new InvalidArgumentException('Value of "form_param" option must be array or string');
                    }
                    $this->withFormParam($value, null, false, false);
                    break;
                case 'multipart':
                    $this->options['multipart'] = array();
                    foreach ($value as $part) {
                        $this->withMultipart($part['name'], $part['contents'], isset($part['filename']) ? $part['filename'] : null, isset($part['headers']) ? $part['headers'] : array());
                    }
                    break;
                case 'follow_redirects':
                    if (is_bool($value) || is_numeric($value) && ($value = intval($value)) >= 0) {
                        $this->options['follow_redirects'] = $value;
                    } else {
                        throw new InvalidArgumentException('Value of "follow_redirects" option' . ' must be a digit number or "true".');
                    }
                    break;
                case 'timeout':
                    if (!is_numeric($value) || $value <= 0) {
                        throw new InvalidArgumentException('Value of "timeout" option must be a digit number.');
                    }
                    $this->options['timeout'] = (int) $value;
                    break;
                case 'auth':
                    if ($value !== null && !preg_match('#^[\\w-_]+(?::[\\w-_]+)?$#', $value)) {
                        throw new InvalidArgumentException('Value of "auth" option must be' . ' one of: string with format "user:pass" or "null".');
                    }
                    $this->options['auth'] = $value;
                    break;
                default:
                    $this->options[$key] = $value;
                    break;
            }
        }
        return $this;
    }
    public function withQuery($name, $value = null, $append = true, $recursive = false)
    {
        add_param_to_array($this->options['query'], $name, $value, $append, $recursive);
        return $this;
    }
    public function withFormParam($name, $value = null, $append = true, $recursive = false)
    {
        add_param_to_array($this->options['form_param'], $name, $value, $append, $recursive);
        return $this;
    }
    public function withFormFile($name, $path, $filename = null, array $headers = array())
    {
        $headers += array('Content-Transfer-Encoding' => 'binary');
        if (empty($headers['Content-Type']) && ($mimeType = mimetype_from_filename($path))) {
            $headers['Content-Type'] = $mimeType;
        }
        return $this->withMultipart($name, fopen($path, 'r'), $filename ? $filename : basename($path), $headers);
    }
    public function withMultipart($name, $contents, $filename = null, array $headers = array())
    {
        $this->options['multipart'][$name] = array('name' => $name, 'contents' => $contents, 'filename' => $filename, 'headers' => $headers);
        return $this;
    }
    public function withBody($body)
    {
        return $this->withOption(array('body_as_json' => false, 'body' => $body));
    }
    public function withJson($json)
    {
        if (is_string($json)) {
            $json = json_decode($json, true);
            if ($json === null) {
                throw new InvalidArgumentException('Json value must be an array or json string.');
            }
        }
        return $this->withOption(array('body_as_json' => true, 'body' => json_encode($json)));
    }
    public function getOption($key = null)
    {
        $options = $this->options + self::$defaultOptions;
        if ($key === null) {
            return $options;
        }
        if (!array_key_exists($key, $options)) {
            throw new InvalidArgumentException(sprintf('Options "%s" is invalid', $key));
        }
        return $options[$key];
    }
    public function withProxy($proxy, $userPwd = null, $type = self::PROXY_HTTP)
    {
        if ($proxy === null) {
            return $this->withOption(array('proxy' => null, 'proxy_userpwd' => null, 'proxy_type' => null));
        }
        if ($userPwd !== null && !preg_match('#[\\w-_]+(?::[\\w-_]+)?#', $userPwd)) {
            throw new InvalidArgumentException('Proxy user pass must be one of: ' . 'string with format "user:pass" or "null".');
        }
        return $this->withOption(array('proxy' => $proxy, 'proxy_userpwd' => $userPwd, 'proxy_type' => $type));
    }
    public function withHttpProxy($proxy, $userPwd = null)
    {
        return $this->withProxy($proxy, $userPwd, self::PROXY_HTTP);
    }
    public function withSocks5Proxy($proxy, $userPwd = null)
    {
        return $this->withProxy($proxy, $userPwd, self::PROXY_SOCKS5);
    }
    public function withSocks4Proxy($proxy)
    {
        return $this->withProxy($proxy, null, self::PROXY_SOCKS4);
    }
    public static function __callStatic($method, $args)
    {
        static $methods = array('OPTIONS' => 1, 'GET' => 1, 'HEAD' => 1, 'POST' => 1, 'PUT' => 1, 'DELETE' => 1, 'TRACE' => 1, 'CONNECT' => 1);
        if (!empty($methods[strtoupper($method)])) {
            return self::request($args[0], $method, isset($args[1]) ? $args[1] : array())->send();
        }
        throw new Exception(sprintf('Method "%s" is not defined.', $method));
    }
    public function __call($method, $args)
    {
        if (method_exists($this->requests[0], $method)) {
            if (stripos($method, 'with') === 0) {
                $this->requests[0] = call_user_func_array(array($this->requests[0], $method), $args);
                return $this;
            }
        }
        throw new Exception(sprintf('Call to undefined method %s::%s', __CLASS__, $method));
    }
}
}

namespace EasyRequest\Cookie {
class Cookie
{
    public $name;
    public $value;
    public $path = '/';
    public $domain;
    public $expires;
    public $maxage;
    public $discard;
    public $secure;
    public $httponly;
    public static function parse($data)
    {
        $cookie = new self();
        if (is_string($data)) {
            preg_match_all('#(?:^|\\s*)([^=;]+)(?:=([^;]*)|;|$)?\\s*#', $data, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                list(, $key, $value) = $match + array('', '', true);
                if (is_null($cookie->name) && is_string($value)) {
                    $cookie->name = $key;
                    $cookie->value = urldecode($value);
                } else {
                    $key = str_replace('-', '', strtolower($key));
                    if (!property_exists($cookie, $key)) {
                        continue;
                    }
                    if ($key == 'expires' && is_string($value)) {
                        $value = strtotime($value);
                    }
                    if (in_array($key, array('discard', 'httponly', 'secure'))) {
                        $value = true;
                    }
                    $cookie->{$key} = $value;
                }
            }
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                if (property_exists($cookie, $key)) {
                    $cookie->{$key} = $value;
                }
            }
        }
        return $cookie;
    }
    public function __call($method, $args)
    {
        if (preg_match('#^(get|set)(.*?)$#i', strtolower($method), $match)) {
            $prop = $match[2];
            if (!property_exists($this, $prop)) {
                throw new \InvalidArgumentException(sprintf('Property %s does not exist.', $prop));
            }
            if ($match[1] == 'get') {
                return $this->{$prop};
            }
            $this->{$prop} = $args[0];
            return;
        }
        throw new \Exception(sprintf('Call to undefined method %s::%s', __CLASS__, $method));
    }
    public function __toString()
    {
        return $this->name ? sprintf('%s=%s;', $this->name, $this->value) : '';
    }
    public function toArray()
    {
        return json_decode(json_encode($this), true);
    }
}
}

namespace EasyRequest\Cookie {
use Psr\Http\Message\ResponseInterface;
class CookieJar implements CookieJarInterface
{
    protected $cookies = array();
    public function add(Cookie $cookie, $defaultDomain = null, $defaultPath = null)
    {
        $exists = false;
        foreach ($this->cookies as $key => $c) {
            if ($c->expires && $c->expires < time()) {
                unset($this->cookies[$key]);
                continue;
            }
            if ($c->name == $cookie->name && ltrim($c->domain, '.') == ltrim($cookie->domain, '.') && $c->path == $cookie->path) {
                if ($c->value != $cookie->value || $c->expires < $cookie->expires) {
                    unset($this->cookies[$key]);
                    continue;
                }
                $exists = true;
                break;
            }
        }
        if (!$exists && $cookie->name) {
            if (!$cookie->domain) {
                $cookie->setDomain($defaultDomain);
            }
            if (!$cookie->path) {
                $cookie->setPath($defaultPath);
            }
            $this->cookies[] = $cookie;
        }
        return $this;
    }
    public function addCookies(array $cookies, $defaultDomain = null, $defaultPath = null)
    {
        foreach ($cookies as $cookie) {
            if ($cookie instanceof Cookie) {
                $this->add($cookie, $defaultDomain, $defaultPath);
            }
        }
        return $this;
    }
    public function fromString($str, $defaultDomain = null, $defaultPath = null)
    {
        foreach (explode(';', $str) as $s) {
            $this->add(Cookie::parse($s), $defaultDomain, $defaultPath);
        }
        return $this;
    }
    public function fromResponse(ResponseInterface $response, $defaultDomain = null, $defaultPath = null)
    {
        $defaultDomain = $defaultDomain ? $defaultDomain : $response->getHeaderLine('Host');
        foreach ($response->getHeader('Set-Cookie') as $c) {
            $cookie = Cookie::parse($c);
            if (!$cookie->expires && $cookie->maxage) {
                $cookie->setExpires(time() + $cookie->maxage);
            }
            $this->add($cookie, $defaultDomain, $defaultPath);
        }
        return $this;
    }
    public function getFor($domain, $path = null)
    {
        $cookies = array();
        foreach ($this->cookies as $key => $cookie) {
            if ($cookie->expires && $cookie->expires < time()) {
                unset($this->cookies[$key]);
                continue;
            }
            if (($domain === null || $this->isCookieMatchesDomain($cookie, $domain)) && ($path === null || $this->isCookieMatchesPath($cookie, $path)) && (!$cookie->expires || $cookie->expires >= time())) {
                $cookies[] = $cookie;
            }
        }
        $jar = new self();
        $jar->cookies = $cookies;
        return $jar;
    }
    public function count()
    {
        return count($this->cookies);
    }
    public function toArray()
    {
        return $this->cookies;
    }
    public function __toString()
    {
        $out = '';
        foreach ($this->cookies as $cookie) {
            $out .= $cookie . ' ';
        }
        return trim($out);
    }
    protected function isCookieMatchesPath(Cookie $cookie, $path)
    {
        return empty($cookie->path) || strpos($path, $cookie->path) === 0;
    }
    protected function isCookieMatchesDomain(Cookie $cookie, $domain)
    {
        $cookieDomain = isset($cookie->domain) ? ltrim($cookie->domain, '.') : null;
        if (!$cookieDomain || !strcasecmp($domain, $cookieDomain)) {
            return true;
        }
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return false;
        }
        return (bool) preg_match('/\\.' . preg_quote($cookieDomain) . '$/i', $domain);
    }
}
}

namespace EasyRequest\Cookie {
use Psr\Http\Message\ResponseInterface;
interface CookieJarInterface
{
    public function add(Cookie $cookie, $defaultDomain = null, $defaultPath = null);
    public function addCookies(array $cookies, $defaultDomain = null, $defaultPath = null);
    public function fromString($str, $defaultDomain = null, $defaultPath = null);
    public function fromResponse(ResponseInterface $response, $defaultDomain = null, $defaultPath = null);
    public function getFor($domain, $path = null);
    public function count();
    public function toArray();
    public function __toString();
}
}

namespace EasyRequest\Cookie {
class FileCookieJar extends CookieJar
{
    protected $file;
    public function __construct($file)
    {
        $this->file = $file;
        $this->load();
    }
    private function load()
    {
        if (file_exists($this->file)) {
            $array = (array) json_decode(file_get_contents($this->file), true);
            foreach ($array as $c) {
                $cookie = Cookie::parse($c);
                $this->add($cookie);
            }
        }
    }
    public function __destruct()
    {
        $cookies = array();
        foreach ($this->cookies as $c) {
            $cookies[] = $c->toArray();
        }
        file_put_contents($this->file, json_encode($cookies));
    }
}
}

namespace EasyRequest\Cookie {
class SessionCookieJar extends CookieJar
{
    protected $key;
    public function __construct($key = __CLASS__)
    {
        $this->key = $key;
        $this->load();
    }
    private function load()
    {
        if (isset($_SESSION[$this->key]) && is_array($_SESSION[$this->key])) {
            $array = $_SESSION[$this->key];
            $this->addCookies((array) $array);
        }
    }
    public function __destruct()
    {
        $_SESSION[$this->key] = $this->cookies;
    }
}
}

namespace EasyRequest\Handler {
use EasyRequest\Client;
use EasyRequest\Psr7\Response;
use Exception;
use Psr\Http\Message\RequestInterface;
class Curl implements HandlerInterface
{
    public function send(RequestInterface $request, array $options = array())
    {
        $options += Client::$defaultOptions;
        $curlOptions = $options['curl'] + array(CURLOPT_URL => (string) $request->getUri(), CURLOPT_CUSTOMREQUEST => $request->getMethod(), CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => \EasyRequest\get_headers($request), CURLOPT_ENCODING => $request->getHeaderLine('Accept-Encoding'), CURLOPT_NOBODY => $options['nobody'], CURLOPT_CONNECTTIMEOUT => $options['timeout'], CURLOPT_HTTP_VERSION => $request->getProtocolVersion() == '1.0' ? CURL_HTTP_VERSION_1_0 : CURL_HTTP_VERSION_1_1);
        if ($options['upload']) {
            $body = $request->getBody();
            $curlOptions += array(CURLOPT_UPLOAD => true, CURLOPT_READFUNCTION => function ($ch, $fp, $length) use($body) {
                return $body->read($length);
            });
        } elseif ($options['body']) {
            $curlOptions[CURLOPT_POSTFIELDS] = (string) $request->getBody();
        }
        if ($options['proxy']) {
            $curlOptions += array(CURLOPT_PROXY => $options['proxy'], CURLOPT_PROXYTYPE => $options['proxy_type']);
            if ($options['proxy_userpwd']) {
                $curlOptions[CURLOPT_PROXYUSERPWD] = $options['proxy_userpwd'];
            }
        }
        if ($options['bindto']) {
            $curlOptions[CURLOPT_INTERFACE] = $options['bindto'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, $curlOptions);
        $result = curl_exec($ch);
        if ($result === false) {
            throw new Exception(sprintf('%d - %s', curl_errno($ch), curl_error($ch)));
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $header = substr($result, 0, $headerSize);
        $body = substr($result, $headerSize);
        return Response::parse($header, $body);
    }
}
}

namespace EasyRequest\Handler {
use Psr\Http\Message\RequestInterface;
interface HandlerInterface
{
    public function send(RequestInterface $request, array $options = array());
}
}

namespace EasyRequest\Handler {
use EasyRequest\Client;
use EasyRequest\Cookie\CookieJar;
use EasyRequest\Psr7\Response;
use Exception;
use Psr\Http\Message\RequestInterface;
class Socket implements HandlerInterface
{
    public function send(RequestInterface $request, array $options = array())
    {
        static $ports = array('https' => 443, 'http' => 80, '' => 80);
        $options += Client::$defaultOptions;
        $uri = $request->getUri();
        $targetHost = $host = $uri->getHost();
        $targetPort = $port = $uri->getPort() ? $uri->getPort() : $ports[$uri->getScheme()];
        $requestTarget = $request->getRequestTarget();
        if ($options['proxy']) {
            list($host, $port) = explode(':', $options['proxy']);
            $requestTarget = (string) $uri;
        }
        if (!($stream = $this->getConnection($host, $port, $errno, $errstr, $options, $uri))) {
            throw new Exception(sprintf("Couldn't connect to %s:%s. %s - %s", $host, $port, $errno, $errstr));
        }
        $httpsThroughSocks = $options['proxy'] && $options['proxy_type'] != Client::PROXY_HTTP && $uri->getScheme() == 'https';
        $headers = \EasyRequest\get_headers($request);
        $headers[] = 'Connection: close';
        if ($options['proxy']) {
            try {
                switch ($options['proxy_type']) {
                    case Client::PROXY_HTTP:
                        if ($options['proxy_userpwd']) {
                            $headers[] = 'Proxy-Authorization: Basic ' . base64_encode($options['proxy_userpwd']);
                        }
                        break;
                    case Client::PROXY_SOCKS5:
                        $this->handleSocks5($stream, $options, $targetHost, $targetPort);
                        break;
                    case Client::PROXY_SOCKS4:
                    case Client::PROXY_SOCKS4A:
                        if ($options['proxy_userpwd']) {
                            $this->handleSocks5($stream, $options, $targetHost, $targetPort);
                        } else {
                            $this->handleSocks4($stream, $options, $targetHost, $targetPort);
                        }
                        break;
                }
            } catch (\Exception $e) {
                fclose($stream);
                throw $e;
            }
            if ($httpsThroughSocks) {
                $this->toggleCrypto($stream, true);
            }
        }
        $header = sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $requestTarget, $request->getProtocolVersion());
        $header .= implode("\r\n", $headers) . "\r\n";
        $header .= "\r\n";
        $this->sendRequest($header, $request->getBody(), $options, function ($message) use($stream) {
            fwrite($stream, $message);
        });
        $request->getBody()->close();
        $httpsThroughSocks && ($level = error_reporting(~E_WARNING));
        $response = '';
        while (!feof($stream)) {
            $response .= fgets($stream, 1024);
        }
        fclose($stream);
        $httpsThroughSocks && error_reporting($level);
        list($header, $body) = explode("\r\n\r\n", $response, 2) + array('', '');
        $response = Response::parse($header, '');
        if ($response->getHeaderLine('Transfer-Encoding') == 'chunked') {
            $body = \EasyRequest\decode_chunked($body);
        }
        if ($response->getHeaderLine('Content-Encoding') == 'gzip') {
            $body = \EasyRequest\decode_gzip($body);
        }
        $response->getBody()->write($body);
        return $response;
    }
    private function handleSocks4($stream, $options, $targetHost, $targetPort)
    {
        $ip = ip2long($targetHost);
        $msg = pack('C*', 0x4, 0x1, 0x0, $targetPort);
        if ($ip !== false) {
            $msg .= pack('N', $ip);
        } else {
            $msg .= pack('C*', 0x0, 0x0, 0x0, 0x1, 0x0) . $targetHost;
        }
        $msg .= pack('C', 0x0);
        fwrite($stream, $msg);
        $reply = fread($stream, 1024);
        if (substr($reply, 1, 1) != pack('C', 90)) {
            throw new Exception('Socks: Request is not granted');
        }
    }
    private function handleSocks5($stream, $options, $targetHost, $targetPort)
    {
        $method = $options['proxy_userpwd'] ? 0x2 : 0x0;
        fwrite($stream, pack('C3', 0x5, 0x1, $method));
        $reply = fread($stream, 2);
        if ($reply != pack('C2', 0x5, $method)) {
            throw new Exception('Socks: Server does not accept the method');
        }
        if ($method == 0x2) {
            list($username, $password) = explode(':', $options['proxy_userpwd']);
            fwrite($stream, pack('C2', 0x1, strlen($username)) . $username . pack('C', strlen($password)) . $password);
            $reply = fread($stream, 2);
            if ($reply != pack('C2', 0x1, 0x1)) {
                throw new Exception('Socks: Authenication failure');
            }
        }
        fwrite($stream, pack('C*', 0x5, 0x1, 0x0, 0x3, strlen($targetHost)) . $targetHost . pack('n', $targetPort));
        $reply = fread($stream, 1024);
        if (substr($reply, 0, 2) != pack('C2', 0x5, 0x0)) {
            throw new Exception('Socks: Request is not granted');
        }
    }
    private function sendRequest($header, $body, $options, $sender)
    {
        $sender($header);
        if ($options['upload']) {
            while (true) {
                $chunk = $body->read(8192);
                $length = strlen($chunk);
                $sender(sprintf("%s\r\n%s\r\n", dechex($length), $chunk));
                if (!$length) {
                    break;
                }
            }
        } else {
            $sender($body . "\r\n\r\n");
        }
    }
    private function getConnection($host, $port, &$errno, &$errstr, $options, $uri)
    {
        $https = $uri->getScheme() == 'https';
        $transport = $https ? 'ssl' : 'tcp';
        $transport = $options['proxy'] ? 'tcp' : $transport;
        $remote = $transport . '://' . $host . ':' . $port;
        $context = stream_context_create();
        if ($options['bindto']) {
            $bindTo = filter_var($options['bindto'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? sprintf('[%s]:0', $options['bindto']) : sprintf('%s:0', $options['bindto']);
            stream_context_set_option($context, 'socket', 'bindto', $bindTo);
        }
        $stream = stream_socket_client($remote, $errno, $errstr, $options['timeout'], STREAM_CLIENT_CONNECT, $context);
        return $stream;
    }
    private function toggleCrypto($stream, $enable = true)
    {
        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        stream_socket_enable_crypto($stream, $enable, $method);
    }
}
}

namespace EasyRequest {
use EasyRequest\Psr7\Stream;
use Psr\Http\Message\RequestInterface;
function normalize_query_rfc3986($query)
{
    parse_str($query, $array);
    return http_build_query_rfc3986($array);
}
function http_build_query_rfc3986(array $array)
{
    if (PHP_VERSION_ID >= 50400) {
        return http_build_query($array, '', '&', PHP_QUERY_RFC3986);
    }
    return preg_replace_callback('#([^=&]+)=([^&]*)#i', function ($match) {
        return $match[1] . '=' . rawurlencode(urldecode($match[2]));
    }, http_build_query($array));
}
function get_headers(RequestInterface $request)
{
    $headers = array();
    foreach ($request->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            $headers[] = sprintf('%s: %s', $name, $value);
        }
    }
    return $headers;
}
function decode_chunked($body)
{
    $data = $body;
    $pos = 0;
    $len = strlen($data);
    $outData = '';
    while ($pos < $len) {
        $rawnum = substr($data, $pos, strpos(substr($data, $pos), "\r\n") + 2);
        $num = hexdec(trim($rawnum));
        $pos += strlen($rawnum);
        $chunk = substr($data, $pos, $num);
        $outData .= $chunk;
        $pos += strlen($chunk);
    }
    return $outData;
}
function decode_gzip($data)
{
    if (function_exists('gzdecode')) {
        return gzdecode($data);
    }
    return gzinflate(substr($data, 10, -8));
}
function stream_for($content)
{
    switch (gettype($content)) {
        case 'resource':
            return new Stream($content);
        case 'string':
            $fp = fopen('php://temp', 'w+');
            if ($content) {
                fwrite($fp, $content);
                rewind($fp);
            }
            return new Stream($fp);
        case 'object':
            if ($content instanceof Stream) {
                return $content;
            }
            if (method_exists($content, '__toString')) {
                return stream_for($content->__toString());
            }
            throw new InvalidArgumentException('Object must be an instance of StreamInterface or stringable');
        default:
            throw new InvalidArgumentException('Stream type is unsupported');
    }
}
function add_param_to_array(&$array, $name, $value = null, $append = false, $recursive = false)
{
    if (is_string($name) && strpos($name, '=')) {
        parse_str($name, $data);
    } elseif (is_array($name)) {
        $data = $name;
    } else {
        $data[$name] = $value;
    }
    if ($append) {
        if ($recursive) {
            $array = array_merge_recursive($array, $data);
        } else {
            $array = $data + $array;
        }
    } else {
        $array = $data;
    }
}
function mimetype_from_filename($filename)
{
    return mimetype_from_extension(pathinfo($filename, PATHINFO_EXTENSION));
}
function mimetype_from_extension($extension)
{
    static $mimetypes = array('7z' => 'application/x-7z-compressed', 'aac' => 'audio/x-aac', 'ai' => 'application/postscript', 'aif' => 'audio/x-aiff', 'asc' => 'text/plain', 'asf' => 'video/x-ms-asf', 'atom' => 'application/atom+xml', 'avi' => 'video/x-msvideo', 'bmp' => 'image/bmp', 'bz2' => 'application/x-bzip2', 'cer' => 'application/pkix-cert', 'crl' => 'application/pkix-crl', 'crt' => 'application/x-x509-ca-cert', 'css' => 'text/css', 'csv' => 'text/csv', 'cu' => 'application/cu-seeme', 'deb' => 'application/x-debian-package', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'dvi' => 'application/x-dvi', 'eot' => 'application/vnd.ms-fontobject', 'eps' => 'application/postscript', 'epub' => 'application/epub+zip', 'etx' => 'text/x-setext', 'flac' => 'audio/flac', 'flv' => 'video/x-flv', 'gif' => 'image/gif', 'gz' => 'application/gzip', 'htm' => 'text/html', 'html' => 'text/html', 'ico' => 'image/x-icon', 'ics' => 'text/calendar', 'ini' => 'text/plain', 'iso' => 'application/x-iso9660-image', 'jar' => 'application/java-archive', 'jpe' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'js' => 'text/javascript', 'json' => 'application/json', 'latex' => 'application/x-latex', 'log' => 'text/plain', 'm4a' => 'audio/mp4', 'm4v' => 'video/mp4', 'mid' => 'audio/midi', 'midi' => 'audio/midi', 'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'mp4a' => 'audio/mp4', 'mp4v' => 'video/mp4', 'mpe' => 'video/mpeg', 'mpeg' => 'video/mpeg', 'mpg' => 'video/mpeg', 'mpg4' => 'video/mp4', 'oga' => 'audio/ogg', 'ogg' => 'audio/ogg', 'ogv' => 'video/ogg', 'ogx' => 'application/ogg', 'pbm' => 'image/x-portable-bitmap', 'pdf' => 'application/pdf', 'pgm' => 'image/x-portable-graymap', 'png' => 'image/png', 'pnm' => 'image/x-portable-anymap', 'ppm' => 'image/x-portable-pixmap', 'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'ps' => 'application/postscript', 'qt' => 'video/quicktime', 'rar' => 'application/x-rar-compressed', 'ras' => 'image/x-cmu-raster', 'rss' => 'application/rss+xml', 'rtf' => 'application/rtf', 'sgm' => 'text/sgml', 'sgml' => 'text/sgml', 'svg' => 'image/svg+xml', 'swf' => 'application/x-shockwave-flash', 'tar' => 'application/x-tar', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'torrent' => 'application/x-bittorrent', 'ttf' => 'application/x-font-ttf', 'txt' => 'text/plain', 'wav' => 'audio/x-wav', 'webm' => 'video/webm', 'wma' => 'audio/x-ms-wma', 'wmv' => 'video/x-ms-wmv', 'woff' => 'application/x-font-woff', 'wsdl' => 'application/wsdl+xml', 'xbm' => 'image/x-xbitmap', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xml' => 'application/xml', 'xpm' => 'image/x-xpixmap', 'xwd' => 'image/x-xwindowdump', 'yaml' => 'text/yaml', 'yml' => 'text/yaml', 'zip' => 'application/zip');
    $extension = strtolower($extension);
    return isset($mimetypes[$extension]) ? $mimetypes[$extension] : null;
}
}

namespace EasyRequest\Psr7 {
use Psr\Http\Message\StreamInterface;
class AppendStream implements StreamInterface
{
    private $streams = array();
    private $seekable = true;
    private $position = 0;
    private $current = 0;
    public function __construct(array $streams = array())
    {
        foreach ($streams as $stream) {
            $this->add($stream);
        }
    }
    public function add(StreamInterface $stream)
    {
        if (!$stream->isReadable()) {
            throw new \InvalidArgumentException('Each stream must be readable');
        }
        $this->seekable = $stream->isSeekable() && $this->seekable;
        $this->streams[] = $stream;
        return $this;
    }
    public function __toString()
    {
        try {
            $this->rewind();
            return $this->getContents();
        } catch (\Exception $e) {
            return '';
        }
    }
    public function close()
    {
        $this->position = $this->current = 0;
        foreach ($this->streams as $stream) {
            $stream->close();
        }
        $this->streams = array();
    }
    public function detach()
    {
        $this->close();
    }
    public function getSize()
    {
        $total = 0;
        foreach ($this->streams as $stream) {
            if (null === ($size = $stream->getSize())) {
                return;
            }
            $total += $size;
        }
        return $total;
    }
    public function tell()
    {
        return $this->position;
    }
    public function eof()
    {
        return !$this->streams || $this->current >= count($this->streams) - 1 && $this->streams[$this->current]->eof();
    }
    public function isSeekable()
    {
        return $this->seekable;
    }
    public function seek($offset, $whence = SEEK_SET)
    {
        if (!$this->isSeekable()) {
            throw new \RuntimeException('This AppendStream is not seekable');
        } elseif ($whence !== SEEK_SET) {
            throw new \RuntimeException('The AppendStream can only seek with SEEK_SET');
        }
        $this->position = $this->current = 0;
        foreach ($this->streams as $i => $stream) {
            try {
                $stream->rewind();
            } catch (\Exception $e) {
                throw new \RuntimeException('Unable to seek stream ' . $i . ' of the AppendStream', 0, $e);
            }
        }
        while ($this->position < $offset && !$this->eof()) {
            $result = $this->read(min(8192, $offset - $this->position));
            if ($result === '') {
                break;
            }
        }
    }
    public function rewind()
    {
        $this->seek(0);
    }
    public function isWritable()
    {
        return false;
    }
    public function write($string)
    {
        throw new \RuntimeException('The AppendStream is not writeable');
    }
    public function isReadable()
    {
        return true;
    }
    public function read($length)
    {
        $remaning = $length;
        $total = count($this->streams) - 1;
        $buffer = '';
        $next = false;
        while ($remaning > 0) {
            if ($next || $this->streams[$this->current]->eof()) {
                if ($this->current === $total) {
                    break;
                }
                $this->current++;
            }
            $result = $this->streams[$this->current]->read($remaning);
            if ($result == null) {
                $next = true;
                continue;
            }
            $buffer .= $result;
            $remaning -= strlen($buffer);
        }
        $this->position += $length - $remaning;
        return $buffer;
    }
    public function getContents()
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }
        return $contents;
    }
    public function getMetadata($key = null)
    {
        return $key ? null : array();
    }
}
}

namespace EasyRequest\Psr7 {
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
class Message implements MessageInterface
{
    protected $protocolVersion = '1.1';
    protected $headers = array();
    protected $body;
    public function getProtocolVersion()
    {
        return $this->protocolVersion;
    }
    public function withProtocolVersion($version)
    {
        $new = clone $this;
        $new->protocolVersion = (string) $version;
        return $new;
    }
    public function getHeaders()
    {
        $out = array();
        foreach ($this->headers as $key => $header) {
            $out[$header['name']] = $header['values'];
        }
        return $out;
    }
    public function hasHeader($name)
    {
        return array_key_exists($this->normalizeHeaderKey($name), $this->headers);
    }
    public function getHeader($name)
    {
        $key = $this->normalizeHeaderKey($name);
        if (isset($this->headers[$key])) {
            return $this->headers[$key]['values'];
        }
        return array();
    }
    public function getHeaderLine($name)
    {
        return implode(',', $this->getHeader($name));
    }
    public function withHeader($name, $value)
    {
        $clone = clone $this;
        $this->addHeaderToArray($clone->headers, $name, $value, false);
        return $clone;
    }
    public function withAddedHeader($name, $value)
    {
        $clone = clone $this;
        $this->addHeaderToArray($clone->headers, $name, $value, true);
        return $clone;
    }
    public function withoutHeader($name)
    {
        $new = clone $this;
        unset($new->headers[$this->normalizeHeaderKey($name)]);
        return $new;
    }
    public function getBody()
    {
        if (!$this->body) {
            $this->body = \EasyRequest\stream_for('');
        }
        return $this->body;
    }
    public function withBody(StreamInterface $body)
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }
    protected function addHeaderToArray(&$headers, $name, $value, $append = false)
    {
        $key = $this->normalizeHeaderKey($name);
        if (!isset($headers[$key])) {
            $headers[$key] = array('name' => $name, 'values' => array());
        }
        $headers[$key]['values'] = $append ? array_merge($headers[$key]['values'], (array) $value) : (array) $value;
    }
    protected function normalizeHeaderKey($name)
    {
        return strtolower($name);
    }
}
}

namespace EasyRequest\Psr7 {
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
class Request extends Message implements RequestInterface
{
    protected $uri;
    protected $method;
    public function __construct($uri, $method = 'GET', array $headers = array(), $protocolVersion = '1.1')
    {
        $this->protocolVersion = $protocolVersion;
        $this->method = $this->normalizeMethod($method);
        $this->uri = $uri instanceof Uri ? $uri : new Uri($uri);
        if ($host = $this->uri->getHost()) {
            $this->updateHeaderHost($host);
        }
        foreach ($headers as $name => $value) {
            $this->addHeaderToArray($this->headers, $name, $value);
        }
    }
    public function getRequestTarget()
    {
        $target = $this->uri->getPath();
        if (!$target) {
            $target = '/';
        }
        if ($query = $this->uri->getQuery()) {
            $target .= '?' . $query;
        }
        return $target;
    }
    public function withRequestTarget($requestTarget)
    {
        if (strpos($requestTarget, ' ') !== false) {
            throw new InvalidArgumentException('Given request target is invalid; cannot contain whitespace');
        }
        $new = clone $this;
        $new->requestTarget = $requestTarget;
        return $new;
    }
    public function getMethod()
    {
        return $this->method;
    }
    public function withMethod($method)
    {
        $new = clone $this;
        $new->method = $this->normalizeMethod($method);
        return $new;
    }
    public function getUri()
    {
        return $this->uri;
    }
    public function withUri(UriInterface $uri, $preserveHost = false)
    {
        $new = clone $this;
        $new->uri = $uri;
        if (!$preserveHost || !empty($this->headers['host']) && $uri->getHost()) {
            $new->updateHeaderHost($uri->getHost());
        }
        return $new;
    }
    protected function normalizeMethod($method)
    {
        if (!is_string($method)) {
            throw new \InvalidArgumentException('Method must be a string');
        }
        return strtoupper($method);
    }
    protected function updateHeaderHost($host)
    {
        if ($port = $this->uri->getPort()) {
            $host .= ':' . $port;
        }
        $this->addHeaderToArray($this->headers, 'Host', $host, false);
    }
}
}

namespace EasyRequest\Psr7 {
use Psr\Http\Message\ResponseInterface;
class Response extends Message implements ResponseInterface
{
    protected $status = 0;
    protected $reasonPhrase = '';
    protected static $statusCodes = array(100 => 'Continue', 101 => 'Switching Protocols', 200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 307 => 'Temporary Redirect', 400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Payload Too Large', 414 => 'URI Too Long', 415 => 'Unsupported Media Type', 416 => 'Range Not Satisfiable', 417 => 'Expectation Failed', 426 => 'Upgrade Required', 451 => 'Unavailable For Legal Reasons', 500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported');
    public static function parse($header, $body)
    {
        $response = new self();
        $lines = array_filter(explode("\r\n", $header));
        if (preg_match('#^HTTP/(?P<protocol>[\\d\\.]+)\\s(?P<status>\\d+)\\s(?P<reason>.*?)$#i', array_shift($lines), $match)) {
            $response->protocolVersion = $match['protocol'];
            $response->status = (int) $match['status'];
            $response->reasonPhrase = $match['reason'];
            foreach ($lines as $line) {
                list($name, $value) = explode(':', $line, 2);
                $response->addHeaderToArray($response->headers, trim($name), trim($value), true);
            }
            $response->body = \EasyRequest\stream_for($body);
        }
        return $response;
    }
    public function getStatusCode()
    {
        return $this->status;
    }
    public function withStatus($code, $reasonPhrase = '')
    {
        if (!isset(self::$statusCodes[$code]) || strlen($code) != 3) {
            throw new \InvalidArgumentException('Given status is invalid');
        }
        $new = clone $this;
        $new->status = (int) $status;
        $new->reasonPhrase = $reasonPhrase ? $reasonPhrase : self::$statusCodes[$new->status];
        return $new;
    }
    public function getReasonPhrase()
    {
        return $this->reasonPhrase;
    }
}
}

namespace EasyRequest\Psr7 {
use Psr\Http\Message\StreamInterface;
class Stream implements StreamInterface
{
    private $stream;
    private $seekable;
    private $readable;
    private $writable;
    private $uri;
    private static $readWriteHash = array('read' => array('r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+' => true, 'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b' => true, 'rt' => true, 'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t' => true, 'a+' => true), 'write' => array('w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true, 'c+' => true, 'wb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true, 'c+b' => true, 'w+t' => true, 'r+t' => true, 'x+t' => true, 'c+t' => true, 'a' => true, 'a+' => true));
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a resource');
        }
        $this->stream = $stream;
        $meta = stream_get_meta_data($stream);
        $this->seekable = $meta['seekable'];
        $this->readable = !empty(self::$readWriteHash['read'][$meta['mode']]);
        $this->writable = !empty(self::$readWriteHash['write'][$meta['mode']]);
        $this->uri = isset($meta['uri']) ? $meta['uri'] : null;
    }
    public function __toString()
    {
        try {
            if ($this->isSeekable()) {
                $this->seek(0);
            }
            return $this->getContents();
        } catch (\Exception $e) {
            return '';
        }
    }
    public function close()
    {
        if (isset($this->stream)) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            $this->detach();
        }
    }
    public function detach()
    {
        if (!isset($this->stream)) {
            return;
        }
        $result = $this->stream;
        $this->stream = $this->size = $this->uri = null;
        $this->readable = $this->writable = $this->seekable = false;
        return $result;
    }
    public function getSize()
    {
        if (!$this->stream) {
            return;
        }
        if ($this->uri) {
            clearstatcache(true, $this->uri);
        }
        $stats = fstat($this->stream);
        return isset($stats['size']) ? $stats['size'] : null;
    }
    public function tell()
    {
        $result = ftell($this->stream);
        if ($result === false) {
            throw new \RuntimeException('Unable to determine stream position');
        }
        return $result;
    }
    public function eof()
    {
        return !$this->stream || feof($this->stream);
    }
    public function isSeekable()
    {
        return $this->seekable;
    }
    public function seek($offset, $whence = SEEK_SET)
    {
        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable');
        }
        if (fseek($this->stream, $offset, $whence) == -1) {
            throw new \RuntimeException(sprintf('Unable to seek to stream' . ' position %d with whence %s', $offset, var_export($whence, true)));
        }
    }
    public function rewind()
    {
        $this->seek(0);
    }
    public function isWritable()
    {
        return $this->writable;
    }
    public function write($string)
    {
        if (!$this->writable) {
            throw new \RuntimeException('Cannot write to a non-writable stream');
        }
        $this->size = null;
        $result = fwrite($this->stream, $string);
        if ($result === false) {
            throw new \RuntimeException('Unable to write to stream');
        }
        return $result;
    }
    public function isReadable()
    {
        return $this->readable;
    }
    public function read($length)
    {
        if (!$this->readable) {
            throw new \RuntimeException('Unable to read non-readable stream');
        }
        return fread($this->stream, $length);
    }
    public function getContents()
    {
        return stream_get_contents($this->stream);
    }
    public function getMetadata($key = null)
    {
        if (!isset($this->stream)) {
            return $key ? null : array();
        }
        if (!$key) {
            return stream_get_meta_data($this->stream);
        }
        $meta = stream_get_meta_data($this->stream);
        return isset($meta[$key]) ? $meta[$key] : null;
    }
}
}

namespace EasyRequest\Psr7 {
use Psr\Http\Message\UriInterface;
class Uri implements UriInterface
{
    private $scheme = '';
    private $user = '';
    private $pass = null;
    private $host = '';
    private $port = null;
    private $path = '';
    private $query = '';
    private $fragment = '';
    public function __construct($url = null)
    {
        if (is_string($url) && ($parsed = parse_url($url))) {
            foreach ($parsed as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
    public function getScheme()
    {
        return $this->scheme;
    }
    public function getAuthority()
    {
        $userInfo = $this->getUserInfo();
        return ($userInfo ? $userInfo . '@' : '') . $this->host . ($this->port ? ':' . $this->port : '');
    }
    public function getUserInfo()
    {
        return $this->user . ($this->pass ? ':' . $this->pass : '');
    }
    public function getHost()
    {
        return $this->host;
    }
    public function getPort()
    {
        return $this->port;
    }
    public function getPath()
    {
        return $this->path;
    }
    public function getQuery()
    {
        return $this->query;
    }
    public function getFragment()
    {
        return $this->fragment;
    }
    public function withScheme($scheme)
    {
        if (!is_string($scheme)) {
            throw new \InvalidArgumentException('Scheme must be a string');
        }
        $new = clone $this;
        $new->scheme = strtolower($scheme);
        return $new;
    }
    public function withUserInfo($user, $password = null)
    {
        $new = clone $this;
        $new->user = $user;
        $new->pass = $password;
        return $new;
    }
    public function withHost($host)
    {
        if (!is_string($host) || !preg_match('#[\\w_-\\.]+#', $host)) {
            throw new \InvalidArgumentException('Given host name is invalid');
        }
        $new = clone $this;
        $new->host = $host;
        return $new;
    }
    public function withPort($port)
    {
        if ($port !== null && !is_numeric($port) || $port > 65535) {
            throw new \InvalidArgumentException('Port number must be an integer or null');
        }
        $new = clone $this;
        $new->port = is_null($port) ? null : (int) $port;
        return $new;
    }
    public function withPath($path)
    {
        if (!is_string($path)) {
            throw new \InvalidArgumentException('Path must be a string');
        }
        $new = clone $this;
        $new->path = $path;
        return $new;
    }
    public function withQuery($query)
    {
        if (!is_string($query)) {
            throw new \InvalidArgumentException('Query must be a string');
        }
        $new = clone $this;
        $new->query = \EasyRequest\normalize_query_rfc3986($query);
        return $new;
    }
    public function withFragment($fragment)
    {
        $new = clone $this;
        $new->fragment = ltrim($fragment, '#');
        return $new;
    }
    public function __toString()
    {
        $scheme = $this->getScheme();
        $authority = $this->getAuthority();
        $path = $this->getPath();
        $query = $this->getQuery();
        $fragment = $this->getFragment();
        if ($authority && substr($path, 0, 1) === '/') {
            $path = '/' . ltrim($path, '/');
        }
        if (!$authority && substr($path, 0, 2) === '//') {
            $path = '/' . ltrim($path, '/');
        }
        return ($scheme ? $scheme . ':' : '') . ($authority ? '//' . $authority : '') . $path . ($query ? '?' . $query : '') . ($fragment ? '#' . $fragment : '');
    }
}
}

