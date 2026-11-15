<?php
// polyfill مبسط لدوال mbstring المش موجودة في php-wasm
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
    function mb_strtoupper($s, $enc = null) { return strtoupper((string)$s); }
    function mb_substr($s, $start, $len = null, $enc = null) { return $len === null ? substr((string)$s, $start) : substr((string)$s, $start, $len); }
    function mb_strlen($s, $enc = null) { return strlen((string)$s); }
    function mb_stripos($h, $n, $off = 0, $enc = null) { return stripos((string)$h, (string)$n, $off); }
    function mb_strpos($h, $n, $off = 0, $enc = null) { return strpos((string)$h, (string)$n, $off); }
}
if (!function_exists('mb_internal_encoding')) { function mb_internal_encoding($e = null) { return 'UTF-8'; } }
