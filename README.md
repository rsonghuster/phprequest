# EasyRequest
A light weight PHP http client implements PSR7, use socket/curl for sending requests.

### Features
- All general features as other libraries
- Upload large file (larger than 2GB)
- Supports HTTP proxy, SOCKS4, SOCKS4A, SOCKS5 (both CURL and Socket handler).
- Use PSR-7 http messages

### Requirements
- Socket enabled or curl extension installed
- PHP 5.3+

### Installation
```
composer require ptcong/easyrequest:^1.0
```

Sometimes you don't want to use composer, just want to include only one file as way our old library does ([ptcong/php-http-client](https://github.com/ptcong/php-http-client)). Let run `build/run.php` script to get `compiled.php` file and include it at the top of your script.

### Usage
* [Create a client](#create-a-request)
* [Set request options](#set-request-options)
* [Quick request](#quick-request)
* [Add headers to request](#add-header-to-request)
* [Working with cookies (set, get)](#working-with-cookies)
* [Add query string](#add-query-string)
* [Add form param (post form)](#add-form-param)
* [Add multipart data](#add-multipart-data)
* [Upload file](#upload-file)
* [Post raw data](#post-raw-data)
* [Post JSON data](#post-json-data)
* [Working with SOCKS, PROXY](#working-with-proxy)
* [Auth basic](#auth-basic)
* [Bind request to specific IP](#bind-request-to-interface)
* [Get response](#get-response)
* [Get all redirected requests](#get-redirected-requests)
* [Get debug, error message](#get-debug-info)

#### Create a request

`Client::request` method just create a new instance of `\EasyRequest\Client` class.

```php
$request = \EasyRequest\Client::request('https://google.com');
$request = \EasyRequest\Client::request('https://google.com', 'GET', $options);
$request = \EasyRequest\Client::request('https://google.com', 'POST', $options);

// then
$request->send();
// or
$request = \EasyRequest\Client::request('https://google.com')->send();
```

with default options

```php
$request = \EasyRequest\Client::request('https://google.com', 'POST', array(
    'protocol_version' => '1.1',
    'method'           => 'GET',
    'header'           => array(),
    'body'             => '',
    'body_as_json'     => false,
    'query'            => array(),
    'form_param'       => array(),
    'multipart'        => array(),
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
    'curl'             => array(), // use more curl options ?
));
```

#### Set request options

`withOption` method will overide to existing option. If you want to append query string, form param to current option, read it under.

```php
$request = \EasyRequest\Client::request('https://google.com', 'GET', array(
    'follow_redirects' => 3,
    'query'            => 'a=b&c=d'
));

// or
$request->withOption('follow_redirects', 3);

// or
$request->withOption(array(
    'follow_redirects' => 3,
    'query'            => 'a=b&c=d'
));
```

#### Quick request

This methods will create new request and send immediately, you don't need to call `send()` method here.

```php
$response = \EasyRequest\Client::get('https://google.com', 'GET', array(
    'follow_redirects' => 3,
    'query'            => 'a=b&c=d'
));

$response = \EasyRequest\Client::post('https://google.com', ...);
$response = \EasyRequest\Client::head('https://google.com', ...);
$response = \EasyRequest\Client::patch('https://google.com', ...);
$response = \EasyRequest\Client::delete('https://google.com', ...);

... more 
```

#### Add header to request

```php
$request = \EasyRequest\Client::request('https://google.com', 'GET', array(
    'header' => array(
        'User-Agent' => 'Firefox 45',
        'Cookie' => 'cookie1=value1; cookie2=value2;',
        'Example' => array(
            'value 1',
            'value 2'
        )
    ),
));

// you also can use PSR7 as
$request = \EasyRequest\Client::request('https://google.com')
    ->withHeader('User-Agent', 'Firefox 45')
    ->withHeader('Example', array('value1', 'value 2'));
```

#### Working with cookies

You can use `header` option to add cookie as above, but we already have `CookieJar` to handle cookie for you.
By deaults, Client class will automatic create an `CookieJar` instance before sending request if cookie_jar is not set. 
You can choose:

* `FileCookieJar` to write cookies to file as browser, next request it will use cookies from this file.
* `SessionCookieJar` to write cookies to $_SESSION (remember, you have added session_start() at the top of your script)
* `CookieJar` just write cookies to array and all cookies will be lost if your script run completed.

```php
$jar = new \EasyRequest\Cookie\CookieJar;
// or
$jar = new \EasyRequest\Cookie\FileCookieJar($filePath);
// or
$jar = new \EasyRequest\Cookie\SessionCookieJar;

// add cookie from string of multiple cookies
$jar->fromString('cookie1=value1; cookie2=value2');

// add cookie with more information 
$jar->add(Cookie::parse('cookie2=value2; path=/; domain=abc.com')); 

// add cookie from \Psr\Http\Message\ResponseInterface
$jar->fromResponse($response);

// read more at \EasyRequest\Cookie\CookieJarInterface

$request = \EasyRequest\Client::request('https://google.com', 'GET', array(
    'cookie_jar' => $jar
))->send();
```

**Get response cookies**

```php
var_dump($jar->toArray());
// or
var_dump($request->getOption('cookie_jar')->toArray());

var_dump((string) $jar);
```

You can use `$jar->getFor($domain, $path)` method to get cookies for specific domain and path.
This method will create new CookieJar instance contains your cookies

```php
var_dump($jar->getFor($domain, $path));
```

#### Add query string

```php
$options = array(
    'query' => 'a=b&c=d'
);
// or
$options = array(
    'query' => array(
        'a' => 'b',
        'c' => 'd',
        'x' => array(
            'y', 'z'
        ),
        'x2' => array(
            'y2' => 'z2'
        )
    )
);
$request = \EasyRequest\Client::request('https://google.com', 'GET', $options);
```

or you can use `withQuery` method, it's dynamic method and can handle some cases

```php
    /**
     * Add query string to request.
     *
     * @param  string|array $name      This value may be:
     *                                 - a query string
     *                                 - array of query string
     * @param  null|string  $value
     * @param  bool         $append
     * @param  bool         $recursive
     * @return self
     */
    $request->withQuery($name, $value = null, $append = true, $recursive = false);


$request->withQuery(array(
    'a' => 'b',
    'c' => 'd',
    'x' => array(
        'y', 'z'
    ),
    'x2' => array(
        'y2' => 'z2'
    )
));
// or
$request->withQuery('query=value1&key2=value2');
// or
$request->withQuery('query', 'value1');
// if you want to clear all existing query and add new, just use false for $append
$request->withQuery('query', 'value1', false);
```

#### Add form param

This is similar with [Add query string](#add-query-string)

and you can use `withFormParam` method same as `withQuery`

```php
    /**
     * Add form param to request.
     *
     * @param  string|array $name      This value may be:
     *                                 - query string
     *                                 - array of query string
     * @param  null|string  $value
     * @param  bool         $append
     * @param  bool         $recursive
     * @return self
     */
    public function withFormParam($name, $value = null, $append = true, $recursive = false)
```

#### Add multipart data

Each multipart part requires `name`, `contents` 

```php
$request = \EasyRequest\Client::request('https://google.com', 'GET', array(
    'multipart' => array(
        array(
            'name'     => 'input1',
            'contents' => 'value1'
        ),
        array(
            'name'     => 'input1',
            'contents' => 'value1',
            'filename' => 'custom file name.txt'
            'headers'  => array('Custom-header' => 'value'),
        )
    ),
));

// you also can use
$request
    ->withMultipart('field2', 'value2')
    ->withMultipart('field3', 'value3', 'fieldname3')
    ->withMultipart('field4', 'value4', 'fieldname4', array('Custom-Header' => 'value'))
    ->withMultipart('file5', fopen('/path/to/file'), 'filename1') // to upload file
```

#### Upload file

You can use multipart option to upload file, but use this method is more easier.

```php
$request
    ->withFormFile('file1', '/path/to/file1', $optionalFileName = null, $optionalHeaders = array())
    ->withFormFile('file2', '/path/to/file2');
```

#### Post RAW data

```php
$request->withBody('raw data');
```

#### Post JSON data
Used to easily upload JSON encoded data as the body of a request. A `Content-Type: application/json` header will be added if no Content-Type header is already present on the message.

```php
$request->withJson(array(1,2,3));
// or
$request->withJson(json_encode(array(1,2,3)));
```

#### Working with proxy

You may use a HTTP or SOCKS4, SOCKS4A, SOCKS5 Proxy.

```php
$request = \EasyRequest\Client::request('http://domain.com', 'POST', array(
    'proxy'         => '192.168.1.105:8888',
    'proxy_userpwd' => 'user:pass',
    'proxy_type'    => Client::PROXY_SOCKS5, // if not given, it will use this proxy as HTTP_PROXY
));

$request->withProxy('192.168.1.105:8888', 'user:pass', Client::PROXY_SOCKS5);
$request->withProxy('192.168.1.105:8888', null, Client::PROXY_SOCKS4);
$request->withProxy('192.168.1.105:8888', null, Client::PROXY_HTTP);

$request->withSocks4Proxy('192.168.1.105:8888'); 
$request->withSocks5Proxy('192.168.1.105:8888', 'user:pass'); 
$request->withHttpProxy('192.168.1.105:8888', 'user:pass'); 
```


#### Auth basic
```php
$request = \EasyRequest\Client::request('http://domain.com', 'POST', array(
    'auth' => 'user:pass',
));
```

#### Bind request to interface
```php
$request = \EasyRequest\Client::request('http://domain.com', 'POST', array(
    'bindto' => '123.123.123.123', // same as CURLOPT_INTERFACE option
));
```

### Get Response
```php
$request = \EasyRequest\Client::request('http://domain.com', 'POST');
$response = $request->send();

// Returns \Psr\Http\Message\RequestInterface
var_dump($request->getRequest());

// Returns \Psr\Http\Message\ResponseInterface
// Or null if request is not sent or failure
var_dump($request->getResponse());

var_dump($response);
```

When working with follow redirects option, sometimes you may want to get current url (last redirected url).

This `getCurrentUri` method will returns \Psr\Http\Message\UriInterface

```php
$request->getCurrentUri();
```

```php
$response = $request->getResponse();

// you can use PSR7 here
$response->getHeaders();
$response->getHeader('Set-Cookie');
$response->getHeaderLine('Location');

$response->getProtocolVersion();

echo (string) $response->getBody();
```

#### Get redirected requests
When use follow_redirects option, sometimes you want to get all the requests and responses.

Each request and response are following PSR7

```php
var_dump($request->getRequests());
```

**Get responses**

```php
var_dump($request->getResponses());
```

#### Get debug info
When a request is fail, you can use `$request->getError()` to see error message.

and use `$request->getDebug()` to see some debug information.
