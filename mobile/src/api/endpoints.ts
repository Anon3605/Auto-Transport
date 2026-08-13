/**
 * Single source of truth for route strings. Note every :id is a ULID, never
 * a sequential integer — see docs/database-design.md §4.5.
 */
export const endpoints = {
  auth: {
    login:    '/auth/login',
    register: '/auth/register',
    logout:   '/auth/logout',
    me:       '/auth/me',
    forgot:   '/auth/forgot-password',
  },
  catalog: {
    services:     '/services',
    service:      (slug: string) => `/services/${slug}`,
    vehicleTypes: '/vehicle-types',
    locations:    '/locations',
    faqs:         '/faqs',
    settings:     '/settings/public',
  },
  quotes: {
    estimate: '/quotes/estimate',            // instant price, no persistence
    store:    '/quote-requests',             // guest-allowed
    mine:     '/quote-requests',
    show:     (ulid: string) => `/quote-requests/${ulid}`,
    accept:   (ulid: string) => `/quotes/${ulid}/accept`,
    decline:  (ulid: string) => `/quotes/${ulid}/decline`,
  },
  bookings: {
    // Books a service directly. The server writes the whole
    // quote_request -> quote -> booking chain, so an app booking keeps the same
    // intake history as one that came through a staff-issued quote.
    create: '/bookings',
    index:  '/bookings',
    show:   (ulid: string) => `/bookings/${ulid}`,
    events: (ulid: string) => `/bookings/${ulid}/events`,
    cancel: (ulid: string) => `/bookings/${ulid}/cancel`,
  },
  reviews: {
    store:   '/reviews',
    forService: (slug: string) => `/services/${slug}/reviews`,
    helpful: (ulid: string) => `/reviews/${ulid}/helpful`,
  },
  contact:      '/contact-messages',
  deviceTokens: '/device-tokens',
} as const;
