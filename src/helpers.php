<?php

namespace EasyRequest;

use EasyRequest\Psr7\Stream;
use InvalidArgumentException;
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

    return preg_replace_callback(
        '#([^=&]+)=([^&]*)#i',
        function ($match) {
            return $match[1].'='.rawurlencode(urldecode($match[2]));
        },
        http_build_query($array));
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

/**
 * Determines the mimetype of a file by looking at its extension.
 *
 * @param $filename
 *
 * @return null|string
 */
function mimetype_from_filename($filename)
{
    return mimetype_from_extension(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Maps a file extensions to a mimetype.
 *
 * @param $extension string The file extension.
 *
 * @return string|null
 * @link http://svn.apache.org/repos/asf/httpd/httpd/branches/1.3.x/conf/mime.types
 */
function mimetype_from_extension($extension)
{
    static $mimetypes = array(
        '7z'      => 'application/x-7z-compressed',
        'aac'     => 'audio/x-aac',
        'ai'      => 'application/postscript',
        'aif'     => 'audio/x-aiff',
        'asc'     => 'text/plain',
        'asf'     => 'video/x-ms-asf',
        'atom'    => 'application/atom+xml',
        'avi'     => 'video/x-msvideo',
        'bmp'     => 'image/bmp',
        'bz2'     => 'application/x-bzip2',
        'cer'     => 'application/pkix-cert',
        'crl'     => 'application/pkix-crl',
        'crt'     => 'application/x-x509-ca-cert',
        'css'     => 'text/css',
        'csv'     => 'text/csv',
        'cu'      => 'application/cu-seeme',
        'deb'     => 'application/x-debian-package',
        'doc'     => 'application/msword',
        'docx'    => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'dvi'     => 'application/x-dvi',
        'eot'     => 'application/vnd.ms-fontobject',
        'eps'     => 'application/postscript',
        'epub'    => 'application/epub+zip',
        'etx'     => 'text/x-setext',
        'flac'    => 'audio/flac',
        'flv'     => 'video/x-flv',
        'gif'     => 'image/gif',
        'gz'      => 'application/gzip',
        'htm'     => 'text/html',
        'html'    => 'text/html',
        'ico'     => 'image/x-icon',
        'ics'     => 'text/calendar',
        'ini'     => 'text/plain',
        'iso'     => 'application/x-iso9660-image',
        'jar'     => 'application/java-archive',
        'jpe'     => 'image/jpeg',
        'jpeg'    => 'image/jpeg',
        'jpg'     => 'image/jpeg',
        'js'      => 'text/javascript',
        'json'    => 'application/json',
        'latex'   => 'application/x-latex',
        'log'     => 'text/plain',
        'm4a'     => 'audio/mp4',
        'm4v'     => 'video/mp4',
        'mid'     => 'audio/midi',
        'midi'    => 'audio/midi',
        'mov'     => 'video/quicktime',
        'mp3'     => 'audio/mpeg',
        'mp4'     => 'video/mp4',
        'mp4a'    => 'audio/mp4',
        'mp4v'    => 'video/mp4',
        'mpe'     => 'video/mpeg',
        'mpeg'    => 'video/mpeg',
        'mpg'     => 'video/mpeg',
        'mpg4'    => 'video/mp4',
        'oga'     => 'audio/ogg',
        'ogg'     => 'audio/ogg',
        'ogv'     => 'video/ogg',
        'ogx'     => 'application/ogg',
        'pbm'     => 'image/x-portable-bitmap',
        'pdf'     => 'application/pdf',
        'pgm'     => 'image/x-portable-graymap',
        'png'     => 'image/png',
        'pnm'     => 'image/x-portable-anymap',
        'ppm'     => 'image/x-portable-pixmap',
        'ppt'     => 'application/vnd.ms-powerpoint',
        'pptx'    => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'ps'      => 'application/postscript',
        'qt'      => 'video/quicktime',
        'rar'     => 'application/x-rar-compressed',
        'ras'     => 'image/x-cmu-raster',
        'rss'     => 'application/rss+xml',
        'rtf'     => 'application/rtf',
        'sgm'     => 'text/sgml',
        'sgml'    => 'text/sgml',
        'svg'     => 'image/svg+xml',
        'swf'     => 'application/x-shockwave-flash',
        'tar'     => 'application/x-tar',
        'tif'     => 'image/tiff',
        'tiff'    => 'image/tiff',
        'torrent' => 'application/x-bittorrent',
        'ttf'     => 'application/x-font-ttf',
        'txt'     => 'text/plain',
        'wav'     => 'audio/x-wav',
        'webm'    => 'video/webm',
        'wma'     => 'audio/x-ms-wma',
        'wmv'     => 'video/x-ms-wmv',
        'woff'    => 'application/x-font-woff',
        'wsdl'    => 'application/wsdl+xml',
        'xbm'     => 'image/x-xbitmap',
        'xls'     => 'application/vnd.ms-excel',
        'xlsx'    => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xml'     => 'application/xml',
        'xpm'     => 'image/x-xpixmap',
        'xwd'     => 'image/x-xwindowdump',
        'yaml'    => 'text/yaml',
        'yml'     => 'text/yaml',
        'zip'     => 'application/zip',
    );

    $extension = strtolower($extension);

    return isset($mimetypes[$extension])
        ? $mimetypes[$extension]
        : null;
}
