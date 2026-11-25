# Forever Friends Armory

A small, shared World of Warcraft armory for friends who want to keep track of each other and preserve their group roster for the adventures to come.

Forever Friends displays character names, owners, factions, levels, races, classes, active specializations, professions, and links to the official Blizzard Armory. The page is designed as a guild-style home for the heroes that the group plays across Azeroth.

## Features

- Shared roster of friends and their World of Warcraft characters
- Alliance and Horde faction filters
- Expandable character cards with level, specialization, and profession details
- Direct links to Blizzard's official character profiles
- Form for adding characters from a slug or Armory URL
- Cached Armory data with a daily CLI refresh
- Responsive dark interface styled for a permanent friends' guild

## Character data

The project is currently configured for the **World of Warcraft EU `Forever`** realm.

Character pages can be supplied either as a full Armory URL or as a character slug:

```text
https://worldofwarcraft.blizzard.com/en-us/forever/eu/armory/character/stroopwafel-man
```

The slug is:

```text
stroopwafel-man
```

The roster is stored in `characters.php`. Each entry connects a friend's real first name with a character slug:

```php
<?php

return [
    [
        'real_name' => 'Louw',
        'slug' => 'stroopwafel-man',
    ],
];
```

Characters can also be added through the form on the page. The web server needs write permission for `characters.php` for this feature to work.

## Requirements

- PHP 7.4 or newer
- PHP support for file locking and atomic file operations
- A network connection to fetch Blizzard Armory pages
- A web server capable of running PHP

PHP's built-in web server is sufficient for local use.

## Armory cache

The roster page reads its character details from cache files in the system temporary directory. This avoids fetching every character profile while friends view the page.

Populate or refresh the cache from the command line:

```bash
php refresh-armory-cache.php
```

Schedule this script once per day at midnight to keep the displayed character data current:

```text
0 0 * * * cd /path/to/wow-forever-armory && php refresh-armory-cache.php
```

On Windows, it can be scheduled with Task Scheduler by running:

```text
php.exe C:\path\to\wow-forever-armory\refresh-armory-cache.php
```

The refresh script has locking to prevent concurrent runs and skips invalid roster entries.

## Notes

- Character pages that have not been cached or whose cache cannot be loaded will show a loading error until the refresh script succeeds.
- Armory page changes by Blizzard may require updates to `armory.php`.
- This project is intended for a trusted private friend group; it does not include authentication or an admin authorization layer.
