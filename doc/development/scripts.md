# Available Scripts

> See also: [Installation](installation.md) · [Symfony Commands](commands.md)

Scripts are located in `scripts/`. All PHP scripts follow this convention: a **commented header** just after the shebang, with `Description:` and `Usage:` tags.

```bash
# See all available scripts
php scripts/help.php

# See the help for a specific script
php scripts/help.php migrate.php
```

## Overview

| Script | Type | Role |
|---|---|---|
| `check-php.sh` | Bash | Check that PHP 8.4+ is installed |
| `help.php` | PHP | Display help for all scripts |
| `setup.php` | PHP | Full installation (first time) |
| `dev.php` | PHP | Start / stop the environment |
| `migrate.php` | PHP | Run Doctrine migrations |
| `console.php` | PHP | Run a Symfony command |
| `logs.php` | PHP | Display Docker logs |
| `health.php` | PHP | Check application status |

## Script Details

### `check-php.sh`
Checks that PHP >= 8.4 is available in the PATH. This is the only Bash script in the project — all others are PHP.

```bash
bash scripts/check-php.sh
# ✓ PHP 8.4.5 detected
```

---

### `help.php`
Displays the list of all scripts with their description and usage examples. Automatically parses the header of each script file.

```bash
php scripts/help.php              # list all scripts
php scripts/help.php migrate.php  # detail for one script
```

---

### `setup.php`
Full project installation. Run once after cloning.

```bash
php scripts/setup.php
php scripts/setup.php --skip-frontend  # without npm install
```

---

### `dev.php`
Starts or stops the Docker environment.

```bash
php scripts/dev.php           # start
php scripts/dev.php --stop    # stop
```

---

### `migrate.php`
Runs Doctrine migrations in the PHP container.

```bash
php scripts/migrate.php             # run migrations
php scripts/migrate.php --dry-run   # simulate without applying
```

---

### `console.php`
Runs any `bin/console` command in the PHP container.

```bash
php scripts/console.php cache:clear
php scripts/console.php doctrine:migrations:status
php scripts/console.php somanagent:seed:web-team
```

---

### `logs.php`
Displays a Docker container's logs in real time (tail -f).

```bash
php scripts/logs.php          # logs from the php container (default)
php scripts/logs.php db       # PostgreSQL logs
php scripts/logs.php node     # Vite logs
php scripts/logs.php nginx    # Nginx logs
```

---

### `health.php`
Queries the API to check the application and connector status.

```bash
php scripts/health.php
php scripts/health.php --url http://my-server:8080
```

## Script Header Convention

Each script must start with this block (after the shebang):

**PHP:**
```php
#!/usr/bin/env php
<?php
// Description: Short one-line description
// Usage: php scripts/script-name.php [options]
// Usage: php scripts/script-name.php --flag value
```

**Bash:**
```bash
#!/usr/bin/env bash
# Description: Short one-line description
# Usage: bash scripts/script-name.sh [options]
```

`help.php` automatically parses these headers to generate its display.
