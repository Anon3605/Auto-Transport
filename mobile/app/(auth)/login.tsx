import { Link } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, View } from 'react-native';

import { Button, Card, ErrorNote, Field, Screen, Txt } from '@/src/components/ui';
import { fieldErrors, useSession } from '@/src/store/session';
import { useTheme } from '@/src/theme/useTheme';

export default function LoginScreen() {
  const { signIn } = useSession();
  const { spacing } = useTheme();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setErrors({});
    setMessage(null);

    try {
      await signIn(email.trim(), password);
      // No navigation call: Stack.Protected re-renders once `user` is set and
      // the tab group becomes the reachable one on its own.
    } catch (error) {
      const fields = fieldErrors(error);
      setErrors(fields);

      /*
       * Only surface a banner when the failure was not attributable to a field,
       * otherwise the same problem is stated twice. The server answers a bad
       * email and a bad password identically -- confirming which half was right
       * turns the form into an account-enumeration oracle.
       */
      if (Object.keys(fields).length === 0) {
        setMessage((error as { message?: string })?.message ?? 'Could not sign in.');
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
        <View style={{ gap: spacing.xs, paddingTop: spacing.xxxl }}>
          <Txt variant="display">Welcome back</Txt>
          <Txt muted>Track your shipments and review completed moves.</Txt>
        </View>

        <Card style={{ gap: spacing.lg }}>
          {message ? <ErrorNote message={message} /> : null}

          <Field
            label="Email"
            value={email}
            onChangeText={setEmail}
            error={errors.email}
            placeholder="you@example.com"
            keyboardType="email-address"
            autoCapitalize="none"
            autoComplete="email"
            textContentType="emailAddress"
            inputMode="email"
            returnKeyType="next"
          />

          <Field
            label="Password"
            value={password}
            onChangeText={setPassword}
            error={errors.password}
            placeholder="Your password"
            secureTextEntry
            autoCapitalize="none"
            autoComplete="current-password"
            textContentType="password"
            returnKeyType="go"
            onSubmitEditing={submit}
          />

          <Button label="Sign in" onPress={submit} loading={busy} />
        </Card>

        <View style={{ gap: spacing.md, alignItems: 'center' }}>
          <Link href="/(auth)/register" asChild>
            <Txt style={{ textDecorationLine: 'underline' }}>
              New here? Create an account
            </Txt>
          </Link>
        </View>
      </Screen>
    </KeyboardAvoidingView>
  );
}
