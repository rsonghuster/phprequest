<?php

namespace EasyRequest\Psr7;

use Psr\Http\Message\ResponseInterface;

class Response extends Message implements ResponseInterface
{
    protected $status = 0;
    protected $reasonPhrase = '';

    protected static $statusCodes = array(
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        426 => 'Upgrade Required',
        // @link https://tools.ietf.org/html/draft-tbray-http-legally-restricted-status-00
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    );

    public static function parse($header, $body)
    {
        $response = new self;

        $lines = array_filter(explode("\r\n", $header));
        if (preg_match('#^HTTP/(?P<protocol>[\d\.]+)\s(?P<status>\d+)\s(?P<reason>.*?)$#i', array_shift($lines), $match)) {
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

    /**
     * Gets the response status code.
     *
     * The status code is a 3-digit integer result code of the server's attempt
     * to understand and satisfy the request.
     *
     * @return int Status code.
     */
    public function getStatusCode()
    {
        return $this->status;
    }
    /**
     * Return an instance with the specified status code and, optionally, reason phrase.
     *
     * If no reason phrase is specified, implementations MAY choose to default
     * to the RFC 7231 or IANA recommended reason phrase for the response's
     * status code.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated status and reason phrase.
     *
     * @link http://tools.ietf.org/html/rfc7231#section-6
     * @link http://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     * @param  int                       $code         The 3-digit integer result code to set.
     * @param  string                    $reasonPhrase The reason phrase to use with the
     *                                                 provided status code; if none is provided, implementations MAY
     *                                                 use the defaults as suggested in the HTTP specification.
     * @throws \InvalidArgumentException For invalid status code arguments.
     * @return self
     */
    public function withStatus($code, $reasonPhrase = '')
    {
        if (! isset(self::$statusCodes[$code]) || strlen($code) != 3) {
            throw new \InvalidArgumentException('Given status is invalid');
        }

        $new = clone $this;
        $new->status = (int) $status;
        $new->reasonPhrase = $reasonPhrase ? $reasonPhrase : self::$statusCodes[$new->status];

        return $new;
    }
    /**
     * Gets the response reason phrase associated with the status code.
     *
     * Because a reason phrase is not a required element in a response
     * status line, the reason phrase value MAY be null. Implementations MAY
     * choose to return the default RFC 7231 recommended reason phrase (or those
     * listed in the IANA HTTP Status Code Registry) for the response's
     * status code.
     *
     * @link http://tools.ietf.org/html/rfc7231#section-6
     * @link http://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     * @return string Reason phrase; must return an empty string if none present.
     */
    public function getReasonPhrase()
    {
        return $this->reasonPhrase;
    }
}
