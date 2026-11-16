# Contract: cached-config guard (`tests/Pest.php`)

## Position

The first statement after the last `use` import, and before `pest()->extend(TestCase::class)...->in('Feature');`. It sits under a short comment block in the file's existing `/* |---| */` header style. Nothing else in `tests/Pest.php` changes in this commit.

## Behaviour

| Condition | Result |
| --- | --- |
| `dirname(__DIR__).'/bootstrap/cache/config.php'` exists | Writes the message to `STDERR`, then `exit(1)`. No test file is loaded and no database is connected. |
| The file is absent | No effect. |

**Never**: deletes, renames or reads the cache file; prints anything when the cache is absent.

## Message

It is one block of text with three facts:

1. the configuration is cached (it names `bootstrap/cache/config.php`);
2. tests would run against the cached (dev) database settings instead of `phpunit.xml`;
3. run `php artisan config:clear` and try again.

It contains no settings values: no database name, no host, no credentials.

## Applies to

- `php artisan test`, serial or `--parallel`, with or without a path or `--filter`;
- `vendor/bin/pest`;
- `tests/Unit`.

`composer test` is unaffected, because it clears the cache first.
