import { useQuery } from '@tanstack/react-query';
import { Link, useLocalSearchParams } from 'expo-router';
import { Pressable, View } from 'react-native';

import { api } from '@/src/api/client';
import { endpoints } from '@/src/api/endpoints';
import { Badge, Button, Card, Empty, ErrorNote, Loading, Row, Screen, Stars, Txt } from '@/src/components/ui';
import { formatMoney, type Review, type Service } from '@/src/types/api';
import { useTheme } from '@/src/theme/useTheme';

export default function ServiceScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const { spacing } = useTheme();

  const service = useQuery({
    queryKey: ['service', slug],
    queryFn: async () => {
      const { data } = await api.get<{ data: Service }>(endpoints.catalog.service(slug));
      return data.data;
    },
    enabled: Boolean(slug),
  });

  const reviews = useQuery({
    queryKey: ['service-reviews', slug],
    queryFn: async () => {
      const { data } = await api.get<{ data: Review[] }>(endpoints.reviews.forService(slug));
      return data.data;
    },
    enabled: Boolean(slug),
  });

  if (service.isLoading) return <Screen><Loading label="Loading service" /></Screen>;

  if (service.isError || !service.data) {
    return (
      <Screen>
        <ErrorNote message="We could not load that service." />
      </Screen>
    );
  }

  const item = service.data;

  return (
    <Screen>
      <View style={{ gap: spacing.sm }}>
        <Txt variant="display">{item.name}</Txt>
        {item.rating_count > 0 ? (
          <Row>
            <Stars value={item.rating_avg} size={18} />
            <Txt muted>
              {item.rating_avg.toFixed(1)} · {item.rating_count}{' '}
              {item.rating_count === 1 ? 'review' : 'reviews'}
            </Txt>
          </Row>
        ) : (
          <Txt muted>No reviews yet.</Txt>
        )}
      </View>

      {item.short_description ? <Txt>{item.short_description}</Txt> : null}

      <Card>
        <Txt variant="heading">Pricing</Txt>
        <Row style={{ justifyContent: 'space-between' }}>
          <Txt muted>Base price</Txt>
          <Txt variant="label">{formatMoney(item.base_price)}</Txt>
        </Row>
        <Row style={{ justifyContent: 'space-between' }}>
          <Txt muted>Per mile</Txt>
          <Txt variant="label">{formatMoney(item.price_per_mile)}</Txt>
        </Row>
        {item.transit_days_min && item.transit_days_max ? (
          <Row style={{ justifyContent: 'space-between' }}>
            <Txt muted>Typical transit</Txt>
            <Txt variant="label">{item.transit_days_min}–{item.transit_days_max} days</Txt>
          </Row>
        ) : null}
        {/* The honest caveat the design doc insists on: an estimate is not an offer. */}
        <Txt variant="caption" muted>
          Indicative only. Your quote depends on the route, the vehicle and whether it runs.
        </Txt>
      </Card>

      {/* The call to action. Placed straight after pricing rather than at the
          bottom of the reviews, so it is reachable without scrolling past every
          testimonial on a service with a lot of them. */}
      <Link href={`/book/${item.slug}`} asChild>
        <Pressable accessibilityRole="button" accessibilityLabel={`Book ${item.name}`}>
          <Button label={`Book ${item.name}`} />
        </Pressable>
      </Link>

      <View style={{ gap: spacing.md }}>
        <Txt variant="title">What customers said</Txt>

        {reviews.isLoading ? (
          <Loading label="Loading reviews" />
        ) : (reviews.data ?? []).length === 0 ? (
          <Empty title="No reviews yet" text="Reviews appear here once a shipment is delivered and checked." />
        ) : (
          (reviews.data ?? []).map((review) => (
            <Card key={review.ulid}>
              <Row style={{ justifyContent: 'space-between' }}>
                <Stars value={review.rating_overall} />
                <Badge label="Verified" tone="success" />
              </Row>

              {review.title ? <Txt variant="heading">{review.title}</Txt> : null}
              {review.body ? <Txt muted>{review.body}</Txt> : null}

              {/* Author is already truncated server-side to "Dana W." — the full
                  name never leaves the database. */}
              <Txt variant="caption" muted>
                {review.author_name} · {review.created_at.slice(0, 10)}
              </Txt>

              {review.admin_reply ? (
                <View style={{ gap: spacing.xs, marginTop: spacing.xs }}>
                  <Txt variant="label">Our reply</Txt>
                  <Txt muted>{review.admin_reply}</Txt>
                </View>
              ) : null}
            </Card>
          ))
        )}
      </View>
    </Screen>
  );
}
