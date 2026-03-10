<?php
require_once __DIR__ . '/file_crypto.php';

if (!function_exists('archive_uploads_dir')) {
    function archive_uploads_dir() {
        return __DIR__ . '/../uploads/archive';
    }
}

if (!function_exists('archive_find_file_path')) {
    function archive_find_file_path($id) {
        $dir = archive_uploads_dir();
        if (!is_dir($dir)) {
            return null;
        }
        $encMatches = glob($dir . '/' . $id . '_*.enc');
        if (!empty($encMatches)) {
            sort($encMatches);
            return $encMatches[0];
        }
        $legacyMatches = glob($dir . '/' . $id . '.*');
        if (!empty($legacyMatches)) {
            sort($legacyMatches);
            return $legacyMatches[0];
        }
        return null;
    }
}

if (!function_exists('archive_delete_existing_files')) {
    function archive_delete_existing_files($id, $excludePath = null) {
        $dir = archive_uploads_dir();
        if (!is_dir($dir)) {
            return;
        }
        $excludeReal = ($excludePath && file_exists($excludePath)) ? realpath($excludePath) : null;
        foreach (glob($dir . '/' . $id . '_*.enc') as $path) {
            if ($excludeReal && realpath($path) === $excludeReal) {
                continue;
            }
            @unlink($path);
        }
        foreach (glob($dir . '/' . $id . '.*') as $path) {
            if (substr($path, -4) === '.enc') {
                continue;
            }
            if ($excludeReal && realpath($path) === $excludeReal) {
                continue;
            }
            @unlink($path);
        }
    }
}

if (!function_exists('archive_guess_extension_from_path')) {
    function archive_guess_extension_from_path($path) {
        if (!$path) {
            return null;
        }
        $base = basename($path);
        $ext = file_crypto_infer_original_extension($base);
        if ($ext) {
            return strtolower($ext);
        }
        $rawExt = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($rawExt === '' || $rawExt === 'enc') {
            return null;
        }
        return $rawExt;
    }
}
