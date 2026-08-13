import type { BookingStatus } from '@/src/types/api';

/**
 * Presentation helpers for booking status.
 *
 * They live here rather than beside the list screen because everything under
 * app/ is a route: importing a helper from one route file into another couples
 * two screens through the router's file map, and the helper disappears the day
 * that screen is renamed.
 */

export type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'primary';

export function statusTone(status: BookingStatus): Tone {
  switch (status) {
    case 'delivered':
      return 'success';
    case 'in_transit':
    case 'picked_up':
      return 'primary';
    case 'pending_payment':
      return 'warning';
    case 'cancelled':
      return 'danger';
    default:
      return 'neutral';
  }
}

/** pending_payment -> "Pending payment". Mirrors BookingStatus::label() server-side. */
export function statusLabel(status: BookingStatus): string {
  return status.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}
