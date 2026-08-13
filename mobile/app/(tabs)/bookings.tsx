import { useQuery } from '@tanstack/react-query';
import { Link } from 'expo-router';
import { Pressable, View } from 'react-native';

import { api } from '@/src/api/client';
import { endpoints } from '@/src/api/endpoints';
import { Badge, Button, Card, Empty, ErrorNote, Loading, Row, Screen, Txt } from '@/src/components/ui';
import { statusLabel, statusTone } from '@/src/lib/booking';
import { formatMoney, type Booking } from '@/src/types/api';
import { useTheme } from '@/src/theme/useTheme';

export default function BookingsScreen() {
  const { spacing } = useTheme();

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['bookings'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Booking[] }>(endpoints.bookings.index);
      return data.data;
    },
  });

  if (isLoading) return <Screen><Loading label="Loading your shipments" /></Screen>;

  if (isError) {
    return (
      <Screen>
        <ErrorNote message={(error as { message?: string })?.message ?? 'Could not load your shipments.'} />
        <Button label="Try again" variant="secondary" onPress={() => refetch()} />
      </Screen>
    );
  }

  const bookings = data ?? [];

  return (
    <Screen>
      <Txt variant="display">Your shipments</Txt>

      {bookings.length === 0 ? (
        <Empty
          title="No shipments yet"
          text="Once a quote is accepted it becomes a shipment and appears here."
        />
      ) : (
        bookings.map((booking) => (
          <Card key={booking.ulid}>
            <Row style={{ justifyContent: 'space-between' }}>
              <Txt variant="label" muted>{booking.booking_number}</Txt>
              <Badge label={statusLabel(booking.status)} tone={statusTone(booking.status)} />
            </Row>

            <Txt variant="heading">
              {booking.pickup_city} → {booking.dropoff_city}
            </Txt>

            <Row style={{ justifyContent: 'space-between' }}>
              <Txt muted>
                {booking.scheduled_pickup_date
                  ? `Pickup ${booking.scheduled_pickup_date}`
                  : 'Pickup date to be confirmed'}
              </Txt>
              <Txt variant="label">{formatMoney(booking.total_price)}</Txt>
            </Row>

            <View style={{ gap: spacing.sm, marginTop: spacing.sm }}>
              <Link href={`/booking/${booking.ulid}`} asChild>
                <Pressable accessibilityRole="button">
                  <Button label="Track shipment" variant="secondary" />
                </Pressable>
              </Link>

              {/*
                can_review is computed by the server (delivered AND no existing
                review). Deciding it on the client would mean duplicating the rule
                and getting it wrong the first time the status machine changes.
              */}
              {booking.can_review ? (
                <Link href={`/review/${booking.ulid}`} asChild>
                  <Pressable accessibilityRole="button">
                    <Button label="Leave a review" />
                  </Pressable>
                </Link>
              ) : null}
            </View>
          </Card>
        ))
      )}
    </Screen>
  );
}
