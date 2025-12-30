<?php
namespace PHPFrame;

class Strings
{
    /**
     * 下划线转驼峰
     *
     * @param $value
     * @return array|string|string[]
     */
    public static function camelcase($value)
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }

    /**
     * 随机字符串
     *
     * @param int $length
     * @param string $charlist
     * @return string
     * @throws \Random\RandomException
     */
    public static function rand(int $length = 10, string $charlist = '0-9a-z'): string
    {
        $charlist = count_chars(preg_replace_callback('#.-.#', function (array $m): string {
            return implode('', range($m[0][0], $m[0][2]));
        }, $charlist), 3);
        $chLen = strlen($charlist);

        if ($length < 1) {
            $length = 1;
        } elseif ($chLen < 2) {
            $chLen = 2;
        }

        $res = '';
        for ($i = 0; $i < $length; $i++) {
            $res .= $charlist[random_int(0, $chLen - 1)];
        }

        return $res;
    }

    /**
     * authcode
     * Authentication code encryption/decryption
     *
     * @param $string
     * @param $operation
     * @param $key
     * @param $expiry
     * @param $replaceEqual
     * @return string
     */
    public static function authcode($string, $operation = 'DECODE', $key = '', $expiry = 0, $replaceEqual = true)
    {

        $ckey_length = 4;

        $key = md5($key ? $key : 'AUTH_KEY');
        $keya = md5(substr($key, 0, 16));
        $keyb = md5(substr($key, 16, 16));
        $keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';

        $cryptkey = $keya . md5($keya . $keyc);
        $key_length = strlen($cryptkey);

        $string = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string . $keyb), 0, 16) . $string;
        $string_length = strlen($string);

        $result = '';
        $box = range(0, 255);

        $rndkey = array();
        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $key_length]);
        }

        for ($j = $i = 0; $i < 256; $i++) {
            $j = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }

        for ($a = $j = $i = 0; $i < $string_length; $i++) {
            $a = ($a + 1) % 256;
            $j = ($j + $box[$a]) % 256;
            $tmp = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }

        if ($operation == 'DECODE') {
            if (strlen($result) > 25 && (substr($result, 0, 10) == 0 || (int)substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26) . $keyb), 0, 16)) {
                return substr($result, 26);
            } else {
                return '';
            }
        } else {
            if ($replaceEqual === true) {
                return $keyc . str_replace('=', '', base64_encode($result));
            } else {
                return $keyc . base64_encode($result);
            }
        }
    }
}