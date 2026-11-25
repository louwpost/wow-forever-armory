<?php

function isValidArmoryCharacter($character): bool
{
    if (
        !is_array($character)
        || !isset($character['name'], $character['slug'], $character['race'], $character['class'], $character['level'], $character['faction'], $character['ruleset'])
        || !array_key_exists('specialization', $character)
        || !is_array($character['professions'] ?? null)
    ) {
        return false;
    }

    if ($character['specialization'] !== null && !is_string($character['specialization'])) {
        return false;
    }

    foreach ($character['professions'] as $profession) {
        if (
            !is_array($profession)
            || !isset($profession['name'], $profession['tiers'])
            || !is_string($profession['name'])
            || !is_array($profession['tiers'])
        ) {
            return false;
        }
        foreach ($profession['tiers'] as $tier) {
            if (
                !is_array($tier)
                || !isset($tier['name'], $tier['skill_points'], $tier['max_skill_points'])
                || !is_string($tier['name'])
                || !is_int($tier['skill_points'])
                || !is_int($tier['max_skill_points'])
                || $tier['skill_points'] < 0
                || $tier['max_skill_points'] <= 0
            ) {
                return false;
            }
        }
    }

    return is_string($character['name'])
        && is_string($character['slug'])
        && is_string($character['race'])
        && is_string($character['class'])
        && is_numeric($character['level'])
        && in_array($character['faction'], ['Alliance', 'Horde'], true)
        && is_string($character['ruleset']);
}

function armoryCharacterCachePath(string $slug): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'forever-friends-armory-' . hash('sha256', $slug) . '.json';
}

function loadArmoryCharacter(string $slug): array
{
    if (!preg_match('/\A[a-z0-9-]+\z/', $slug)) {
        error_log('Invalid character slug in characters.php.');
        return ['character' => null, 'error' => 'This character has an invalid armory slug.'];
    }

    $cachePath = armoryCharacterCachePath($slug);
    if (is_file($cachePath)) {
        $cacheContents = @file_get_contents($cachePath);
        if ($cacheContents === false) {
            error_log('Could not read cached Blizzard armory profile for slug: ' . $slug);
        } else {
            $cache = json_decode($cacheContents, true);
            $cacheAge = is_array($cache)
                && isset($cache['fetched_at'])
                && is_numeric($cache['fetched_at'])
                ? time() - (int) $cache['fetched_at']
                : -1;
            if (
                is_array($cache)
                && isset($cache['character'])
                && $cacheAge >= 0
                && isValidArmoryCharacter($cache['character'])
            ) {
                return [
                    'character' => $cache['character'],
                    'error' => null,
                    'fetched_at' => (int) $cache['fetched_at'],
                ];
            }

            error_log('Ignoring invalid cached armory data for slug: ' . $slug);
        }
    }

    return [
        'character' => null,
        'error' => 'Cached Blizzard armory data is not available. Please try again later.',
    ];
}

