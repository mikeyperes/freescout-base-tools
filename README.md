# FreeScout Base Tools

Hexa Web Systems base customizations for FreeScout.

## Features

- Subject+sender email threading fallback for conversations without Message-ID
- Admin password reset tool (reset any user's password from admin panel)
- Watchers dropdown on conversations
- PHP 8.2 compatibility patches
- Noninteractive `php artisan tinker --execute="..."` support for the bundled Tinker 1.x
- Admin-only watcher controls and persistent favicon/header logo overrides

## Requirements

- FreeScout v1.8+
- PHP 8.0+

## Installation

1. Upload the `HexawebBaseTools` folder to `Modules/`
2. Go to Manage → Modules and activate it
