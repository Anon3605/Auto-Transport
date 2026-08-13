# AutoTransport — backend

Laravel 12 application serving the JSON API at `/api/v1` and the staff admin
panel at `/admin`.

**The documentation lives at the repository root**, so it can describe both this
project and the mobile client without duplication:

| Document | Answers |
|---|---|
| [`../docs/backend.md`](../docs/backend.md) | Layering, the API surface, the admin panel, permissions, testing |
| [`../docs/database-design.md`](../docs/database-design.md) | Why the schema is shaped this way |
| [`../docs/architecture.md`](../docs/architecture.md) | How this fits with the mobile client |
| [`../README.md`](../README.md) | Setup and seeded accounts |

---

## Quick start

```bash
composer install && php artisan migrate:fresh --seed && php artisan serve
```

```bash
php artisan test                        # 91 tests, 465 assertions
php artisan route:list --path=api       # the server half of the client contract
php artisan reviews:rebuild-aggregates  # recompute rating averages from source
```

---

## Before you change anything

**Ignore rules live in the root [`.gitignore`](../.gitignore)**, not here. The
`.gitignore` files remaining under `storage/` and `bootstrap/cache/` are *not*
ignore rules — they contain `*` plus `!.gitignore`, which is how an empty
directory gets committed. Deleting them breaks fresh clones only.

**`DatabaseSeeder` must not use `WithoutModelEvents`.** The trait is the Laravel
stub default and it silently disables the `creating` hook that populates every
`ulid`, plus the observer that maintains review aggregates.

**The API speaks ULIDs.** Auto-increment ids never leave the server.
