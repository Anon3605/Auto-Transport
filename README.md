# AutoTransport

Vehicle-shipping platform. A Laravel 12 API with a server-rendered admin panel,
and a React Native (Expo 57) client.

A customer browses transport options, books a shipment, tracks it, and reviews it
once delivered. Staff price leads, move shipments through a state machine,
moderate reviews, and manage users and roles.

---

## Documentation

Read these in order of the question you have:

| Document | Answers |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | How the pieces fit, request lifecycles, the invariants that hold it together |
| [`docs/database-design.md`](docs/database-design.md) | Why the schema is shaped this way — **read first for any data question** |
| [`docs/backend.md`](docs/backend.md) | Laravel layering, the API surface, the admin panel, permissions, testing |
| [`docs/mobile.md`](docs/mobile.md) | Routing, the auth guard, state layers, the API seam, design system |

The docs carry the *why*. The *what* is recoverable from the code; the reasoning
is not.

---

## Running it

### Backend

```bash
cd backend && composer install && php artisan migrate:fresh --seed && php artisan serve
```

`http://127.0.0.1:8000` — API at `/api/v1`, admin panel at `/admin`.

`.env` ships with `DB_CONNECTION=sqlite`, so there is nothing to provision. To use
MySQL from XAMPP instead, set `DB_CONNECTION=mysql` plus the usual `DB_*` values
and re-run the migrate command.

### Mobile

```bash
cd mobile && npm install && npx expo start
```

The client reads `EXPO_PUBLIC_API_URL`, defaulting to
`http://localhost:8000/api/v1`. A physical device cannot reach `localhost` —
point it at your machine's LAN address and bind the server to all interfaces:

```bash
EXPO_PUBLIC_API_URL=http://192.168.1.50:8000/api/v1 npx expo start
```

```bash
cd backend && php artisan serve --host=0.0.0.0
```

> **After adding a new file under `mobile/app/`, restart Expo with `--clear`.**
> The router builds its route manifest at startup; a new route added to a running
> server is not registered, and the screen is unreachable even though hot reload
> shows your other edits. See [`docs/mobile.md` §9](docs/mobile.md#9-verifying-a-change).

---

## Seeded accounts

Development only — `DemoDataSeeder` is skipped when `APP_ENV=production`.

| Role | Email | Password |
|---|---|---|
| Super admin | `admin@autotransport.test` | `password` |
| Customer | `customer@autotransport.test` | `password` |
| Driver | `driver@autotransport.test` | `password` |

The super-admin's credentials come from `ADMIN_EMAIL` / `ADMIN_NAME` /
`ADMIN_PASSWORD` in `.env` when set — a committed password is a published
password. In production with nothing set, a random one is generated and printed
once. Re-seeding never rewrites an existing admin password, so an operator who
rotated it in the panel does not find `db:seed` quietly reverting them.

Five failed logins lock an account for 15 minutes, with a 6/minute per-IP throttle
on top.

---

## What is wired up

**Authentication** — register, login, logout, forgot-password, `me`. Sanctum
bearer tokens in Keychain/Keystore.

**Booking** — book a transport option from the app. Writes the full
`quote_request → quote → booking` chain in one transaction, so an
instantly-booked shipment keeps the same intake and offer history as one that came
through a staff-issued quote. Opens at `pending_payment`: the price is an
automated estimate, and a human confirms before it reaches the dispatch board.

**Tracking** — customer-visible timeline. Internal dispatch notes ride the same
table and are filtered out of the API.

**Reviews** — a customer reviews a delivered shipment: overall rating plus
optional sub-ratings for communication, timeliness, vehicle condition and value.
Nothing is public until a moderator approves it. Service, carrier and driver
averages are maintained by an observer, with
`php artisan reviews:rebuild-aggregates` to recompute from source.

**Admin panel** — dashboard, users, roles and permissions, review moderation,
bookings and the dispatch board, quote requests, contact messages, services,
settings. Server-rendered Blade with hand-written CSS and no build step.

**Contact form** — public, throttled, feeding the admin inbox.

---

## Not built

Stated so nobody infers otherwise from a half-present seam:

- **Payment capture.** The ledger schema exists; no gateway is wired.
- **Email delivery.** Saving a reply to a contact message writes the audit trail
  and sends nothing. The admin form says so.
- **Quote accept / decline endpoints.** Listed in `endpoints.ts`, no controller.
- **Real distance lookup.** `QuoteEstimator` stubs distance with a 1000-mile
  fallback.

See [`docs/architecture.md` §8](docs/architecture.md#8-what-is-deliberately-not-built)
for what each would involve.

---

## Verifying a change

```bash
cd backend && php artisan test
```

91 tests, 465 assertions. `AdminPanelSmokeTest` renders every admin page against
real seeded rows — a template that compiles can still throw on a relation that was
never eager-loaded.

```bash
cd mobile && npx tsc --noEmit
```

---

## Two things that will bite you

**The API contract is duplicated on purpose.** `mobile/src/api/endpoints.ts` holds
the route strings and `mobile/src/types/api.ts` mirrors the Laravel resource
shapes. Nothing links them to the server mechanically, so renaming a route on one
side without the other is a **silent 404 at runtime, not a compile error**.
`php artisan route:list --path=api` is the check.

**Identifiers in the API are ULIDs, never auto-increment ids.** A sequential id in
a payload leaks volume — `/bookings/1847` tells a competitor how many shipments
you have done — and makes enumeration trivial. Route-model binding resolves on
`ulid`, or `slug` for services. An unguessable key is *not* authorization: every
owner-scoped endpoint still runs its policy check, and scoped `exists` rules
return "not found" rather than "forbidden" so a probe cannot confirm a row exists.

---

## Version control

One master [`.gitignore`](.gitignore) at the root covers both projects, replacing
the former `backend/.gitignore`.

**Do not delete the `.gitignore` files under `backend/storage/`.** They contain
`*` plus `!.gitignore` — the standard trick for committing an *empty* directory,
since Git tracks files and not folders. They are the only reason
`storage/framework/views/` exists after a clone; remove them and the app boots,
then dies on the first page render with *failed to open stream* — a failure that
appears only on a fresh checkout.

`mobile/` is currently its own git repository with its own remote, so it keeps its
own `.gitignore`; a root-level file has no authority over a nested repo.
