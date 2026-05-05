# model/skeleton

Composer create-project template for a ModEl Framework 4 application. Replaces the legacy `zkinstall.php` web installer with a CLI-driven flow.

## Usage

```
composer create-project model/skeleton my-app
```

Composer clones the skeleton, installs `model/core` + `model/router` from the configured Composer repositories, then runs an interactive installer that:

1. Asks for a repository URL and license key (defaults to the existing ModEl repository).
2. Validates the key against the repository's `?act=get-modules` endpoint.
3. Lets you pick the legacy ModEl 3 modules to download (`Output` is auto-selected with its dependencies, mirroring `zkinstall.php`).
4. Downloads the legacy `model/` directory and `app/FrontController.php` from the repository, applying `[zk:*]` placeholder substitution to config files.
5. Writes a `.env` and prints next steps.

The result is a working ModEl app whose `index.php` boots through the legacy `FrontController` while modern `model/*` Composer packages live alongside in `vendor/`. Add more `model/*` packages with `composer require` as the migration progresses.

## Local testing without publishing

```
composer create-project --repository="{\"type\":\"path\",\"url\":\"../model4/skeleton\"}" model/skeleton:@dev test-app
```
