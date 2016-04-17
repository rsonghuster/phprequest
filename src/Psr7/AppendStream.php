<?php

/**
 * This class refers to some parts of Guzzle
 */
namespace EasyRequest\Psr7;

use Psr\Http\Message\StreamInterface;

class AppendStream implements StreamInterface
{
    private $streams = array();
    private $seekable = true;
    private $position = 0;
    private $current = 0;

    /**
     * @param \Psr\Http\Message\StreamInterface[] $streams
     */
    public function __construct(array $streams = array())
    {
        foreach ($streams as $stream) {
            $this->add($stream);
        }
    }

    /**
     * Append stream to stack.
     *
     * @param  StreamInterface $stream
     * @return self
     */
    public function add(StreamInterface $stream)
    {
        if (! $stream->isReadable()) {
            throw new \InvalidArgumentException('Each stream must be readable');
        }
        $this->seekable = $stream->isSeekable() && $this->seekable;

        $this->streams[] = $stream;

        return $this;
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
            $this->rewind();

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
        $this->position = $this->current = 0;

        foreach ($this->streams as $stream) {
            $stream->close();
        }

        $this->streams = array();
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
        $this->close();
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize()
    {
        $total = 0;
        foreach ($this->streams as $stream) {
            if (null === $size = $stream->getSize()) {
                return;
            }
            $total += $size;
        }

        return $total;
    }

    /**
     * Returns the current position of the file read/write pointer.
     *
     * @throws \RuntimeException on error.
     * @return int               Position of the file pointer
     */
    public function tell()
    {
        return $this->position;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof()
    {
        return ! $this->streams ||
            ($this->current >= count($this->streams) - 1
                && $this->streams[$this->current]->eof());
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
            throw new \RuntimeException('This AppendStream is not seekable');
        } elseif ($whence !== SEEK_SET) {
            throw new \RuntimeException('The AppendStream can only seek with SEEK_SET');
        }

        $this->position = $this->current = 0;

        // rewind each stream
        foreach ($this->streams as $i => $stream) {
            try {
                $stream->rewind();
            } catch (\Exception $e) {
                throw new \RuntimeException('Unable to seek stream '.$i.' of the AppendStream', 0, $e);
            }
        }

        // Seek to the actual position by reading from each stream
        while ($this->position < $offset && ! $this->eof()) {
            $result = $this->read(min(8192, $offset - $this->position));
            if ($result === '') {
                break;
            }
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
        return false;
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
        throw new \RuntimeException('The AppendStream is not writeable');
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable()
    {
        return true;
    }

    /**
     * Read data from the stream.
     *
     * @param  int               $length Read up to $length bytes from the object and return
     *                                   them. Fewer than $length bytes may be returned if underlying stream
     *                                   call returns fewer bytes.
     * @throws \RuntimeException if an error occurs.
     * @return string            Returns the data read from the stream, or an empty string
     *                                   if no bytes are available.
     */
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

    /**
     * Returns the remaining contents in a string.
     *
     * @throws \RuntimeException if unable to read or an error occurs while
     *                           reading.
     * @return string
     */
    public function getContents()
    {
        $contents = '';
        while (! $this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
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
        return $key ? null : array();
    }
}
