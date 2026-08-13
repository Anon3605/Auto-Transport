import { useQuery } from '@tanstack/react-query';
import { Link, useLocalSearchParams } from 'expo-router';
import { Pressable, View } from 'react-native';

import { api } from '@/src/api/client';
import { endpoints } from '@/src/api/endpoints';
import { Badge, Button, Card, Empty, ErrorNote, Loading, Row, Screen, Txt } from '@/src/components/ui';
import { statusLabel, statusTone } from '@/src/lib/booking';
import { formatMoney, type Booking, type BookingEvent } from '@/src/types/api';
import { useTheme } from '@/src/theme/useTheme';

export default function BookingScreen() {
  const { ulid } = useLocalSearchParams<{ ulid: string }>();
  const { colors, spacing } = useTheme();

  const booking = useQuery({
    queryKey: ['booking', ulid],
    queryFn: async () => {
      const { data } = await api.get<{ data: Booking }>(endpoints.bookings.show(ulid));
      return data.data;
    },
    enabled: Boolean(ulid),
  });

  const events = useQuery({
    queryKey: ['booking-events', ulid],
    queryFn: async () => {
      const { data } = await api.get<{ data: BookingEvent[] }>(endpoints.bookings.events(ulid));
      return data.data;
    },
    enabled: Boolean(ulid),
  });

  if (booking.isLoading) return <Screen><Loading label="Loading shipment" /></Screen>;

  if (booking.isError || !booking.data) {
    return (
      <Screen>
        <ErrorNote message="We could not load that shipment." />
      </Screen>
    );
  }

  const item = booking.data;

  return (
    <Screen>
      <View style={{ gap: spacing.sm }}>
        <Row style={{ justifyContent: 'space-between' }}>
          <Txt variant="label" muted>{item.booking_number}</Txt>
          <Badge label={statusLabel(item.status)} tone={statusTone(item.status)} />
        </Row>
        <Txt variant="display">
          {item.pickup_city} → {item.dropoff_city}
        </Txt>
        {item.service ? <Txt muted>{item.service.name}</Txt> : null}
      </View>

      <Card>
        <Txt variant="heading">Schedule</Txt>
        <Row style={{ justifyContent: 'space-between' }}>
          <Txt muted>Pickup</Txt>
          <Txt variant="label">{item.scheduled_pickup_date ?? 'To be confirmed'}</Txt>
        </Row>
        <Row style={{ justifyContent: 'space-between' }}>
          <Txt muted>Delivery</Txt>
          <Txt variant="label">{item.scheduled_delivery_date ?? 'To be confirmed'}</Txt>
        </Row>
        {item.actual_delivery_at ? (
          <Row style={{ justifyContent: 'space-between' }}>
            <Txt muted>Delivered</Txt>
            <Txt variant="label">{item.actual_delivery_at.slice(0, 10)}</Txt>
          </Row>
        ) : null}
      </Card>

      <Card>
        <Txt variant="heading">Payment</Txt>
        <Row style={{ justifyContent: 'space-between' }}>
          <Txt muted>Total</Txt>
          <Txt variant="label">{formatMoney(item.total_price)}</Txt>
        </Row>
        <Row style={{ justifyContent: 'space-between' }}>
          <Txt muted>Paid</Txt>
          <Txt variant="label">{formatMoney(item.amount_paid)}</Txt>
        </Row>
        <Row style={{ justifyContent: 'space-between' }}>
          <Txt muted>Balance due</Txt>
          <Txt variant="label" style={{ color: item.balance_due.cents > 0 ? colors.warning : colors.success }}>
            {formatMoney(item.balance_due)}
          </Txt>
        </Row>
      </Card>

      {item.can_review ? (
        <Link href={`/review/${item.ulid}`} asChild>
          <Pressable accessibilityRole="button">
            <Button label="Leave a review" />
          </Pressable>
        </Link>
      ) : null}

      <View style={{ gap: spacing.md }}>
        <Txt variant="title">Tracking</Txt>

        {events.isLoading ? (
          <Loading label="Loading timeline" />
        ) : (events.data ?? []).length === 0 ? (
          <Empty title="Nothing to show yet" text="Updates appear here as your vehicle moves." />
        ) : (
          /* The API returns only customer-visible events; internal dispatch notes
             live on the same table and never reach this screen. */
          (events.data ?? []).map((event) => (
            <Card key={event.id}>
              <Row style={{ justifyContent: 'space-between' }}>
                <Txt variant="label">
                  {event.event_type.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())}
                </Txt>
                <Txt variant="caption" muted>{event.occurred_at.slice(0, 16).replace('T', ' ')}</Txt>
              </Row>
              {event.description ? <Txt muted>{event.description}</Txt> : null}
            </Card>
          ))
        )}
      </View>
    </Screen>
  );
}
