# Contract: cross-tenant dataset builder in `tests/Pest.php`

## `crossTenantRoutesFor(string $rule, ?string $slice = null): array`

- **Returns** `array<string, array{0: string, 1: string}>`, keyed `"<METHOD> <uri>"`. The shape is unchanged.
- **`$slice = null`**: every route of `$rule`. The result is the same as the original function's.
- **`$slice = '<Part>'`**: only the routes whose `crossTenantSegment(uri)` is listed under `<Part>` in `crossTenantSlices()`.
- **An unknown `$slice`** throws `InvalidArgumentException`. A typo must not produce an empty dataset that passes silently.
- **Boot**: on its first call in a process, it boots `__DIR__.'/../bootstrap/app.php'` once, groups the routes by rule, then calls `HandleExceptions::flushState()` and `Facade::clearResolvedInstances()`. Later calls use the cached grouping.
- **Use**: only inside a `->with(fn () => ...)` closure or a test body, as now.

## `crossTenantSlices(): array<string, list<string>>`

The map in [research.md](../research.md) R1, in the order Reservations, Operations, GuestServices, Platform.

## `crossTenantSegment(string $uri): string`

Removes a leading `api/` and returns the text before the next `/`. Examples:

- `api/reservation/{reservation}` → `reservation`
- `api/superadmin/users` → `superadmin`
- `api/whatsapp` → `whatsapp`

## Moved unchanged

`crossTenantRoute(string $method, string $uri): Route` and `crossTenantAdmin(Hotel $hotel): User`.

## Use in each part

```php
it('never shows another hotel\'s rows on a list or report route', function (string $method, string $uri) {
    // body unchanged
})->with(fn () => crossTenantRoutesFor('index', 'Reservations'));
```

The owner test keeps its `GET` filter around the sliced call:

```php
->with(fn () => array_filter(crossTenantRoutesFor('bound', 'Reservations'), fn (array $route) => $route[0] === 'GET'));
```
