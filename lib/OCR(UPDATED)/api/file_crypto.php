<?php
require_once __DIR__ . '/../config.php';

if (!function_exists('file_crypto_get_key')) {
    function file_crypto_get_key() {
        static $keyBin = null;
        if ($keyBin !== null) {
            return $keyBin;
        }
        $key = defined('FILE_ENC_KEY') ? FILE_ENC_KEY : '';
        if (ctype_xdigit($key) && strlen($key) === 64) {
            $keyBin = hex2bin($key);
        } elseif (strlen($key) === 32 && !ctype_xdigit($key)) {
            $keyBin = $key;
        } else {
            $keyBin = hash('sha256', (string)$key, true);
        }
        return $keyBin;
    }
}

if (!function_exists('file_crypto_encrypt_string')) {
    function file_crypto_encrypt_string($plaintext) {
        $cipher = defined('FILE_ENC_ALGO') ? FILE_ENC_ALGO : 'aes-256-gcm';
        $iv = random_bytes(12);
        $tag = '';
        $key = file_crypto_get_key();
        $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || $tag === '') {
            return false;
        }
        return 'ENC1' . $iv . $tag . $ciphertext;
    }
}

if (!function_exists('file_crypto_encrypt_stream_to_path')) {
    function file_crypto_encrypt_stream_to_path($sourcePath, $targetPath) {
        if (!is_readable($sourcePath)) {
            return false;
        }
        $raw = file_get_contents($sourcePath);
        if ($raw === false) {
            return false;
        }
        $payload = file_crypto_encrypt_string($raw);
        if ($payload === false) {
            return false;
        }
        if (@file_put_contents($targetPath, $payload) === false) {
            return false;
        }
        @unlink($sourcePath);
        return true;
    }
}

if (!function_exists('file_crypto_decrypt_blob')) {
    function file_crypto_decrypt_blob($blob) {
        if ($blob === '' || $blob === null) {
            return false;
        }
        if (strlen($blob) < 4 || substr($blob, 0, 4) !== 'ENC1') {
            // Legacy/plain file
            return $blob;
        }
        if (strlen($blob) < 32) {
            return false;
        }
        $offset = 4;
        $iv = substr($blob, $offset, 12);
        $offset += 12;
        $tag = substr($blob, $offset, 16);
        $offset += 16;
        $ciphertext = substr($blob, $offset);
        $cipher = defined('FILE_ENC_ALGO') ? FILE_ENC_ALGO : 'aes-256-gcm';
        $plain = openssl_decrypt($ciphertext, $cipher, file_crypto_get_key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain;
    }
}

if (!function_exists('file_crypto_infer_original_extension')) {
    function file_crypto_infer_original_extension($basename) {
        if ($basename === null) {
            return null;
        }
        if (substr($basename, -4) === '.enc') {
            $basename = substr($basename, 0, -4);
        }
        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        if ($ext === '' || $ext === 'enc') {
            return null;
        }
        return $ext;
    }
}
