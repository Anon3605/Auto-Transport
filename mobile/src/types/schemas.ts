import { z } from 'zod';

/**
 * Client validation mirroring the Laravel FormRequest rules exactly.
 * The server re-validates everything — this layer exists for instant
 * feedback and offline-safe forms, not as a security boundary.
 */

const currentYear = new Date().getFullYear();

export const addressSchema = z.object({
  line1: z.string().max(255).optional(),
  city: z.string().min(1, 'City is required').max(120),
  state: z.string().max(120).optional(),
  postal_code: z.string().max(24).optional(),
  country_code: z.string().length(2).default('US'),
  lat: z.number().min(-90).max(90).optional(),
  lng: z.number().min(-180).max(180).optional(),
  location_type: z.enum(['residential', 'business', 'terminal', 'auction', 'dealer', 'port'])
    .default('residential'),
});

export const vehicleSchema = z.object({
  vehicle_type_id: z.number().int().positive().nullable(),
  year: z.number().int().min(1900).max(currentYear + 2).nullable(),
  make: z.string().max(64).nullable(),
  model: z.string().max(64).nullable(),
  color: z.string().max(48).nullable(),
  // VIN: 17 chars, no I/O/Q by ISO 3779
  vin: z.string().regex(/^[A-HJ-NPR-Z0-9]{17}$/i, 'Invalid VIN').nullable().or(z.literal('')),
  is_operable: z.boolean().default(true),
  is_modified: z.boolean().default(false),
});

export const quoteRequestSchema = z.object({
  service_id: z.number().int().positive().nullable(),
  contact_name: z.string().min(2, 'Name is required').max(160),
  contact_email: z.string().email('Enter a valid email'),
  contact_phone: z.string().max(32).optional(),
  pickup: addressSchema,
  dropoff: addressSchema,
  pickup_date_earliest: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date'),
  pickup_date_latest: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
  dates_flexible: z.boolean().default(false),
  vehicles: z.array(vehicleSchema).min(1, 'Add at least one vehicle').max(8),
  additional_notes: z.string().max(2000).optional(),
})
  .refine(
    (d) => !d.pickup_date_latest || d.pickup_date_latest >= d.pickup_date_earliest,
    { message: 'End of window must be on or after the start', path: ['pickup_date_latest'] },
  )
  .refine(
    (d) => !(d.pickup.city === d.dropoff.city && d.pickup.postal_code === d.dropoff.postal_code),
    { message: 'Pickup and dropoff cannot be the same location', path: ['dropoff'] },
  );

export const reviewSchema = z.object({
  // The shipment is addressed by ULID, matching every other endpoint — the
  // integer id is never emitted by the API, so the client cannot hold one.
  booking_ulid: z.string().length(26, 'Missing shipment reference'),
  rating_overall: z.number().int().min(1, 'Pick a rating').max(5),
  rating_communication: z.number().int().min(1).max(5).nullable(),
  rating_timeliness: z.number().int().min(1).max(5).nullable(),
  rating_condition: z.number().int().min(1).max(5).nullable(),
  rating_value: z.number().int().min(1).max(5).nullable(),
  title: z.string().max(160).optional(),
  body: z.string().max(5000).optional(),
});

export const contactSchema = z.object({
  name: z.string().min(2).max(160),
  email: z.string().email(),
  phone: z.string().max(32).optional(),
  subject: z.string().max(200).optional(),
  message: z.string().min(10, 'Tell us a little more').max(5000),
});

export type QuoteRequestInput = z.infer<typeof quoteRequestSchema>;
export type ReviewInput = z.infer<typeof reviewSchema>;
export type ContactInput = z.infer<typeof contactSchema>;
