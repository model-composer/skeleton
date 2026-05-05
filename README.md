# model/skeleton

Composer create-project template for a ModEl Framework 4 application. Replaces the legacy `zkinstall.php` web installer with a CLI-driven flow.

## Usage

From an empty target folder:

```
composer create-project model/skeleton .
```

The trailing `.` is important — it tells Composer to install in the current directory rather than create a `skeleton/` subfolder. Alternatively, pass an explicit target name:

```
composer create-project model/skeleton my-app
```

The installer:

1. Asks for a license key.
2. Asks for an app name (defaults to the directory name).
3. Validates the key against the existing repository's `?act=get-modules` endpoint.
4. Downloads the legacy `model/Core` + `model/Output` (and their dependencies) plus templated config files into the project root, applying `[zk:*]` placeholder substitution.
5. Asks for the dev environment name (defaults to `local`) and writes a matching `.env`.

The result is a working ModEl app whose `index.php` boots through the legacy `FrontController` while modern `model/*` Composer packages live alongside in `vendor/`. Add more legacy modules later by editing the install list, or add new Composer packages with `composer require model/<pkg>`.
