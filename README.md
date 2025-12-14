# WPI - WordPress Import Tool for Evolution CMS 3.1.3+

A powerful tool to import content from WordPress XML export files into Evolution CMS 3.1.3+.

## Features

*   **Content Import**: Imports Posts, Pages, Categories, and Users.
*   **Media Handling**: Downloads images and attachments, attempts to fix image paths in content.
*   **Smart Parsing**: Handles `<!--more-->` tags, post thumbnails, and custom fields.
*   **Rollback Capability**: Includes a `--rollback` option to remove all imported resources and users.
*   **PDF Support**: Optional downloading of PDF files (configurable via flag).
*   **Safety**: Validates XML structure and generator version before processing.

## Installation

To install the package and automatically configure Evolution CMS to use it, follow these steps.

### 1. Configure Composer

Edit `core/custom/composer.json`. If this file doesn't exist, create it.
Ensure it contains the repository, requirement, and **scripts** for auto-configuration:

```json
{
    "name": "evolutioncms/custom",
    "provide": {
        "evolution-cms/evolution": "3.1.30"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/XTRO123/wpi"
        }
    ],
    "require": {
        "xtro123/wpi": "dev-main"
    },
    "autoload": {
        "psr-4": {}
    },
    "scripts": {
        "post-update-cmd": [
            "php -r \"file_put_contents('define.php', '<?php require_once __DIR__ . \\'/vendor/autoload.php\\';');\"",
            "php -r \"if(file_exists('vendor/xtro123/wpi')) { @mkdir('config/app/providers', 0755, true); file_put_contents('config/app/providers/WpiServiceProvider.php', '<?php return Xtro123\\\\Wpi\\\\WpiServiceProvider::class;'); } else { @unlink('config/app/providers/WpiServiceProvider.php'); }\""
        ],
        "post-install-cmd": [
            "@post-update-cmd"
        ]
    }
}
```

### 2. Install Package

Run composer update in the custom folder:

```bash
cd core/custom
composer update
```

## Usage

Run the import command from your project root:

```bash
php core/artisan wpi:import [options] [file]
```

### Arguments

*   `file` (Optional): Path to the XML export file.

### Options

*   `--rollback`: **Delete all imported content**.
    ```bash
    php core/artisan wpi:import --rollback
    ```
*   `--no-pdf`: Disable downloading of PDF files.
    ```bash
    php core/artisan wpi:import --no-pdf
    ```

## Delete

To uninstall the package:

1.  Open `core/custom/composer.json` and remove the line `"xtro123/wpi": "dev-main"`.
2.  Run `composer update` in the `core/custom` directory.

The configuration scripts will automatically detect the package removal and delete the registered ServiceProvider file (`config/app/providers/WpiServiceProvider.php`).

## Requirements

*   PHP ^8.0
*   Evolution CMS 3.1.3+

## License

MIT
