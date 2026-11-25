<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/armory.php';

$characterSlugs = require __DIR__ . '/characters.php';
if (!is_array($characterSlugs)) {
    error_log('Armory cache refresh failed: characters.php did not return an array.');
    exit(1);
}

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'forever-friends-armory-refresh.lock';
$lockFile = fopen($lockPath, 'c');
if ($lockFile === false) {
    error_log('Armory cache refresh failed: could not open the refresh lock file.');
    exit(1);
}

if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    fclose($lockFile);
    error_log('Armory cache refresh skipped because another refresh is running.');
    exit(0);
}

$failed = false;
$slugs = [];
foreach ($characterSlugs as $key => $entry) {
    if (is_array($entry) && isset($entry['slug']) && is_string($entry['slug'])) {
        $slug = $entry['slug'];
    } elseif (is_string($entry) && is_int($key)) {
        $slug = $entry;
    } elseif (is_string($key) && is_string($entry)) {
        $slug = $key;
    } else {
        error_log('Armory cache refresh skipped an invalid roster entry.');
        $failed = true;
        continue;
    }

    if (!preg_match('/\A[a-z0-9-]+\z/', $slug)) {
        error_log('Armory cache refresh skipped an invalid character slug.');
        $failed = true;
        continue;
    }

    $slugs[$slug] = true;
}

foreach (array_keys($slugs) as $slug) {
    $result = fetchArmoryCharacter($slug);
    if ($result['character'] === null) {
        error_log('Armory cache refresh failed for character slug: ' . $slug);
        $failed = true;
        continue;
    }

    $cacheData = json_encode(
        ['fetched_at' => $result['fetched_at'], 'character' => $result['character']],
        JSON_UNESCAPED_UNICODE
    );
    if ($cacheData === false) {
        error_log('Armory cache refresh could not encode character data for slug: ' . $slug);
        $failed = true;
        continue;
    }

    $cachePath = armoryCharacterCachePath($slug);
    $temporaryPath = tempnam(dirname($cachePath), basename($cachePath) . '.tmp-');
    if ($temporaryPath === false) {
        error_log('Armory cache refresh could not create a temporary cache file for slug: ' . $slug);
        $failed = true;
        continue;
    }

    $written = file_put_contents($temporaryPath, $cacheData, LOCK_EX);
    if ($written !== strlen($cacheData) || !rename($temporaryPath, $cachePath)) {
        @unlink($temporaryPath);
        error_log('Armory cache refresh could not save character data for slug: ' . $slug);
        $failed = true;
    }
}

flock($lockFile, LOCK_UN);
fclose($lockFile);

exit($failed ? 1 : 0);
