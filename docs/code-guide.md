# Finding your way around the code

The application uses PHP functions, SQLite and HTML templates. Start here:

| Folder or file | Purpose |
| --- | --- |
| `php/public/index.php` | Receives requests and selects the page or action |
| `php/src/` | Authentication, database access and gallery features |
| `php/templates/` | HTML pages, with PHP for displaying values and loops |
| `php/public/assets/` | CSS, browser JavaScript and the model viewer bundle |
| `php/scripts/` | Installation and maintenance commands |
| `php/tests/` | PHP checks and browser tests |

## Coming from Java, C# or C++

Variables start with `$`. Functions can declare parameter and return types:

```php
function is_admin(array $user): bool
{
    return $user['role'] === 'admin';
}
```

- `===` compares both value and type. Prefer it to `==`.
- `array` can be a list or a dictionary: `$user['name']` reads a named entry.
- `->` accesses an object's methods or properties; `::` accesses static members.
- `?string` means a string or `null`. `??` provides a default for a missing or null value.
- `.` joins strings. `use ($value)` captures a variable in a closure.
- `<?= e($title) ?>` prints an HTML-escaped value in a template.
- `if (...): ... endif;` is the template form of an ordinary `if` block.

## Formatting

From `php/`, run `composer install`, then:

```sh
composer format
composer format:check
```

The second command checks formatting without changing files. CI runs it too.
The formatter is a development dependency; production installs omit it.

Keep configuration separate from loops. Use named fields for records, one per
line, rather than packing nested arrays into a `foreach`. Give complicated
conditions descriptive variable names. The formatter handles spacing; these
readability choices still need a human pass.
