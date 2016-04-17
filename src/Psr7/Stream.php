<?php
namespace EasyRequest\Psr7;

use Psr\Http\Message\StreamInterface;

class Stream implements StreamInterface
{
    private $stream;
    private $seekable;
    private $readable;
    private $writable;
    private $uri;

    private static $readWriteHash = array(
        'read' => array(
            'r'   => true, 'w+'  => true, 'r+'  => true, 'x+'  => true, 'c+' => true,
            'rb'  => true, 'w+b' => true, 'r+b' => true, 'x+b' => true,
            'c+b' => true, 'rt'  => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a+'  => true
        ),
        'write' => array(
            'w'   => true, 'w+'  => true, 'rw'  => true, 'r+'  => true, 'x+' => true,
            'c+'  => true, 'wb'  => true, 'w+b' => true, 'r+b' => true,
            'x+b' => true, 'c+b' => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a'   => true, 'a+'  => true
        )
    );

    /**
     * Create new stream instance.
     *
     * @param  resource                  $stream
     * @throws \InvalidArgumentException
     */
    public function __construct($stream)
    {
        if (! is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a resource');
        }

        $this->stream = $stream;

        $meta = stream_get_meta_data($stream);
        $this->seekable = $meta['seekable'];
        $this->readable = ! empty(self::$readWriteHash['read'][$meta['mode']]);
        $this->writable = ! empty(self::$readWriteHash['write'][$meta['mode']]);
        $this->uri = isset($meta['uri']) ? $meta['uri'] : null;
    }

    /**
     * Reads all data from the stream into a string, from the beginning to end.
     *
     * This method MUST attempt to seek to the beginning of the stream before
     * reading data and read the stream until the end is reached.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * This method MUST NOT raise an exception in order to conform with PHP's
     * string casting operations.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     * @return string
     */
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

    /**
     * Closes the stream and any underlying resources.
     *
     * @return void
     */
    public function close()
    {
        if (isset($this->stream)) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            $this->detach();
        }
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach()
    {
        if (! isset($this->stream)) {
            return;
        }

        $result = $this->stream;

        $this->stream = $this->size = $this->uri = null;
        $this->readable = $this->writable = $this->seekable = false;

        return $result;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize()
    {
        if (! $this->stream) {
            return;
        }

        // Clear the stat cache if the stream has a URI
        if ($this->uri) {
            clearstatcache(true, $this->uri);
        }

        $stats = fstat($this->stream);

        return isset($stats['size']) ? $stats['size'] : null;
    }

    /**
     * Returns the current position of the file read/write pointer.
     *
     * @throws \RuntimeException on error.
     * @return int               Position of the file pointer
     */
    public function tell()
    {
        $result = ftell($this->stream);

        if ($result === false) {
            throw new \RuntimeException('Unable to determine stream position');
        }

        return $result;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof()
    {
        return ! $this->stream || feof($this->stream);
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable()
    {
        return $this->seekable;
    }

    /**
     * Seek to a position in the stream.
     *
     * @link http://www.php.net/manual/en/function.fseek.php
     * @param  int               $offset Stream offset
     * @param  int               $whence Specifies how the cursor position will be calculated
     *                                   based on the seek offset. Valid values are identical to the built-in
     *                                   PHP $whence values for `fseek()`.  SEEK_SET: Set position equal to
     *                                   offset bytes SEEK_CUR: Set position to current location plus offset
     *                                   SEEK_END: Set position to end-of-stream plus offset.
     * @throws \RuntimeException on failure.
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if (! $this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable');
        }

        if (fseek($this->stream, $offset, $whence) == -1) {
            throw new \RuntimeException(sprintf('Unable to seek to stream'
                .' position %d with whence %s', $offset, var_export($whence, true)));
        }
    }

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @see seek()
     * @link http://www.php.net/manual/en/function.fseek.php
     * @throws \RuntimeException on failure.
     */
    public function rewind()
    {
        $this->seek(0);
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable()
    {
        return $this->writable;
    }

    /**
     * Write data to the stream.
     *
     * @param  string            $string The string that is to be written.
     * @throws \RuntimeException on failure.
     * @return int               Returns the number of bytes written to the stream.
     */
    public function write($string)
    {
        if (! $this->writable) {
            throw new \RuntimeException('Cannot write to a non-writable stream');
        }

        $this->size = null;
        $result = fwrite($this->stream, $string);

        if ($result === false) {
            throw new \RuntimeException('Unable to write to stream');
        }

        return $result;
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable()
    {
        return $this->readable;
    }

    /**
     * Read data from the stream.
     *
     * @param  int               $length Read up to $length bytes from the object and return
     *                                   them. Fewer than $length bytes may be returned if underlying stream
     *                                   call returns fewer bytes.
     * @throws \RuntimeException if an error occurs.
     * @return string            Returns the data read from the stream, or an empty string
     *                                  if no bytes are available.
     */
    public function read($length)
    {
        if (! $this->readable) {
            throw new \RuntimeException('Unable to read non-readable stream');
        }

        return fread($this->stream, $length);
    }

    /**
     * Returns the remaining contents in a string.
     *
     * @throws \RuntimeException if unable to read or an error occurs while
     *                           reading.
     * @return string
     */
    public function getContents()
    {
        return stream_get_contents($this->stream);
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @link http://php.net/manual/en/function.stream-get-meta-data.php
     * @param  string           $key Specific metadata to retrieve.
     * @return array|mixed|null Returns an associative array if no key is
     *                              provided. Returns a specific key value if a key is provided and the
     *                              value is found, or null if the key is not found.
     */
    public function getMetadata($key = null)
    {
        if (! isset($this->stream)) {
            return $key ? null : array();
        }
        if (! $key) {
            return stream_get_meta_data($this->stream);
        }

        $meta = stream_get_meta_data($this->stream);

        return isset($meta[$key]) ? $meta[$key] : null;
    }
}
