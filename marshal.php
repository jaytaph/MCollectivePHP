<?php

/**
 *
 */
class MarshalException extends Exception {}

/**
 * VERY simple ruby marshal to php converter. Does not include every type available...
 *
 *
 */
class Marshal {
    // Must comply to this version
    const MAJOR_VERSION = 0x04;
    const MINOR_VERSION = 0x08;

    // Our defined types (there are more)
    const TYPE_NIL          = 0x30;
    const TYPE_STRING       = 0x22;
    const TYPE_SYMBOL       = 0x3A;
    const TYPE_ARRAY        = 0x5B;
    const TYPE_HASH         = 0x7B;
    const TYPE_BIGNUM       = 0x6C;

    // Data stream and pointer
    protected $_stream = "";
    protected $_streamPtr = 0;

    // Set our data stream
    protected function _setStream($stream) {
        $this->_stream = $stream;
        $this->_streamPtr = 0;
    }

    // Retrieve an ascii character
    protected function _getChar() {
        return chr($this->_getByte());
    }
    // Writes an ascii character
    protected function _setChar($c) {
        return $this->_setByte(ord($c[0]));
    }

    /**
     * Retrieves the next byte from the stream, or returns an exception when no more bytes are
     * available.
     *
     * @throws MarshalException
     * @return string Single byte (binary)
     */
    protected function _getByte() {
        if ($this->_streamPtr >= strlen($this->_stream)) {
            throw new MarshalException("Stream too short");
        }

        // Fetch single byte (binary)
        $tmp = unpack("C", $this->_stream[$this->_streamPtr++]);
        return $tmp[1];
    }

    // Writes a byte
    protected function _setByte($b) {
        return pack("C", $b);
    }

    /**
     * Longs are encoded through a mechanism I need to figure out.
     *
     * Longs (ints?) can be multibytes.
     */
    protected function _getLong() {
        $b = $this->_getByte();
        if ($b == 0) return 0;
        if ($b > 0) {
            if (4 < $b && $b < 128) return $b - 5;
            $x=0;
            for ($i=0; $i<$b;$i++) {
                $x |= $this->_getByte() << (8*$i);
            }
        } else {
            if (-129 < $b && $b < -4) return $b + 5;
            $x = -1;
            for ($i=0; $i<$b; $i++) {
                $x &= ~(0xff << (8*$i));
                $x |= $this->_getByte() << (8*$i);
            }
        }
        return $x;
    }
    // Decodes a long
    protected function _setLong($l) {
        $stream = "";
        if ($l == 0) {
            $stream .= $this->_setByte(0);
            return $stream;
        }
        if (0 < $l && $l < 123) {
            $stream .= $this->_setByte($l + 5);
            return $stream;
        }
        if (-124 < $l && $l < 0) {
            $stream .= $this->_setByte(($l - 5) & 0xff);
            return $stream;
        }

        $buf = array();
        for ($i=1; $i<8; $i++) {
            $buf[$i] = $l & 0xff;
            $l = $l << 8;
            if ($l == 0) {
                $buf[0] = $i;
                break;
            }
            if ($l == -1) {
                $buf[0] = -$i;
                break;
            }
        }
        $len = $i;
        for ($i=0; $i<$len;$i++) {
            $stream .= $this->_setByte(ord($buf[$i]));
        }
        return $stream;
    }

    /**
     * Return a big number (longs?)
     *
     * @return int
     */
    protected function _getBigNum() {
        // First char is the sign (+ for positive)
        $sign = $this->_getChar();
        // Length of the number (multiply by two)
        $length = $this->_getLong();

        // Byteshift and add
        $num = 0;
        $shift = 0;
        for ($i=0; $i!=$length*2; $i++) {
            $c = $this->_getByte();
            $num |= ($c << $shift);
            $shift += 8;
        }

        // Negate if needed (is this correct?)
        if ($sign != "+") {
            $num = 0 - $num;
        }

        return $num;
    }

    // Encode a bignum
    protected function _setBigNum($l) {
        $stream = "";
        $stream .= $this->_setChar( ($l >= 0) ? '+' : '-');

        if ($l <= 65535) {
            $tmp = pack("n", $l);
            $stream .= $this->_setLong(1);
        } else {
            $tmp = pack("N", $l);
            $stream .= $this->_setLong(2);
        }
        for ($i=0; $i<strlen($tmp); $i++) {
            $stream .= $this->_setChar($tmp[$i]);
        }
        return $stream;
    }

    /**
     * Loads one type from the stream (recursive function).
     *
     * @throws MarshalException
     * @return mixed
     */
    protected function _load() {
        switch ($this->_getByte()) {
            case self::TYPE_BIGNUM :
                $ret = $this->_getBigNum();
                break;
            case self::TYPE_SYMBOL :
            case self::TYPE_STRING :
                // We treat symbols and strings the same.
                $ret = "";
                $length = $this->_getLong();
                for ($i=0; $i!=$length; $i++) $ret .= $this->_getChar();
                break;
            
            case self::TYPE_ARRAY :
                // Haven't checked arrays yet
                $ret = array();
                $length = $this->_getLong();
                while ($length--) {
                    $ret[] = $this->_load();
                }
                break;

            case self::TYPE_HASH :
                // Most mcollective messages are stored as hashes
                $ret = array();
                // Fetch hash count
                $length = $this->_getLong();
                while ($length--) {
                    $key = $this->_load();
                    $value = $this->_load();
                    $ret[$key] = $value;
                }
                break;
            default :
                // Time to add your own if needed.
                throw new MarshalException("Incorrect type detected");
                break;
        }
        return $ret;
    }

    // Dumps a type. Can override the type (symbols for instance, are strings, but need a different
    // encoding type)
    function _dump($a, $type = null) {
        $stream = "";

        if (! $type) $type = gettype($a);
        switch ($type) {
            case "array" :
                // arrays are hashes.. sorry.. :|
                $stream .= $this->_setByte(self::TYPE_HASH);
                $stream .= $this->_setLong(count($a));
                foreach ($a as $k => $v) {
                    $stream .= $this->_dump((string)$k, "symbol");
                    $stream .= $this->_dump($v);
                }
                break;
            case "symbol" :
            case "string" :
                $stream .= $this->_setByte(($type == "symbol") ? self::TYPE_SYMBOL : self::TYPE_STRING);
                $stream .= $this->_setLong(strlen($a));
                for ($i=0; $i!=strlen($a); $i++) {
                    $stream .= $this->_setChar($a[$i]);
                }
                break;
            case "integer" :
            case "double" :
                $stream .= $this->_setByte(self::TYPE_BIGNUM);
                $stream .= $this->_setBigNum($a);
                break;
        }
        return $stream;
    }

    /**
     * Converts a marshal string into an array
     *
     * @throws MarshalException
     * @param $stream
     * @return mixed
     */
    public function load($stream) {
        // Set data stream
        $this->_setStream($stream);

        // Check if major and minor versions are correct
        $major = $this->_getByte();
        $minor = $this->_getByte();
        if ($major != self::MAJOR_VERSION && $minor != self::MINOR_VERSION) {
            throw new MarshalException("Incorrect version number");
        }

        // Decode the stream
        return $this->_load();
    }

    /**
     * Converts an array into a marshal string
     * 
     * @param $a
     * @return void
     */
    public function dump($a) {
        $stream = "";
        $stream .= $this->_setByte(self::MAJOR_VERSION);
        $stream .= $this->_setByte(self::MINOR_VERSION);
        $stream .= $this->_dump($a);
        return $stream;
    }

} // end class