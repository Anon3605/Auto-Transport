import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, View } from 'react-native';

import { api } from '@/src/api/client';
import { endpoints } from '@/src/api/endpoints';
import { Button, Card, ErrorNote, Field, Screen, Txt } from '@/src/components/ui';
import { fieldErrors } from '@/src/store/session';
import { contactSchema } from '@/src/types/schemas';
import { useTheme } from '@/src/theme/useTheme';

export default function ContactScreen() {
  const { colors, radius, spacing } = useTheme();

  const [form, setForm] = useState({ name: '', email: '', phone: '', subject: '', message: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [sent, setSent] = useState(false);

  const set = (key: keyof typeof form) => (value: string) =>
    setForm((previous) => ({ ...previous, [key]: value }));

  const mutation = useMutation({
    mutationFn: async () => {
      const { data } = await api.post(endpoints.contact, {
        ...form,
        phone: form.phone || undefined,
        subject: form.subject || undefined,
      });
      return data;
    },
    onSuccess: () => {
      setSent(true);
      setForm({ name: '', email: '', phone: '', subject: '', message: '' });
      setErrors({});
    },
    onError: (error) => setErrors(fieldErrors(error)),
  });

  function submit() {
    /*
     * Validate against the same zod schema the rest of the app uses so the
     * messages match. The server re-validates regardless -- this is for instant
     * feedback, not safety.
     */
    const parsed = contactSchema.safeParse({
      ...form,
      phone: form.phone || undefined,
      subject: form.subject || undefined,
    });

    if (!parsed.success) {
      const flat: Record<string, string> = {};
      for (const issue of parsed.error.issues) {
        const key = String(issue.path[0] ?? '');
        if (key && !flat[key]) flat[key] = issue.message;
      }
      setErrors(flat);
      return;
    }

    setErrors({});
    mutation.mutate();
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen>
        <View style={{ gap: spacing.xs }}>
          <Txt variant="display">Get in touch</Txt>
          <Txt muted>Questions about a quote, a shipment or anything else.</Txt>
        </View>

        {sent ? (
          <View
            accessibilityLiveRegion="polite"
            role="alert"
            style={{ backgroundColor: colors.successSoft, borderRadius: radius.md, padding: spacing.lg, gap: spacing.xs }}
          >
            <Txt variant="heading" style={{ color: colors.success }}>Message sent</Txt>
            <Txt style={{ color: colors.success }}>
              We have it on record and will reply by email.
            </Txt>
            <Button label="Send another" variant="ghost" onPress={() => setSent(false)} />
          </View>
        ) : (
          <Card style={{ gap: spacing.lg }}>
            {mutation.isError && Object.keys(errors).length === 0 ? (
              <ErrorNote
                message={(mutation.error as { message?: string })?.message ?? 'Could not send your message.'}
              />
            ) : null}

            <Field
              label="Your name"
              value={form.name}
              onChangeText={set('name')}
              error={errors.name}
              autoCapitalize="words"
              autoComplete="name"
            />

            <Field
              label="Email"
              value={form.email}
              onChangeText={set('email')}
              error={errors.email}
              keyboardType="email-address"
              autoCapitalize="none"
              autoComplete="email"
              inputMode="email"
            />

            <Field
              label="Phone"
              value={form.phone}
              onChangeText={set('phone')}
              error={errors.phone}
              placeholder="Optional"
              keyboardType="phone-pad"
              autoComplete="tel"
            />

            <Field
              label="Subject"
              value={form.subject}
              onChangeText={set('subject')}
              error={errors.subject}
              placeholder="Optional"
            />

            <Field
              label="Message"
              value={form.message}
              onChangeText={set('message')}
              error={errors.message}
              placeholder="Tell us what you need"
              multiline
              numberOfLines={5}
              style={{ minHeight: 120, textAlignVertical: 'top' }}
            />

            <Button label="Send message" onPress={submit} loading={mutation.isPending} />
          </Card>
        )}
      </Screen>
    </KeyboardAvoidingView>
  );
}
