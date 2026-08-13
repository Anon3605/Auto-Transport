import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, View } from 'react-native';

import { api } from '@/src/api/client';
import { endpoints } from '@/src/api/endpoints';
import { Badge, Button, Card, ErrorNote, Field, Loading, Row, Screen, Txt } from '@/src/components/ui';
import { fieldErrors } from '@/src/store/session';
import { useTheme } from '@/src/theme/useTheme';
import { formatMoney, type Booking, type Service, type VehicleType } from '@/src/types/api';

interface VehicleDraft {
  vehicle_type_id: number | null;
  year: string;
  make: string;
  model: string;
  is_operable: boolean;
}

const emptyVehicle: VehicleDraft = {
  vehicle_type_id: null,
  year: '',
  make: '',
  model: '',
  is_operable: true,
};

/** YYYY-MM-DD without pulling in a date library for one call. */
function isoDate(daysFromNow: number): string {
  const d = new Date();
  d.setDate(d.getDate() + daysFromNow);
  return d.toISOString().slice(0, 10);
}

export default function BookScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const router = useRouter();
  const queryClient = useQueryClient();
  const { colors, spacing } = useTheme();

  /*
   * The service arrives from the Services tab, but it stays editable here.
   * Tapping the wrong card otherwise means backing out and losing a
   * half-filled form, and "which transport option" is exactly the decision a
   * customer changes their mind about once they see the price.
   */
  const [selectedSlug, setSelectedSlug] = useState<string>(slug);

  const [pickup, setPickup] = useState({ line1: '', city: '', state: '', postal_code: '' });
  const [dropoff, setDropoff] = useState({ line1: '', city: '', state: '', postal_code: '' });
  const [pickupDate, setPickupDate] = useState(isoDate(7));
  const [vehicles, setVehicles] = useState<VehicleDraft[]>([{ ...emptyVehicle }]);
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const service = useQuery({
    queryKey: ['service', selectedSlug],
    queryFn: async () => {
      const { data } = await api.get<{ data: Service }>(endpoints.catalog.service(selectedSlug));
      return data.data;
    },
    enabled: Boolean(selectedSlug),
  });

  // Powers the switcher below. Already cached by the Services tab in most cases.
  const services = useQuery({
    queryKey: ['services'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Service[] }>(endpoints.catalog.services);
      return data.data;
    },
  });

  const vehicleTypes = useQuery({
    queryKey: ['vehicle-types'],
    queryFn: async () => {
      const { data } = await api.get<{ data: VehicleType[] }>(endpoints.catalog.vehicleTypes);
      return data.data;
    },
  });

  const mutation = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: Booking }>(endpoints.bookings.create, {
        service_slug: selectedSlug,
        pickup: { ...pickup, country_code: 'US', location_type: 'residential' },
        dropoff: { ...dropoff, country_code: 'US', location_type: 'residential' },
        pickup_date_earliest: pickupDate,
        dates_flexible: true,
        vehicles: vehicles.map((v) => ({
          vehicle_type_id: v.vehicle_type_id,
          // Blank stays blank rather than becoming 0 or NaN, which the API
          // would reject as an out-of-range year.
          year: v.year.trim() === '' ? null : Number(v.year),
          make: v.make.trim() || null,
          model: v.model.trim() || null,
          is_operable: v.is_operable,
        })),
        additional_notes: notes.trim() || undefined,
      });
      return data.data;
    },
    onSuccess: (booking) => {
      // The shipments tab is the destination; drop its cache so the new row is
      // there when the user lands rather than after a manual pull-to-refresh.
      queryClient.invalidateQueries({ queryKey: ['bookings'] });
      router.replace(`/booking/${booking.ulid}`);
    },
    onError: (error) => setErrors(fieldErrors(error)),
  });

  if (service.isLoading) return <Screen><Loading label="Loading service" /></Screen>;

  if (service.isError || !service.data) {
    return <Screen><ErrorNote message="We could not load that service." /></Screen>;
  }

  const setVehicle = (index: number, patch: Partial<VehicleDraft>) =>
    setVehicles((list) => list.map((v, i) => (i === index ? { ...v, ...patch } : v)));

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen>
        <View style={{ gap: spacing.xs }}>
          <Txt variant="display">Book a shipment</Txt>
          <Txt muted>
            {service.data.name} — from {formatMoney(service.data.base_price)} plus{' '}
            {formatMoney(service.data.price_per_mile)} per mile.
          </Txt>
        </View>

        {/* Transport option, changeable without leaving the form. */}
        <Card style={{ gap: spacing.sm }}>
          <Txt variant="heading">Transport option</Txt>
          {errors.service_slug ? <ErrorNote message={errors.service_slug} /> : null}

          <Row style={{ flexWrap: 'wrap' }}>
            {(services.data ?? []).map((option) => {
              const selected = option.slug === selectedSlug;
              return (
                <Pressable
                  key={option.id}
                  onPress={() => setSelectedSlug(option.slug)}
                  accessibilityRole="radio"
                  accessibilityState={{ selected }}
                  accessibilityLabel={`${option.name}, from ${formatMoney(option.base_price)}`}
                  style={{
                    paddingHorizontal: spacing.md,
                    paddingVertical: spacing.sm,
                    borderRadius: 999,
                    marginRight: spacing.xs,
                    marginBottom: spacing.xs,
                    backgroundColor: selected ? colors.primary : colors.surfaceAlt,
                  }}
                >
                  <Txt style={{ color: selected ? colors.primaryText : colors.text }}>
                    {option.name}
                  </Txt>
                </Pressable>
              );
            })}
          </Row>

          {service.data.transit_days_min && service.data.transit_days_max ? (
            <Txt variant="caption" muted>
              Typically {service.data.transit_days_min}–{service.data.transit_days_max} days in transit.
            </Txt>
          ) : null}
        </Card>

        {/* Collection ------------------------------------------------------ */}
        <Card style={{ gap: spacing.lg }}>
          <Txt variant="heading">Collection</Txt>

          <Field
            label="Street address"
            value={pickup.line1}
            onChangeText={(v) => setPickup((p) => ({ ...p, line1: v }))}
            error={errors['pickup.line1']}
            placeholder="901 E 6th St"
          />
          <Row style={{ alignItems: 'flex-start' }}>
            <View style={{ flex: 2 }}>
              <Field
                label="City"
                value={pickup.city}
                onChangeText={(v) => setPickup((p) => ({ ...p, city: v }))}
                error={errors['pickup.city']}
                placeholder="Austin"
              />
            </View>
            <View style={{ flex: 1 }}>
              <Field
                label="State"
                value={pickup.state}
                onChangeText={(v) => setPickup((p) => ({ ...p, state: v }))}
                error={errors['pickup.state']}
                placeholder="TX"
                autoCapitalize="characters"
                maxLength={2}
              />
            </View>
          </Row>
          <Field
            label="ZIP"
            value={pickup.postal_code}
            onChangeText={(v) => setPickup((p) => ({ ...p, postal_code: v }))}
            error={errors['pickup.postal_code']}
            placeholder="78701"
            keyboardType="number-pad"
            maxLength={10}
          />
        </Card>

        {/* Delivery -------------------------------------------------------- */}
        <Card style={{ gap: spacing.lg }}>
          <Txt variant="heading">Delivery</Txt>

          <Field
            label="Street address"
            value={dropoff.line1}
            onChangeText={(v) => setDropoff((p) => ({ ...p, line1: v }))}
            error={errors['dropoff.line1']}
            placeholder="1701 Wynkoop St"
          />
          <Row style={{ alignItems: 'flex-start' }}>
            <View style={{ flex: 2 }}>
              <Field
                label="City"
                value={dropoff.city}
                onChangeText={(v) => setDropoff((p) => ({ ...p, city: v }))}
                error={errors['dropoff.city']}
                placeholder="Denver"
              />
            </View>
            <View style={{ flex: 1 }}>
              <Field
                label="State"
                value={dropoff.state}
                onChangeText={(v) => setDropoff((p) => ({ ...p, state: v }))}
                error={errors['dropoff.state']}
                placeholder="CO"
                autoCapitalize="characters"
                maxLength={2}
              />
            </View>
          </Row>
          <Field
            label="ZIP"
            value={dropoff.postal_code}
            onChangeText={(v) => setDropoff((p) => ({ ...p, postal_code: v }))}
            error={errors['dropoff.postal_code']}
            placeholder="80202"
            keyboardType="number-pad"
            maxLength={10}
          />
        </Card>

        <Card>
          <Field
            label="Earliest collection date"
            value={pickupDate}
            onChangeText={setPickupDate}
            error={errors.pickup_date_earliest}
            placeholder="YYYY-MM-DD"
            hint="We treat your dates as flexible unless you tell us otherwise."
          />
        </Card>

        {/* Vehicles -------------------------------------------------------- */}
        <Card style={{ gap: spacing.lg }}>
          <Row style={{ justifyContent: 'space-between' }}>
            <Txt variant="heading">Vehicles</Txt>
            <Txt variant="caption" muted>{vehicles.length} of 8</Txt>
          </Row>

          {errors.vehicles ? <ErrorNote message={errors.vehicles} /> : null}

          {vehicles.map((vehicle, index) => (
            <View key={index} style={{ gap: spacing.md, paddingTop: index > 0 ? spacing.md : 0 }}>
              {index > 0 ? (
                <Row style={{ justifyContent: 'space-between' }}>
                  <Txt variant="label" muted>Vehicle {index + 1}</Txt>
                  <Pressable
                    onPress={() => setVehicles((l) => l.filter((_, i) => i !== index))}
                    accessibilityRole="button"
                    accessibilityLabel={`Remove vehicle ${index + 1}`}
                  >
                    <Txt style={{ color: colors.danger }}>Remove</Txt>
                  </Pressable>
                </Row>
              ) : null}

              <View style={{ gap: spacing.xs }}>
                <Txt variant="label">Type</Txt>
                {/* Chips, not a picker: @react-native-picker is another native
                    dependency, and five options fit on screen anyway. */}
                <Row style={{ flexWrap: 'wrap' }}>
                  {(vehicleTypes.data ?? []).map((type) => {
                    const selected = vehicle.vehicle_type_id === type.id;
                    return (
                      <Pressable
                        key={type.id}
                        onPress={() => setVehicle(index, { vehicle_type_id: type.id })}
                        accessibilityRole="radio"
                        accessibilityState={{ selected }}
                        accessibilityLabel={type.name}
                        style={{
                          paddingHorizontal: spacing.md,
                          paddingVertical: spacing.sm,
                          borderRadius: 999,
                          marginRight: spacing.xs,
                          marginBottom: spacing.xs,
                          backgroundColor: selected ? colors.primary : colors.surfaceAlt,
                        }}
                      >
                        <Txt style={{ color: selected ? colors.primaryText : colors.text }}>
                          {type.name}
                        </Txt>
                      </Pressable>
                    );
                  })}
                </Row>
                {errors[`vehicles.${index}.vehicle_type_id`] ? (
                  <Txt variant="caption" style={{ color: colors.danger }}>
                    {errors[`vehicles.${index}.vehicle_type_id`]}
                  </Txt>
                ) : null}
              </View>

              <Row style={{ alignItems: 'flex-start' }}>
                <View style={{ flex: 1 }}>
                  <Field
                    label="Year"
                    value={vehicle.year}
                    onChangeText={(v) => setVehicle(index, { year: v })}
                    error={errors[`vehicles.${index}.year`]}
                    placeholder="2019"
                    keyboardType="number-pad"
                    maxLength={4}
                  />
                </View>
                <View style={{ flex: 1.4 }}>
                  <Field
                    label="Make"
                    value={vehicle.make}
                    onChangeText={(v) => setVehicle(index, { make: v })}
                    error={errors[`vehicles.${index}.make`]}
                    placeholder="Subaru"
                  />
                </View>
                <View style={{ flex: 1.4 }}>
                  <Field
                    label="Model"
                    value={vehicle.model}
                    onChangeText={(v) => setVehicle(index, { model: v })}
                    error={errors[`vehicles.${index}.model`]}
                    placeholder="Outback"
                  />
                </View>
              </Row>

              {/*
                Not a cosmetic toggle: a non-running vehicle needs a winch, which
                changes the price by 35% and limits which carriers can take it.
              */}
              <Pressable
                onPress={() => setVehicle(index, { is_operable: !vehicle.is_operable })}
                accessibilityRole="switch"
                accessibilityState={{ checked: vehicle.is_operable }}
                accessibilityLabel="Vehicle starts and drives"
                style={{ paddingVertical: spacing.sm }}
              >
                <Row style={{ justifyContent: 'space-between' }}>
                  <View style={{ flex: 1 }}>
                    <Txt>It starts and drives</Txt>
                    <Txt variant="caption" muted>
                      A non-runner needs a winch and costs more.
                    </Txt>
                  </View>
                  <Badge
                    label={vehicle.is_operable ? 'Runs' : 'Non-runner'}
                    tone={vehicle.is_operable ? 'success' : 'warning'}
                  />
                </Row>
              </Pressable>
            </View>
          ))}

          {vehicles.length < 8 ? (
            <Button
              label="Add another vehicle"
              variant="ghost"
              onPress={() => setVehicles((l) => [...l, { ...emptyVehicle }])}
            />
          ) : null}
        </Card>

        <Card>
          <Field
            label="Anything we should know?"
            value={notes}
            onChangeText={setNotes}
            error={errors.additional_notes}
            placeholder="Gate codes, low clearance, preferred contact times"
            multiline
            numberOfLines={4}
            maxLength={2000}
            style={{ minHeight: 100, textAlignVertical: 'top' }}
          />
        </Card>

        {mutation.isError && Object.keys(errors).length === 0 ? (
          <ErrorNote
            message={(mutation.error as { message?: string })?.message ?? 'Could not create your booking.'}
          />
        ) : null}

        <Button label="Confirm booking" onPress={() => { setErrors({}); mutation.mutate(); }} loading={mutation.isPending} />

        {/*
          Said plainly before they commit. §7 is explicit that the automated
          figure is not a binding quote, and a customer who thinks it is will be
          angry at the confirmation call rather than at this sentence.
        */}
        <Txt variant="caption" muted style={{ textAlign: 'center' }}>
          The price is an automated estimate and is confirmed by our team before
          collection. Your shipment appears under Shipments straight away.
        </Txt>
      </Screen>
    </KeyboardAvoidingView>
  );
}
