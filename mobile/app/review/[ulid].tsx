import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, View } from 'react-native';

import { api } from '@/src/api/client';
import { endpoints } from '@/src/api/endpoints';
import { Button, Card, ErrorNote, Field, Loading, Row, Screen, Txt } from '@/src/components/ui';
import { fieldErrors } from '@/src/store/session';
import { HIT_TARGET } from '@/src/theme/tokens';
import { useTheme } from '@/src/theme/useTheme';
import type { Booking } from '@/src/types/api';

/**
 * The post-service review.
 *
 * Reachable only from a delivered shipment the caller owns. The server decides
 * eligibility (`can_review`) and re-checks it on submit -- this screen is the
 * form, not the gate.
 */
export default function ReviewScreen() {
  const { ulid } = useLocalSearchParams<{ ulid: string }>();
  const router = useRouter();
  const queryClient = useQueryClient();
  const { colors, spacing } = useTheme();

  const [overall, setOverall] = useState(0);
  const [sub, setSub] = useState({
    rating_communication: 0,
    rating_timeliness: 0,
    rating_condition: 0,
    rating_value: 0,
  });
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const booking = useQuery({
    queryKey: ['booking', ulid],
    queryFn: async () => {
      const { data } = await api.get<{ data: Booking }>(endpoints.bookings.show(ulid));
      return data.data;
    },
    enabled: Boolean(ulid),
  });

  const mutation = useMutation({
    mutationFn: async () => {
      const { data } = await api.post(endpoints.reviews.store, {
        booking_ulid: ulid,
        rating_overall: overall,
        // 0 means "not answered". Sending it would fail the between:1,5 rule, and
        // null is what the API documents as skipped.
        ...Object.fromEntries(
          Object.entries(sub).map(([key, value]) => [key, value === 0 ? null : value]),
        ),
        title: title.trim() || null,
        body: body.trim() || null,
      });
      return data;
    },
    onSuccess: () => {
      // The list shows a "Leave a review" button driven by can_review, which has
      // just changed server-side; drop both so it disappears without a manual pull.
      queryClient.invalidateQueries({ queryKey: ['bookings'] });
      queryClient.invalidateQueries({ queryKey: ['booking', ulid] });
      router.back();
    },
    onError: (error) => setErrors(fieldErrors(error)),
  });

  if (booking.isLoading) return <Screen><Loading label="Loading shipment" /></Screen>;

  if (booking.isError) {
    return (
      <Screen>
        <ErrorNote message="We could not load that shipment." />
      </Screen>
    );
  }

  const shipment = booking.data;

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen>
        {shipment ? (
          <View style={{ gap: spacing.xs }}>
            <Txt variant="title">
              {shipment.pickup_city} → {shipment.dropoff_city}
            </Txt>
            <Txt muted>
              {shipment.booking_number}
              {shipment.actual_delivery_at ? ` · delivered ${shipment.actual_delivery_at.slice(0, 10)}` : ''}
            </Txt>
          </View>
        ) : null}

        {/* The one required answer. */}
        <Card>
          <Txt variant="heading">Overall rating</Txt>
          <StarPicker value={overall} onChange={setOverall} label="Overall rating" />
          {errors.rating_overall ? (
            <Txt variant="caption" style={{ color: colors.danger }}>{errors.rating_overall}</Txt>
          ) : null}
          {errors.booking_ulid ? <ErrorNote message={errors.booking_ulid} /> : null}
        </Card>

        <Card style={{ gap: spacing.lg }}>
          <View style={{ gap: spacing.xs }}>
            <Txt variant="heading">Rate the details</Txt>
            <Txt variant="caption" muted>Optional — skip any that do not apply.</Txt>
          </View>

          <SubRating
            label="Communication"
            value={sub.rating_communication}
            onChange={(v) => setSub((s) => ({ ...s, rating_communication: v }))}
          />
          <SubRating
            label="Timeliness"
            value={sub.rating_timeliness}
            onChange={(v) => setSub((s) => ({ ...s, rating_timeliness: v }))}
          />
          {/* The commercially decisive one: did the car arrive as it left? */}
          <SubRating
            label="Vehicle condition"
            value={sub.rating_condition}
            onChange={(v) => setSub((s) => ({ ...s, rating_condition: v }))}
          />
          <SubRating
            label="Value for money"
            value={sub.rating_value}
            onChange={(v) => setSub((s) => ({ ...s, rating_value: v }))}
          />
        </Card>

        <Card style={{ gap: spacing.lg }}>
          <Field
            label="Headline"
            value={title}
            onChangeText={setTitle}
            error={errors.title}
            placeholder="Optional"
            maxLength={160}
          />

          <Field
            label="Your review"
            value={body}
            onChangeText={setBody}
            error={errors.body}
            placeholder="What went well, what could have gone better?"
            multiline
            numberOfLines={6}
            maxLength={5000}
            style={{ minHeight: 140, textAlignVertical: 'top' }}
          />
        </Card>

        {mutation.isError && Object.keys(errors).length === 0 ? (
          <ErrorNote
            message={(mutation.error as { message?: string })?.message ?? 'Could not submit your review.'}
          />
        ) : null}

        <Button
          label="Submit review"
          onPress={() => {
            if (overall === 0) {
              setErrors({ rating_overall: 'Pick a rating.' });
              return;
            }
            setErrors({});
            mutation.mutate();
          }}
          loading={mutation.isPending}
        />

        {/* Stated up front rather than discovered later when it fails to appear. */}
        <Txt variant="caption" muted style={{ textAlign: 'center' }}>
          Reviews are checked before they appear publicly. Yours is tied to this
          shipment, so it shows as verified.
        </Txt>
      </Screen>
    </KeyboardAvoidingView>
  );
}

/**
 * Tappable stars.
 *
 * Each star is its own button with its own label ("3 of 5 stars") rather than
 * one control with a swipe gesture: a discrete button is reachable by switch
 * control and by a screen reader, and it works with a thumb in a cold car park,
 * which is roughly where this form gets filled in.
 */
function StarPicker({
  value,
  onChange,
  label,
  size = 34,
}: {
  value: number;
  onChange: (value: number) => void;
  label: string;
  size?: number;
}) {
  const { colors, spacing } = useTheme();

  return (
    <Row style={{ gap: spacing.xs }} accessibilityRole="radiogroup" accessibilityLabel={label}>
      {[1, 2, 3, 4, 5].map((n) => (
        <Pressable
          key={n}
          onPress={() => onChange(n)}
          accessibilityRole="radio"
          accessibilityState={{ selected: value === n }}
          accessibilityLabel={`${n} of 5 stars`}
          hitSlop={6}
          style={{
            minWidth: HIT_TARGET,
            minHeight: HIT_TARGET,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Txt style={{ fontSize: size, color: n <= value ? colors.star : colors.border }}>★</Txt>
        </Pressable>
      ))}
    </Row>
  );
}

function SubRating({
  label,
  value,
  onChange,
}: {
  label: string;
  value: number;
  onChange: (value: number) => void;
}) {
  const { spacing } = useTheme();

  return (
    <View style={{ gap: spacing.xs }}>
      <Txt variant="label">{label}</Txt>
      <StarPicker value={value} onChange={onChange} label={label} size={26} />
    </View>
  );
}
