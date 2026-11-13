# Development

## Development

Install dependencies:

```bash
composer install
```

Run tests (WordPress test suite required):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

If needed, set `WP_TESTS_DIR` to your local WordPress tests library path before running PHPUnit.
