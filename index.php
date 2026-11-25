<?php
require __DIR__ . '/armory.php';

$characterSlugs = require __DIR__ . '/characters.php';
$characters = [];
$loadErrors = [];
$oldestArmoryFetchedAt = null;
$rosterMessage = null;
$rosterMessageType = 'success';
$realNamesBySlug = [];
$rosterEntries = [];

foreach ($characterSlugs as $key => $entry) {
    if (is_array($entry) && isset($entry['slug']) && is_string($entry['slug'])) {
        $slug = $entry['slug'];
        $realName = $entry['real_name'] ?? '';
    } elseif (is_string($entry) && is_int($key)) {
        $slug = $entry;
        $realName = '';
    } elseif (is_string($key) && preg_match('/\A[a-z0-9-]+\z/', $key) && is_string($entry)) {
        $slug = $key;
        $realName = $entry;
    } else {
        $rosterEntries[] = null;
        continue;
    }

    if (preg_match('/\A[a-z0-9-]+\z/', $slug) && is_string($realName)) {
        $realName = trim($realName);
        $rosterEntries[] = ['real_name' => $realName, 'slug' => $slug];
        $realNamesBySlug[$slug] = $realName;
    } else {
        $rosterEntries[] = null;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $inputValue = $_POST['character'] ?? '';
    $realNameValue = $_POST['real_name'] ?? '';
    $invalidInput = !is_string($inputValue) || !is_string($realNameValue);
    $input = is_string($inputValue) ? trim($inputValue) : '';
    $realName = is_string($realNameValue) ? trim($realNameValue) : '';

    if (filter_var($input, FILTER_VALIDATE_URL)) {
        $path = parse_url($input, PHP_URL_PATH);
        $parts = is_string($path) ? array_values(array_filter(explode('/', $path))) : [];
        $input = strtolower(rtrim((string) end($parts), '/'));
    }

    $slug = strtolower(trim($input));
    $realName = preg_replace('/\s+/u', ' ', $realName) ?? '';
    if ($invalidInput) {
        $rosterMessage = 'Enter a valid first name and character slug or Armory profile URL.';
        $rosterMessageType = 'error';
    } elseif (strlen($slug) > 100 || !preg_match('/\A[a-z0-9-]+\z/', $slug)) {
        $rosterMessage = 'Enter a valid character slug or Armory profile URL.';
        $rosterMessageType = 'error';
    } elseif ($realName === '' || strlen($realName) > 200) {
        $rosterMessage = 'Enter the character owner\'s real first name.';
        $rosterMessageType = 'error';
    } elseif (array_key_exists($slug, $realNamesBySlug)) {
        $rosterMessage = 'That character is already on the roster.';
        $rosterMessageType = 'error';
    } else {
        $rosterEntries[] = ['real_name' => $realName, 'slug' => $slug];
        $contents = "<?php\n\nreturn " . var_export($rosterEntries, true) . ";\n";
        $rosterFile = fopen(__DIR__ . '/characters.php', 'c');

        if ($rosterFile === false) {
            array_pop($rosterEntries);
            $rosterMessage = 'The roster could not be updated. Please try again.';
            $rosterMessageType = 'error';
        } else {
            if (!flock($rosterFile, LOCK_EX)) {
                fclose($rosterFile);
                array_pop($rosterEntries);
                $rosterMessage = 'The roster could not be updated. Please try again.';
                $rosterMessageType = 'error';
            } else {
                ftruncate($rosterFile, 0);
                rewind($rosterFile);
                $written = fwrite($rosterFile, $contents);
                $flushed = fflush($rosterFile);
                flock($rosterFile, LOCK_UN);
                fclose($rosterFile);

                if ($written !== strlen($contents) || !$flushed) {
                    array_pop($rosterEntries);
                    $rosterMessage = 'The roster could not be updated. Please try again.';
                    $rosterMessageType = 'error';
                } else {
                    $realNamesBySlug[$slug] = $realName;
                    $rosterMessage = 'Character added to the roster.';
                }
            }
        }
    }
}

foreach ($rosterEntries as $entry) {
    if ($entry === null) {
        $loadErrors[] = [
            'slug' => 'Unknown character',
            'message' => 'The roster contains an invalid entry.',
        ];
        error_log('Invalid entry in characters.php; expected a character slug.');
        continue;
    }

    $slug = $entry['slug'];
    $result = loadArmoryCharacter($slug);
    if ($result['character'] !== null) {
        $fetchedAt = $result['fetched_at'];
        if ($oldestArmoryFetchedAt === null || $fetchedAt < $oldestArmoryFetchedAt) {
            $oldestArmoryFetchedAt = $fetchedAt;
        }
        $result['character']['real_name'] = $realNamesBySlug[$slug] ?? '';
        $characters[] = $result['character'];
    } else {
        $loadErrors[] = ['slug' => $slug, 'message' => $result['error']];
    }
}

usort($characters, static function (array $first, array $second): int {
    return strnatcasecmp($first['name'], $second['name']);
});

$armoryCacheAge = null;
if ($oldestArmoryFetchedAt !== null) {
    $ageInSeconds = max(0, time() - $oldestArmoryFetchedAt);
    if ($ageInSeconds < 60) {
        $armoryCacheAge = 'just now';
    } elseif ($ageInSeconds < 3600) {
        $armoryCacheAge = floor($ageInSeconds / 60) . 'm ago';
    } else {
        $armoryCacheAge = floor($ageInSeconds / 3600) . 'h ago';
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$factionCounts = ['Alliance' => 0, 'Horde' => 0];
foreach ($characters as $character) {
    if (isset($factionCounts[$character['faction']])) {
        $factionCounts[$character['faction']]++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101311">
    <title>Forever Friends — Character Roster</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <main class="page-shell">
        <header class="masthead">
            <a class="brand" href="#" aria-label="Forever Friends home">
                <span class="brand-mark" aria-hidden="true">F</span>
                <span>FOREVER <span class="brand-muted">FRIENDS</span></span>
            </a>
            <span class="realm-label"><span class="status-dot"></span> WoW Forever · EU</span>
        </header>

        <section class="intro" aria-labelledby="page-title">
            <p class="eyebrow">THE GUILD ROSTER</p>
            <h1 id="page-title">Our characters<span class="title-period">.</span></h1>
            <p class="intro-copy">A little home for the heroes we take into Azeroth together.</p>
        </section>

        <section class="roster-summary" aria-label="Roster summary and faction filters">
            <div class="summary-total">
                <span class="summary-number"><?= count($characters) ?></span>
                <span class="summary-label">CHARACTERS</span>
            </div>
            <div class="summary-factions">
                <button class="faction-count faction-filter alliance-count" type="button"
                        data-faction-filter="alliance" aria-pressed="false">
                    <img class="mini-crest" src="images/alliance.png" alt="" aria-hidden="true">
                    Alliance <strong><?= $factionCounts['Alliance'] ?></strong>
                </button>
                <span class="summary-divider" aria-hidden="true"></span>
                <button class="faction-count faction-filter horde-count" type="button"
                        data-faction-filter="horde" aria-pressed="false">
                    <img class="mini-crest" src="images/horde.png" alt="" aria-hidden="true">
                    Horde <strong><?= $factionCounts['Horde'] ?></strong>
                </button>
            </div>
        </section>
        <p id="filter-status" class="sr-only" aria-live="polite"></p>

        <?php if ($characters): ?>
            <section id="character-grid" class="character-grid" aria-label="Characters">
                <?php foreach ($characters as $character): ?>
                    <?php
                    $isAlliance = $character['faction'] === 'Alliance';
                    $factionClass = $isAlliance ? 'alliance' : 'horde';
                    $factionLogo = $isAlliance ? 'images/alliance.png' : 'images/horde.png';
                    $realName = $character['real_name'] ?? '';
                    $armoryUrl = 'https://worldofwarcraft.blizzard.com/en-us/forever/eu/armory/character/'
                        . rawurlencode($character['slug']);
                    ?>
                    <details class="character-card <?= $factionClass ?>">
                        <summary class="card-summary">
                            <div class="card-topline">
                                <span class="faction-badge">
                                    <img class="faction-crest" src="<?= escape($factionLogo) ?>" alt="" aria-hidden="true">
                                    <?= escape($character['faction']) ?>
                                </span>
                                <span class="level-badge">LVL <strong><?= (int) $character['level'] ?></strong></span>
                            </div>
                            <div class="character-info">
                                <?php if ($realName !== ''): ?>
                                    <p class="character-owner"><?= escape($realName) ?></p>
                                <?php endif; ?>
                                <div class="character-name">
                                    <h2><?= escape($character['name']) ?></h2>
                                    <?php if ($character['ruleset'] !== ''): ?>
                                        <span class="ruleset-badge"><?= escape($character['ruleset']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p>
                                    <?= escape($character['race']) ?><span class="info-separator">·</span><?= escape($character['class']) ?>
                                </p>
                            </div>
                            <div class="card-footer">
                                <span>VIEW DETAILS</span>
                                <span class="card-toggle-icon" aria-hidden="true">⌄</span>
                            </div>
                        </summary>
                        <div class="character-details">
                            <dl class="character-detail-list">
                                <div class="character-detail-row">
                                    <dt>Level</dt>
                                    <dd><?= (int) $character['level'] ?></dd>
                                </div>
                                <div class="character-detail-row">
                                    <dt>Active specialization</dt>
                                    <dd><?= $character['specialization'] !== null ? escape($character['specialization']) : 'Not available' ?></dd>
                                </div>
                                <div class="character-detail-row">
                                    <dt>Professions</dt>
                                    <dd>
                                        <?php if ($character['professions']): ?>
                                            <div class="profession-list">
                                                <?php foreach ($character['professions'] as $profession): ?>
                                                    <div class="profession-entry">
                                                        <?php if ($profession['tiers']): ?>
                                                            <?php foreach ($profession['tiers'] as $tier): ?>
                                                                <div class="profession-heading">
                                                                    <span class="profession-name">
                                                                        <?= escape($profession['name']) ?>
                                                                        <?php if ($tier['name'] !== $profession['name']): ?>
                                                                            <span class="profession-tier-name">· <?= escape($tier['name']) ?></span>
                                                                        <?php endif; ?>
                                                                    </span>
                                                                    <span class="profession-fraction">
                                                                        <?= $tier['skill_points'] ?> / <?= $tier['max_skill_points'] ?>
                                                                    </span>
                                                                </div>
                                                                <progress class="profession-progress"
                                                                          max="<?= $tier['max_skill_points'] ?>"
                                                                          value="<?= min($tier['skill_points'], $tier['max_skill_points']) ?>"
                                                                          aria-label="<?= escape($profession['name'] . ' ' . $tier['name']) ?>"></progress>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <div class="profession-heading">
                                                                <span class="profession-name"><?= escape($profession['name']) ?></span>
                                                                <span class="profession-unavailable">Level unavailable</span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            None listed
                                        <?php endif; ?>
                                    </dd>
                                </div>
                            </dl>
                            <a class="armory-link" href="<?= escape($armoryUrl) ?>"
                               target="_blank" rel="noopener noreferrer">
                                <span>VIEW ARMORY PROFILE</span>
                                <span class="arrow" aria-hidden="true">↗</span>
                            </a>
                        </div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php elseif ($characterSlugs): ?>
            <section class="empty-state" aria-labelledby="empty-title">
                <div class="empty-emblem" aria-hidden="true">!</div>
                <h2 id="empty-title">The roster couldn’t load</h2>
                <p>Character details are retrieved from the Blizzard armory. Please try again shortly.</p>
            </section>
        <?php else: ?>
            <section class="empty-state" aria-labelledby="empty-title">
                <div class="empty-emblem" aria-hidden="true">✦</div>
                <h2 id="empty-title">Your roster starts here</h2>
                <p>Add character slugs from their armory URLs to <code>characters.php</code> to see them listed here.</p>
            </section>
        <?php endif; ?>

        <?php if ($loadErrors): ?>
            <aside class="load-errors" aria-label="Characters that could not be loaded">
                <strong><?= $characters ? 'Some characters couldn’t be loaded:' : 'Character loading errors:' ?></strong>
                <?php foreach ($loadErrors as $loadError): ?>
                    <p><code><?= escape($loadError['slug']) ?></code> — <?= escape($loadError['message']) ?></p>
                <?php endforeach; ?>
            </aside>
        <?php endif; ?>

        <section class="add-character" aria-labelledby="add-character-title">
            <div>
                <p class="eyebrow">JOIN THE ROSTER</p>
                <h2 id="add-character-title">Add your character</h2>
            </div>
            <form class="character-form" method="post">
                <label class="sr-only" for="real_name">Real first name</label>
                <input id="real_name" name="real_name" type="text"
                       placeholder="Real first name"
                       autocomplete="given-name" maxlength="50" required>
                <label class="sr-only" for="character">Character slug or Armory URL</label>
                <input id="character" name="character" type="text"
                       placeholder="Character slug or Armory URL"
                       autocomplete="off" maxlength="250" required>
                <button type="submit">Add character</button>
            </form>
            <?php if ($rosterMessage): ?>
                <p class="roster-message <?= $rosterMessageType ?>" role="status">
                    <?= escape($rosterMessage) ?>
                </p>
            <?php endif; ?>
        </section>

        <footer class="page-footer">
            <span>Made for friends, across Azeroth.</span>
            <span>Character details sync from the Blizzard armory.</span>
            <?php if ($armoryCacheAge !== null): ?>
                <span>Armory cached <?= escape($armoryCacheAge) ?>. Refreshes at midnight.</span>
            <?php endif; ?>
        </footer>
    </main>
    <script>
        const factionFilters = document.querySelectorAll('[data-faction-filter]');
        const characterCards = document.querySelectorAll('.character-card');
        const filterStatus = document.getElementById('filter-status');
        let activeFaction = null;

        factionFilters.forEach((filter) => {
            filter.addEventListener('click', () => {
                const selectedFaction = filter.dataset.factionFilter;
                activeFaction = activeFaction === selectedFaction ? null : selectedFaction;

                factionFilters.forEach((button) => {
                    button.setAttribute(
                        'aria-pressed',
                        String(button.dataset.factionFilter === activeFaction)
                    );
                });

                let visibleCount = 0;
                characterCards.forEach((card) => {
                    const isVisible = activeFaction === null || card.classList.contains(activeFaction);
                    card.hidden = !isVisible;
                    if (isVisible) {
                        visibleCount++;
                    }
                });

                if (filterStatus) {
                    filterStatus.textContent = activeFaction === null
                        ? 'Showing all characters.'
                        : `Showing ${visibleCount} ${activeFaction === 'alliance' ? 'Alliance' : 'Horde'} characters.`;
                }
            });
        });
    </script>
</body>
</html>
