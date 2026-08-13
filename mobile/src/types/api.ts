/**
 * Mirrors the Laravel API resource shapes. Kept hand-written rather than
 * generated so the client contract is reviewable in diffs.
 * Money arrives as integer minor units — never format it with plain division
 * in a template; use formatMoney().
 */

export type Ulid = string;

export type BookingStatus =
  | 'pending_payment' | 'confirmed' | 'assigned'
  | 'picked_up' | 'in_transit' | 'delivered' | 'cancelled';

export type QuoteRequestStatus =
  | 'new' | 'reviewing' | 'quoted' | 'accepted' | 'declined' | 'expired' | 'spam';

export type ReviewStatus = 'pending' | 'approved' | 'rejected';

export interface Money {
  cents: number;
  currency: string;
}

export const formatMoney = (m: Money): string =>
  new Intl.NumberFormat('en-US', { style: 'currency', currency: m.currency })
    .format(m.cents / 100);

export interface User {
  ulid: Ulid;
  name: string;
  email: string;
  phone: string | null;
  avatar_url: string | null;
  roles: string[];
  email_verified: boolean;
}

export interface Service {
  id: number;
  slug: string;
  name: string;
  short_description: string | null;
  icon: string | null;
  hero_image_url: string | null;
  base_price: Money;
  price_per_mile: Money;
  transit_days_min: number | null;
  transit_days_max: number | null;
  rating_avg: number;
  rating_count: number;
}

export interface VehicleType {
  id: number;
  slug: string;
  name: string;
  size_class: string;
  price_multiplier: number;
}

export interface QuoteVehicle {
  vehicle_type_id: number | null;
  year: number | null;
  make: string | null;
  model: string | null;
  color: string | null;
  vin: string | null;
  is_operable: boolean;
  is_modified: boolean;
}

export interface AddressInput {
  line1?: string;
  city: string;
  state?: string;
  postal_code?: string;
  country_code: string;
  lat?: number;
  lng?: number;
  location_type: 'residential' | 'business' | 'terminal' | 'auction' | 'dealer' | 'port';
}

export interface QuoteRequestPayload {
  service_id: number | null;
  contact_name: string;
  contact_email: string;
  contact_phone?: string;
  pickup: AddressInput;
  dropoff: AddressInput;
  pickup_date_earliest: string;   // YYYY-MM-DD — date only, no timezone ambiguity
  pickup_date_latest?: string;
  dates_flexible: boolean;
  vehicles: QuoteVehicle[];
  additional_notes?: string;
}

export interface QuoteRequest {
  ulid: Ulid;
  reference: string;
  status: QuoteRequestStatus;
  estimated_price: Money | null;
  distance_miles: number | null;
  vehicle_count: number;
  created_at: string;
}

export interface BookingEvent {
  id: number;
  event_type: string;
  to_status: BookingStatus | null;
  description: string | null;
  lat: number | null;
  lng: number | null;
  occurred_at: string;
}

export interface Booking {
  ulid: Ulid;
  booking_number: string;
  status: BookingStatus;
  service: Pick<Service, 'id' | 'name' | 'slug'> | null;
  pickup_city: string;
  pickup_state: string | null;
  dropoff_city: string;
  dropoff_state: string | null;
  scheduled_pickup_date: string | null;
  scheduled_delivery_date: string | null;
  actual_pickup_at: string | null;
  actual_delivery_at: string | null;
  total_price: Money;
  amount_paid: Money;
  balance_due: Money;
  can_review: boolean;          // server-computed: delivered && no existing review
  events?: BookingEvent[];
}

export interface Review {
  ulid: Ulid;
  /**
   * Present only on the owner's own review (the create response), never on the
   * public per-service listing — a shipment reference has no business being
   * readable by a stranger browsing testimonials.
   */
  booking_ulid?: Ulid;
  rating_overall: number;
  rating_communication: number | null;
  rating_timeliness: number | null;
  rating_condition: number | null;
  rating_value: number | null;
  title: string | null;
  body: string | null;
  status: ReviewStatus;
  admin_reply: string | null;
  helpful_count: number;
  author_name: string;
  created_at: string;
}

export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}
