import type { User } from '@/src/types/api';

/**
 * Which panel a signed-in user gets — and 'guest' when nobody is signed in.
 *
 * WHY 'guest' EXISTS, because removing it looks harmless and is not:
 *
 * The first version of this returned 'customer' as its fallback, reasoning that
 * a user with no special role is a customer. But `panelFor(null)` then also
 * returned 'customer', so a signed-OUT visitor satisfied both
 * `guard={panel === 'customer'}` and `guard={user === null}` at the same time.
 * Two groups were reachable at once: the customer tabs stayed mounted after
 * logout, their queries 401'd, and the app showed an authentication error
 * instead of the login screen. Sign-out appeared to do nothing.
 *
 * Returning a distinct 'guest' makes the four panels mutually exclusive by
 * construction: the navigator compares ONE value for equality, so exactly one
 * branch can ever match. That is a property of the type, not a rule someone has
 * to remember.
 *
 * Roles come from the API (UserResource emits role names), so this mirrors
 * App\Enums\UserRole and must stay in step with it.
 */
export type Panel = 'guest' | 'customer' | 'driver' | 'staff';

const STAFF_ROLES = ['super-admin', 'admin', 'dispatcher', 'support'] as const;

/**
 * Order matters. A dispatcher who also drives is staff first: the web panel is
 * strictly more capable than the driver screens, so sending them to the weaker
 * surface would be the wrong default. A driver who also holds `customer` (which
 * registration grants by default) is a driver.
 */
export function panelFor(user: User | null | undefined): Panel {
  // No session: not a customer, not anything. The auth group owns this case.
  if (!user) {
    return 'guest';
  }

  const roles = user.roles ?? [];

  if (roles.some((role) => STAFF_ROLES.includes(role as (typeof STAFF_ROLES)[number]))) {
    return 'staff';
  }

  if (roles.includes('driver')) {
    return 'driver';
  }

  return 'customer';
}
