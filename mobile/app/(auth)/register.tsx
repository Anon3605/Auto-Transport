import { Link } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, View } from 'react-native';

import { Button, Card, ErrorNote, Field, Screen, Txt } from '@/src/components/ui';
import { fieldErrors, useSession } from '@/src/store/session';
import { useTheme } from '@/src/theme/useTheme';

export default function RegisterScreen() {
  const { register } = useSession();
  const { spacing } = useTheme();

  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const set = (key: keyof typeof form) => (value: string) =>
    setForm((previous) => ({ ...previous, [key]: value }));

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
        name: form.name.trim(),
        email: form.email.trim(),
        password: form.password,
        password_confirmation: form.password_confirmation,
        phone: form.phone.trim() || undefined,
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
        <View style={{ gap: spacing.xs, paddingTop: spacing.xl }}>
          <Txt variant="display">Create your account</Txt>
          <Txt muted>You can get a price without one — an account is for tracking shipments.</Txt>
        </View>

        <Card style={{ gap: spacing.lg }}>
          {message ? <ErrorNote message={message} /> : null}

          <Field
            label="Full name"
            value={form.name}
            onChangeText={set('name')}
            error={errors.name}
            placeholder="Dana Reyes"
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
            label="Phone"
            value={form.phone}
            onChangeText={set('phone')}
            error={errors.phone}
            placeholder="Optional"
            hint="Used by dispatch on the day of pickup."
            keyboardType="phone-pad"
            autoComplete="tel"
            textContentType="telephoneNumber"
          />

          <Field
            label="Password"
            value={form.password}
            onChangeText={set('password')}
            error={errors.password}
            placeholder="At least 8 characters"
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

          <Button label="Create account" onPress={submit} loading={busy} />
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