function fetchArmoryCharacter(string $slug): array
{
    if (!preg_match('/\A[a-z0-9-]+\z/', $slug)) {
        error_log('Invalid character slug in characters.php.');
        return ['character' => null, 'error' => 'This character has an invalid armory slug.'];
    }

    $url = 'https://worldofwarcraft.blizzard.com/en-us/forever/eu/armory/character/' . $slug;
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "Accept: text/html\r\nUser-Agent: ForeverFriendsRoster/1.0\r\n",
        ],
    ]);

    error_clear_last();
    $html = @file_get_contents($url, false, $context);
    if ($html === false) {
        $lastError = error_get_last();
        $reason = $lastError !== null ? $lastError['message'] : 'Unknown network error';
        error_log('Could not fetch Blizzard armory profile for ' . $slug . ': ' . $reason);
        return ['character' => null, 'error' => 'Blizzard armory data could not be loaded. Please try again later.'];
    }

    $statusCode = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $statusMatch)) {
        $statusCode = (int) $statusMatch[1];
    }
    if ($statusCode !== 200) {
        error_log('Blizzard armory returned HTTP ' . $statusCode . ' for character slug: ' . $slug);
        return ['character' => null, 'error' => 'Blizzard armory data could not be loaded. Please try again later.'];
    }

    if (
        !preg_match(
            '~<script\b[^>]*\bid=["\']character-profile-mount-initial-state["\'][^>]*>(.*?)</script>~is',
            $html,
            $scriptMatch
        )
        || !preg_match('/characterProfileInitialState\s*=\s*(\{.*\});?/s', $scriptMatch[1], $stateMatch)
    ) {
        error_log('Could not find character profile data in Blizzard armory page for slug: ' . $slug);
        return ['character' => null, 'error' => 'Blizzard armory data could not be read. Please try again later.'];
    }

    $state = json_decode($stateMatch[1], true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($state['character']) || !is_array($state['character'])) {
        error_log('Could not decode character profile data for slug ' . $slug . ': ' . json_last_error_msg());
        return ['character' => null, 'error' => 'Blizzard armory data could not be read. Please try again later.'];
    }

    $profile = $state['character'];
    $name = isset($profile['name']) && is_string($profile['name']) ? trim($profile['name']) : '';
    $surname = isset($profile['surname']) && is_string($profile['surname']) ? trim($profile['surname']) : '';
    $race = $profile['race']['name'] ?? null;
    $class = $profile['class']['name'] ?? null;
    $level = $profile['level'] ?? null;
    $faction = $profile['faction']['name'] ?? null;
    $ruleset = $profile['ruleset']['name'] ?? null;
    $ruleset = is_string($ruleset) ? trim($ruleset) : '';
    $specialization = null;
    if (isset($profile['specs']) && is_array($profile['specs'])) {
        foreach ($profile['specs'] as $spec) {
            if (
                is_array($spec)
                && ($spec['active'] ?? false) === true
                && isset($spec['name'])
                && is_string($spec['name'])
            ) {
                $specName = trim($spec['name']);
                if ($specName !== '') {
                    $specialization = $specName;
                    break;
                }
            }
        }
    }

    $professions = [];
    if (isset($profile['professions']) && is_array($profile['professions'])) {
        foreach ($profile['professions'] as $professionGroup) {
            if (!is_array($professionGroup)) {
                continue;
            }
            foreach ($professionGroup as $profession) {
                if (is_array($profession) && isset($profession['name']) && is_string($profession['name'])) {
                    $professionName = trim($profession['name']);
                    if ($professionName !== '') {
                        $tiers = [];
                        if (isset($profession['tiers']) && is_array($profession['tiers'])) {
                            foreach ($profession['tiers'] as $tier) {
                                if (
                                    !is_array($tier)
                                    || !isset($tier['skillPoints'], $tier['maxSkillPoints'])
                                    || !is_numeric($tier['skillPoints'])
                                    || !is_numeric($tier['maxSkillPoints'])
                                ) {
                                    continue;
                                }
                                $skillPoints = (int) $tier['skillPoints'];
                                $maxSkillPoints = (int) $tier['maxSkillPoints'];
                                if ($skillPoints < 0 || $maxSkillPoints <= 0) {
                                    continue;
                                }
                                $tierName = isset($tier['name']) && is_string($tier['name'])
                                    ? trim($tier['name'])
                                    : '';
                                $tiers[] = [
                                    'name' => $tierName !== '' ? $tierName : $professionName,
                                    'skill_points' => $skillPoints,
                                    'max_skill_points' => $maxSkillPoints,
                                ];
                            }
                        }
                        $professions[] = ['name' => $professionName, 'tiers' => $tiers];
                    }
                }
            }
        }
    }

    if (
        $name === ''
        || !is_string($race)
        || !is_string($class)
        || !is_numeric($level)
        || !is_string($faction)
    ) {
        error_log('Blizzard armory profile is missing roster fields for slug: ' . $slug);
        return ['character' => null, 'error' => 'Blizzard armory data could not be read. Please try again later.'];
    }

    $character = [
        'name' => trim($name . ' ' . $surname),
        'slug' => $slug,
        'race' => $race,
        'class' => $class,
        'level' => (int) $level,
        'faction' => $faction,
        'ruleset' => $ruleset,
        'specialization' => $specialization,
        'professions' => $professions,
    ];

    if (!isValidArmoryCharacter($character)) {
        error_log('Blizzard armory profile has invalid roster fields for slug: ' . $slug);
        return ['character' => null, 'error' => 'Blizzard armory data could not be read. Please try again later.'];
    }

    return ['character' => $character, 'error' => null, 'fetched_at' => time()];
}
