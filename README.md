# WPI - WordPress Import Tool for Evolution CMS CE

A powerful tool to migrate content from WordPress to Evolution CMS 3.1.x.

## Features

- **Import Categories**: Preserves hierarchy.
- **Import Users**: Creates manager users (password reset required).
- **Import Posts & Pages**: Preserves hierarchy, dates, and status.
- **Media Handling**: 
  - Downloads Featured Images and associates them via TV (`image`).
  - Scans content for images/PDFs, downloads them, and updates links locally.
  - Efficient caching: Skips already downloaded files.
  - Validates images using `getimagesize` and MIME types.
- **Rollback**: Full cleanup command to remove imported resources and TVs.

## Installation

### 1. Configure Repository
Add the package repository to your `core/custom/composer.json`:

```json
{
    "name": "evolutioncms/custom",
    "provide": {
        "evolution-cms/evolution": "3.1.3*"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/XTRO123/wpi"
        }
    ],
    "require": {
    },
    "autoload": {
        "psr-4": []
    }
}
```

### 2. Install Package
Run the installation command from your project root:

```bash
php core/artisan package:installrequire xtro123/wpi dev-main
```

### 3. Register Provider (Optional)
If valid package auto-discovery is disabled, add the service provider in `core/custom/config/app.php`:

```php
'providers' => [
    // ...
    Xtro123\Wpi\WpiServiceProvider::class,
],
```

## Usage

Run the import command via artisan:

```bash
php core/artisan wpi:import [file] [options]
```

### Arguments

- `file`: (Optional) Path to the WordPress XML export file. If not provided, you will be prompted to enter it.

### Options

- `--rollback`: Delete all imported content (Categories, Users, Posts, TVs).
- `--no-pdf`: Disable downloading PDF files found in content/media.

### Examples

**1. Basic Import**
```bash
php core/artisan wpi:import export_data.xml
```

**2. Import without downloading PDFs**
```bash
php core/artisan wpi:import export_data.xml --no-pdf
```

**3. Rollback (Delete imported data)**
```bash
php core/artisan wpi:import export_data.xml --rollback
```

## Delete
- Remove xtro123/wpi requirements and repositories from core/custom/composer.json
- also see core/custom/config/app/providers
- Run composer update in core to remove package files

## Requirements

- PHP >= 8.2
- Evolution CMS CE >= 3.1.x
- WordPress Export (WXR) Version 1.2+ (WordPress 6.0+)

## License

MIT
