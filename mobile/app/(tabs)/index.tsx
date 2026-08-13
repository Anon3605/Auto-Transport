import { useQuery } from '@tanstack/react-query';
import { Link } from 'expo-router';
import { Pressable, View } from 'react-native';

import { api } from '@/src/api/client';
import { endpoints } from '@/src/api/endpoints';
import { Badge, Button, Card, Empty, ErrorNote, Loading, Row, Screen, Stars, Txt } from '@/src/components/ui';
import { formatMoney, type Service } from '@/src/types/api';
import { useTheme } from '@/src/theme/useTheme';

export default function ServicesScreen() {
  const { spacing } = useTheme();

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['services'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Service[] }>(endpoints.catalog.services);
      return data.data;
    },
  });

  if (isLoading) return <Screen><Loading label="Loading services" /></Screen>;

  if (isError) {
    return (
      <Screen>
        <ErrorNote message={(error as { message?: string })?.message ?? 'Could not load services.'} />
      </Screen>
    );
  }

  const services = data ?? [];

  return (
    <Screen>
      <View style={{ gap: spacing.xs }}>
        <Txt variant="display">How we ship</Txt>
        <Txt muted>
          Pick a service to see pricing, transit times and what other customers said.
        </Txt>
      </View>

      {services.length === 0 ? (
        <Empty title="No services listed" text="Check back shortly." />
      ) : (
        services.map((service) => (
          /*
           * Two explicit actions rather than a tappable card wrapping a button.
           * Nesting a Pressable inside a Pressable makes the inner press
           * ambiguous on Android, and "read about it" and "book it" are
           * different enough intents to deserve their own targets.
           */
          <Card key={service.id}>
            <Row style={{ justifyContent: 'space-between' }}>
              <Txt variant="heading" style={{ flex: 1 }}>{service.name}</Txt>
              {service.rating_count > 0 ? (
                <Row>
                  <Stars value={service.rating_avg} size={13} />
                  <Txt variant="caption" muted>({service.rating_count})</Txt>
                </Row>
              ) : null}
            </Row>

            {service.short_description ? (
              <Txt muted numberOfLines={2}>{service.short_description}</Txt>
            ) : null}

            <Row style={{ flexWrap: 'wrap', marginTop: spacing.xs }}>
              {/* Money arrives as integer minor units; formatMoney is the only
                  thing that turns it into text (see types/api.ts). */}
              <Badge label={`From ${formatMoney(service.base_price)}`} tone="primary" />
              {service.transit_days_min && service.transit_days_max ? (
                <Badge label={`${service.transit_days_min}–${service.transit_days_max} days`} />
              ) : null}
            </Row>

            <Row style={{ marginTop: spacing.sm }}>
              <View style={{ flex: 1 }}>
                <Link href={`/service/${service.slug}`} asChild>
                  <Pressable accessibilityRole="button" accessibilityLabel={`${service.name}, details and reviews`}>
                    <Button label="Details" variant="secondary" />
                  </Pressable>
                </Link>
              </View>
              <View style={{ flex: 1 }}>
                <Link href={`/book/${service.slug}`} asChild>
                  <Pressable accessibilityRole="button" accessibilityLabel={`Book ${service.name}`}>
                    <Button label="Book" />
                  </Pressable>
                </Link>
              </View>
            </Row>
          </Card>
        ))
      )}
    </Screen>
  );
}
