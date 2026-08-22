# Expo HAS CHANGED

Read the exact versioned docs at <https://docs.expo.dev/versions/v57.0.0/> before
writing any code. Guidance written for older SDKs is actively wrong here:

- **SDK 56 removed support for importing `@react-navigation/*` in application
  code.** Import from `expo-router` instead.
- **Auth guards use `<Stack.Protected guard={…}>`**, not a `useEffect` +
  `router.replace` redirect. The imperative pattern races the first paint.
- **`expo-secure-store` has no web implementation.** `src/lib/storage.ts` splits
  platforms; do not call it directly.

## Before you add a route

`expo-router` builds its route manifest when the dev server starts. A new file
under `app/` added to a running server is **not registered** — hot reload shows
your edits to existing files while the new screen stays unreachable, which makes
this very confusing to diagnose.

Restart with `npx expo start --clear`, then verify against the served bundle
rather than the file system:

```bash
curl -s "http://localhost:8081/node_modules/expo-router/entry.bundle?platform=web&dev=true" | grep -c "your-new-route"
```

## Project documentation

Start with [`README.md`](README.md) in this directory — it is self-contained and
covers setup, the route tree, and the traps.

Deeper design documents live in the **parent monorepo**, not in this repository.
These paths resolve in a full checkout of `AutoTransport/`; in a standalone clone
of this repo they do not exist:

- `../docs/mobile.md` — routing, auth guard, state layers, design system
- `../docs/architecture.md` — how this fits with the API
- `../docs/database-design.md` — schema rationale, and the source of the ULID and
  integer-cents conventions this client has to honour

## Two rules that are easy to break

**The API contract is duplicated by hand.** `src/api/endpoints.ts` and
`src/types/api.ts` mirror the Laravel routes and resources with no mechanical
link. A renamed route type-checks fine and 404s at runtime. Check with
`php artisan route:list --path=api` in `../backend`.

**Money is integer minor units.** The API sends `{ cents, currency }`. Format it
with `formatMoney()` — never divide in a template.
