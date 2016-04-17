<?php

namespace EasyRequest\Handler;

use EasyRequest\Client;
use EasyRequest\Cookie\CookieJar;
use EasyRequest\Psr7\Response;
use Exception;
use Psr\Http\Message\RequestInterface;

class Socket implements HandlerInterface
{
    /**
     * {@inheritdoc}
     */
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

        if (! $stream = $this->getConnection($host, $port, $errno, $errstr, $options, $uri)) {
            throw new Exception(sprintf("Couldn't connect to %s:%s. %s - %s", $host, $port, $errno, $errstr));
        }

        $httpsThroughSocks = $options['proxy']
                            && $options['proxy_type'] != Client::PROXY_HTTP
                            && $uri->getScheme() == 'https';

        $headers = \EasyRequest\get_headers($request);
        $headers[] = 'Connection: close';

        // handle proxy
        if ($options['proxy']) {
            try {
                switch ($options['proxy_type']) {
                    case Client::PROXY_HTTP:
                        if ($options['proxy_userpwd']) {
                            $headers[] = 'Proxy-Authorization: Basic '.base64_encode($options['proxy_userpwd']);
                        }
                        break;

                    case Client::PROXY_SOCKS5:
                        $this->handleSocks5($stream, $options, $targetHost, $targetPort);
                        break;

                    case Client::PROXY_SOCKS4:
                    case Client::PROXY_SOCKS4A:
                        if ($options['proxy_userpwd']) {
                            // socks4 does not support authentication,
                            // if user give a wrong version, just handle it
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

        // build request header
        $header = sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $requestTarget, $request->getProtocolVersion());
        $header .= implode("\r\n", $headers)."\r\n";
        $header .= "\r\n";

        $this->sendRequest($header, $request->getBody(), $options,
            function ($message) use ($stream) {
                fwrite($stream, $message);
            });

        $request->getBody()->close();

        // ignore some warning such as "SSL: Connection reset by peer",
        // this issue sometimes happen on some SOCKS servers while
        // browsing to HTTPS website.
        $httpsThroughSocks && $level = error_reporting(~E_WARNING);

        $response = '';
        while (! feof($stream)) {
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
        $msg = pack('C*', 0x04, 0x01, 0x00, $targetPort);

        if ($ip !== false) {
            // SOCKS4
            $msg .= pack('N', $ip); // same as pack('C4', $addr[0], $addr[1], $addr[2], $addr[3]);
        } else {
            // SOCKS4A
            $msg .= pack('C*', 0x00, 0x00, 0x00, 0x01, 0x00).$targetHost;
        }
        $msg .= pack('C', 0x00);

        fwrite($stream, $msg);

        $reply = fread($stream, 1024);

        if (substr($reply, 1, 1) != pack('C', 90)) {
            throw new Exception('Socks: Request is not granted');
        }
    }

    private function handleSocks5($stream, $options, $targetHost, $targetPort)
    {
        // @link https://www.ietf.org/rfc/rfc1928.txt
        $method = $options['proxy_userpwd'] ? 0x02 : 0x00;
        fwrite($stream, pack('C3', 0x05, 0x01, $method));

        // send auth method
        $reply = fread($stream, 2);
        if ($reply != pack('C2', 0x05, $method)) {
            throw new Exception('Socks: Server does not accept the method');
        }

        // @link https://www.ietf.org/rfc/rfc1929.txt
        if ($method == 0x02) {
            list($username, $password) = explode(':', $options['proxy_userpwd']);

            fwrite($stream, pack('C2', 0x01, strlen($username)).$username.pack('C', strlen($password)).$password);
            $reply = fread($stream, 2);

            if ($reply != pack('C2', 0x01, 0x01)) {
                throw new Exception('Socks: Authenication failure');
            }
        }

        // send request connect
        fwrite($stream, pack('C*', 0x05, 0x01, 0x00, 0x03, strlen($targetHost)).$targetHost.pack('n', $targetPort));
        $reply = fread($stream, 1024); // make sure read all of the message

        // 0x00 is granted
        if (substr($reply, 0, 2) != pack('C2', 0x05, 0x00)) {
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

                if (! $length) {
                    break;
                }
            }
        } else {
            $sender($body."\r\n\r\n");
        }
    }

    private function getConnection($host, $port, &$errno, &$errstr, $options, $uri)
    {
        // $level = error_reporting(~E_WARNING);
        $https = $uri->getScheme() == 'https';
        $transport = $https ? 'ssl' : 'tcp';
        $transport = $options['proxy'] ? 'tcp' : $transport;

        $remote = $transport.'://'.$host.':'.$port;

        $context = stream_context_create();

        if ($options['bindto']) {
            $bindTo = filter_var($options['bindto'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ? sprintf('[%s]:0', $options['bindto'])
                : sprintf('%s:0', $options['bindto']);

            stream_context_set_option($context, 'socket', 'bindto', $bindTo);
        }

        $stream = stream_socket_client($remote, $errno, $errstr, $options['timeout'], STREAM_CLIENT_CONNECT, $context);
        // error_reporting($level);

        return $stream;
    }

    private function toggleCrypto($stream, $enable = true)
    {
        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        // php 5.6+
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
