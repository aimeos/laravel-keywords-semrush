# Laravel Keywords Semrush

Semrush driver for the
[Laravel Keywords Bridge](https://github.com/aimeos/laravel-keywords-bridge)
using the keyword reports of the Semrush API v3.

## Installation

```bash
composer require aimeos/laravel-keywords-semrush
```

## Configuration

Add these settings to your `.env` file and set `KEYWORDS_DRIVER=semrush`:

```
SEMRUSH_API_KEY=...
SEMRUSH_DATABASE=us
```

* `SEMRUSH_API_KEY`: API key from the subscription info of your Semrush account (requires a plan with API units)
* `SEMRUSH_DATABASE`: Two letter ISO country code of the database used if no country is passed

Optional settings in the `semrush` section of `./config/keywords-bridge.php`:

* `timeout`: Request timeout in seconds (default: `30`)

## Usage

```php
use Aimeos\KeywordsBridge\Facades\Keywords;

// search volume in the configured database
$result = Keywords::volume(['laravel cms', 'headless cms']);

// related keywords in Germany
$result = Keywords::related('laravel cms', 'de', 100);
```

* Semrush has no worldwide data, without a country the configured database is used.
* Country codes are the regional database codes, `gb` is mapped to the `uk` database.
* Volume uses the `phrase_these` report (100 keywords per request, 10 API units per keyword).
* Related keywords use the `phrase_related` report, ordered by volume (at most 1000, 40 API units per keyword).
* Keywords containing semicolons can't be passed to the API and are returned with a `null` volume.

## License

This package is released under the LGPL-2.1+ License.
