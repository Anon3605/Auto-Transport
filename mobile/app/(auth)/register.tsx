import { Link } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, View } from 'react-native';

import { Button, Card, ErrorNote, Field, Row, Screen, Txt } from '@/src/components/ui';
import { fieldErrors, useSession } from '@/src/store/session';
import { useTheme } from '@/src/theme/useTheme';

type AccountType = 'customer' | 'driver';

/** Matches the whitelist in RegisterRequest::SELF_SERVICE_TYPES. */
const CDL_CLASSES = ['A', 'B', 'C', 'none'] as const;

export default function RegisterScreen() {
  const { register } = useSession();
  const { colors, radius, spacing } = useTheme();

  const [accountType, setAccountType] = useState<AccountType>('customer');

  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    license_number: '',
    license_state: '',
    license_expires_at: '',
    cdl_class: '' as '' | (typeof CDL_CLASSES)[number],
  });

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const set = (key: keyof typeof form) => (value: string) =>
    setForm((previous) => ({ ...previous, [key]: value }));

  const isDriver = accountType === 'driver';

  async function submit() {
    // Checked here purely for the instant message; the server re-validates
    // everything, and this layer is never the security boundary.
    if (form.password !== form.password_confirmation) {
      setErrors({ password_confirmation: 'The two passwords do not match.' });
      return;
    }

    setBusy(true);
    setErrors({});
    setMessage(null);

    try {
      await register({
        account_type: accountType,
        name: form.name.trim(),
        email: form.email.trim(),
        password: form.password,
        password_confirmation: form.password_confirmation,
        phone: form.phone.trim() || undefined,

        // Only sent for a driver. A customer must not be asked for a CDL, and
        // sending empty strings would trip the server's required_if rules.
        ...(isDriver
          ? {
              license_number: form.license_number.trim(),
              license_state: form.license_state.trim(),
              license_expires_at: form.license_expires_at.trim(),
              cdl_class: form.cdl_class || undefined,
            }
          : {}),
      });
    } catch (error) {
      const fields = fieldErrors(error);
      setErrors(fields);

      if (Object.keys(fields).length === 0) {
        setMessage((error as { message?: string })?.message ?? 'Could not create your account.');
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <KeyboardAvoidingView
      style={{ flex: 1 }}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <Screen>
        <View style={{ gap: spacing.xs, paddingTop: spacing.lg }}>
          <Txt variant="display">Create your account</Txt>
          <Txt muted>You can get a price without one — an account is for tracking shipments.</Txt>
        </View>

        {/* Account type ------------------------------------------------- */}
        <Card style={{ gap: spacing.md }}>
          <Txt variant="heading">I am a…</Txt>

          <Row accessibilityRole="radiogroup" accessibilityLabel="Account type">
            {(
              [
                { value: 'customer', label: 'Customer', hint: 'Shipping a vehicle' },
                { value: 'driver', label: 'Driver', hint: 'Carrying vehicles' },
              ] as const
            ).map((option) => {
              const selected = accountType === option.value;

              return (
                <Pressable
                  key={option.value}
                  onPress={() => {
                    setAccountType(option.value);
                    setErrors({});
                  }}
                  accessibilityRole="radio"
                  accessibilityState={{ selected }}
                  accessibilityLabel={`${option.label}. ${option.hint}`}
                  style={{
                    flex: 1,
                    padding: spacing.md,
                    borderRadius: radius.md,
                    borderWidth: 1,
                    borderColor: selected ? colors.primary : colors.border,
                    backgroundColor: selected ? colors.primarySoft : 'transparent',
                    gap: 2,
                  }}
                >
                  <Txt variant="label" style={{ color: selected ? colors.primary : colors.text }}>
                    {option.label}
                  </Txt>
                  <Txt variant="caption" muted>
                    {option.hint}
                  </Txt>
                </Pressable>
              );
            })}
          </Row>

          {errors.account_type ? <ErrorNote message={errors.account_type} /> : null}

          {isDriver ? (
            <View
              style={{
                backgroundColor: colors.warningSoft,
                borderRadius: radius.md,
                padding: spacing.md,
                gap: spacing.xs,
              }}
            >
              {/*
                Said before they fill the form, not after. A driver account is
                created pending and cannot be given work until someone verifies
                the licence and links an employer — discovering that from an
                empty job list would read as a broken app.
              */}
              <Txt variant="label" style={{ color: colors.warning }}>
                Driver accounts are checked first
              </Txt>
              <Txt variant="caption" style={{ color: colors.warning }}>
                You can sign in straight away, but we verify your licence and link you
                to a carrier before any work can be assigned to you.
              </Txt>
            </View>
          ) : null}
        </Card>

        {/* Account ------------------------------------------------------ */}
        <Card style={{ gap: spacing.lg }}>
          {message ? <ErrorNote message={message} /> : null}

          <Field
            label="Full name"
            value={form.name}
            onChangeText={set('name')}
            error={errors.name}
            placeholder={isDriver ? 'Marcus Hale' : 'Dana Reyes'}
            autoCapitalize="words"
            autoComplete="name"
            textContentType="name"
          />

          <Field
            label="Email"
            value={form.email}
            onChangeText={set('email')}
            error={errors.email}
            placeholder="you@example.com"
            keyboardType="email-address"
            autoCapitalize="none"
            autoComplete="email"
            textContentType="emailAddress"
            inputMode="email"
          />

          <Field
            label={isDriver ? 'Phone' : 'Phone (optional)'}
            value={form.phone}
            onChangeText={set('phone')}
            error={errors.phone}
            placeholder={isDriver ? 'Required' : 'Optional'}
            hint={
              isDriver
                ? 'Dispatch has to reach you on the day of a collection.'
                : 'Used by dispatch on the day of pickup.'
            }
            keyboardType="phone-pad"
            autoComplete="tel"
            textContentType="telephoneNumber"
          />
        </Card>

        {/* Licence — driver only ---------------------------------------- */}
        {isDriver ? (
          <Card style={{ gap: spacing.lg }}>
            <View style={{ gap: spacing.xs }}>
              <Txt variant="heading">Your licence</Txt>
              <Txt variant="caption" muted>
                We check these before assigning you any work.
              </Txt>
            </View>

            <Field
              label="Licence number"
              value={form.license_number}
              onChangeText={set('license_number')}
              error={errors.license_number}
              placeholder="D1234567"
              autoCapitalize="characters"
            />

            <Field
              label="Issuing state or region"
              value={form.license_state}
              onChangeText={set('license_state')}
              error={errors.license_state}
              placeholder="TX"
              autoCapitalize="characters"
            />

            <Field
              label="Expires on"
              value={form.license_expires_at}
              onChangeText={set('license_expires_at')}
              error={errors.license_expires_at}
              placeholder="YYYY-MM-DD"
              hint="An expired licence cannot be accepted."
            />

            <View style={{ gap: spacing.xs }}>
              <Txt variant="label">Licence class</Txt>
              {/* Chips, not free text: "class a" and "A" would otherwise both
                  end up in the column and neither would be queryable. */}
              <Row style={{ flexWrap: 'wrap' }} accessibilityRole="radiogroup" accessibilityLabel="Licence class">
                {CDL_CLASSES.map((value) => {
                  const selected = form.cdl_class === value;
                  const label = value === 'none' ? 'No CDL' : `Class ${value}`;

                  return (
                    <Pressable
                      key={value}
                      onPress={() => setForm((p) => ({ ...p, cdl_class: value }))}
                      accessibilityRole="radio"
                      accessibilityState={{ selected }}
                      accessibilityLabel={label}
                      style={{
                        paddingHorizontal: spacing.md,
                        paddingVertical: spacing.sm,
                        borderRadius: 999,
                        marginRight: spacing.xs,
                        marginBottom: spacing.xs,
                        backgroundColor: selected ? colors.primary : colors.surfaceAlt,
                      }}
                    >
                      <Txt style={{ color: selected ? colors.primaryText : colors.text }}>{label}</Txt>
                    </Pressable>
                  );
                })}
              </Row>
              {errors.cdl_class ? (
                <Txt variant="caption" style={{ color: colors.danger }}>
                  {errors.cdl_class}
                </Txt>
              ) : null}
            </View>
          </Card>
        ) : null}

        {/* Password ----------------------------------------------------- */}
        <Card style={{ gap: spacing.lg }}>
          <Field
            label="Password"
            value={form.password}
            onChangeText={set('password')}
            error={errors.password}
            placeholder="At least 8 characters, with a number"
            secureTextEntry
            autoCapitalize="none"
            // new-password, not password: this is what prompts the platform
            // password manager to offer to generate and save one.
            autoComplete="new-password"
            textContentType="newPassword"
          />

          <Field
            label="Confirm password"
            value={form.password_confirmation}
            onChangeText={set('password_confirmation')}
            error={errors.password_confirmation}
            placeholder="Type it again"
            secureTextEntry
            autoCapitalize="none"
            autoComplete="new-password"
            textContentType="newPassword"
            returnKeyType="go"
            onSubmitEditing={submit}
          />

          <Button
            label={isDriver ? 'Apply as a driver' : 'Create account'}
            onPress={submit}
            loading={busy}
          />
        </Card>

        <View style={{ alignItems: 'center' }}>
          <Link href="/(auth)/login" asChild>
            <Txt style={{ textDecorationLine: 'underline' }}>Already registered? Sign in</Txt>
          </Link>
        </View>
      </Screen>
    </KeyboardAvoidingView>
  );
}
