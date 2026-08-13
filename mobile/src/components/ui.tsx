import { forwardRef } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type PressableProps,
  type TextInputProps,
  type TextProps,
  type ViewProps,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { HIT_TARGET } from '@/src/theme/tokens';
import { useTheme } from '@/src/theme/useTheme';

/**
 * The shared kit. Everything a screen renders comes from here so colour and
 * spacing stay in the token file -- a screen that reaches for a raw hex is the
 * thing that breaks dark mode later.
 */

// --- layout ----------------------------------------------------------------

export function Screen({
  children,
  scroll = true,
  ...rest
}: ViewProps & { scroll?: boolean }) {
  const { colors, spacing } = useTheme();

  const body = (
    <View style={{ padding: spacing.lg, gap: spacing.lg, flexGrow: 1 }} {...rest}>
      {children}
    </View>
  );

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
      {scroll ? (
        <ScrollView
          contentContainerStyle={{ flexGrow: 1 }}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
        >
          {body}
        </ScrollView>
      ) : (
        body
      )}
    </SafeAreaView>
  );
}

export function Card({ children, style, ...rest }: ViewProps) {
  const { colors, radius, spacing } = useTheme();

  return (
    <View
      style={[
        {
          backgroundColor: colors.surface,
          borderColor: colors.border,
          borderWidth: StyleSheet.hairlineWidth,
          borderRadius: radius.lg,
          padding: spacing.lg,
          gap: spacing.sm,
        },
        style,
      ]}
      {...rest}
    >
      {children}
    </View>
  );
}

export function Row({ children, style, ...rest }: ViewProps) {
  const { spacing } = useTheme();

  return (
    <View
      style={[{ flexDirection: 'row', alignItems: 'center', gap: spacing.sm }, style]}
      {...rest}
    >
      {children}
    </View>
  );
}

// --- type ------------------------------------------------------------------

type Variant = 'display' | 'title' | 'heading' | 'body' | 'label' | 'caption';

export function Txt({
  variant = 'body',
  muted = false,
  style,
  ...rest
}: TextProps & { variant?: Variant; muted?: boolean }) {
  const { colors, typography } = useTheme();

  return (
    <Text
      style={[typography[variant], { color: muted ? colors.textMuted : colors.text }, style]}
      {...rest}
    />
  );
}

// --- controls --------------------------------------------------------------

export function Button({
  label,
  onPress,
  variant = 'primary',
  loading = false,
  disabled = false,
  ...rest
}: PressableProps & {
  label: string;
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  loading?: boolean;
}) {
  const { colors, radius, spacing, typography } = useTheme();
  const isDisabled = disabled || loading;

  const background = {
    primary: colors.primary,
    secondary: colors.surfaceAlt,
    ghost: 'transparent',
    danger: colors.danger,
  }[variant];

  const foreground = {
    primary: colors.primaryText,
    secondary: colors.text,
    ghost: colors.primary,
    danger: '#ffffff',
  }[variant];

  return (
    <Pressable
      onPress={onPress}
      disabled={isDisabled}
      // Announces the busy/disabled state instead of leaving a screen reader to
      // infer it from a spinner it cannot see.
      accessibilityRole="button"
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      accessibilityLabel={label}
      style={({ pressed }) => ({
        minHeight: HIT_TARGET,
        paddingHorizontal: spacing.lg,
        paddingVertical: spacing.md,
        borderRadius: radius.md,
        backgroundColor: background,
        borderWidth: variant === 'ghost' ? StyleSheet.hairlineWidth : 0,
        borderColor: colors.border,
        alignItems: 'center',
        justifyContent: 'center',
        flexDirection: 'row',
        gap: spacing.sm,
        opacity: isDisabled ? 0.55 : pressed ? 0.85 : 1,
      })}
      {...rest}
    >
      {loading && <ActivityIndicator size="small" color={foreground} />}
      <Text style={[typography.heading, { color: foreground }]}>{label}</Text>
    </Pressable>
  );
}

export const Field = forwardRef<TextInput, TextInputProps & { label: string; error?: string; hint?: string }>(
  function Field({ label, error, hint, style, ...rest }, ref) {
    const { colors, radius, spacing, typography } = useTheme();

    return (
      <View style={{ gap: spacing.xs }}>
        <Text style={[typography.label, { color: colors.text }]}>{label}</Text>

        <TextInput
          ref={ref}
          placeholderTextColor={colors.textMuted}
          accessibilityLabel={label}
          // The message is bound to the field rather than only rendered under
          // it, so it is announced with the input instead of being stranded as
          // loose text a screen reader reaches separately.
          accessibilityHint={error ?? hint}
          style={[
            typography.body,
            {
              minHeight: HIT_TARGET,
              paddingHorizontal: spacing.md,
              paddingVertical: spacing.md,
              borderRadius: radius.md,
              borderWidth: StyleSheet.hairlineWidth,
              borderColor: error ? colors.danger : colors.border,
              backgroundColor: colors.surface,
              color: colors.text,
            },
            style,
          ]}
          {...rest}
        />

        {error ? (
          <Text style={[typography.caption, { color: colors.danger }]}>{error}</Text>
        ) : hint ? (
          <Text style={[typography.caption, { color: colors.textMuted }]}>{hint}</Text>
        ) : null}
      </View>
    );
  },
);

// --- display ---------------------------------------------------------------

export function Badge({ label, tone = 'neutral' }: { label: string; tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'primary' }) {
  const { colors, radius, spacing, typography } = useTheme();

  const [bg, fg] = {
    neutral: [colors.surfaceAlt, colors.textMuted],
    success: [colors.successSoft, colors.success],
    warning: [colors.warningSoft, colors.warning],
    danger: [colors.dangerSoft, colors.danger],
    primary: [colors.primarySoft, colors.primary],
  }[tone];

  return (
    <View
      style={{
        backgroundColor: bg,
        borderRadius: radius.pill,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.xs,
        alignSelf: 'flex-start',
      }}
    >
      <Text style={[typography.caption, { color: fg, fontWeight: '600' }]}>{label}</Text>
    </View>
  );
}

/**
 * Read-only star display. The glyph row is hidden from assistive tech and the
 * real number is exposed once on the wrapper -- otherwise a screen reader
 * announces "star star star star star", which conveys nothing.
 */
export function Stars({ value, size = 16 }: { value: number; size?: number }) {
  const { colors } = useTheme();
  const rounded = Math.round(value);

  return (
    <View
      style={{ flexDirection: 'row' }}
      accessibilityRole="image"
      accessibilityLabel={`${value.toFixed(1)} out of 5 stars`}
    >
      {[1, 2, 3, 4, 5].map((n) => (
        <Text
          key={n}
          aria-hidden
          importantForAccessibility="no"
          style={{ fontSize: size, color: n <= rounded ? colors.star : colors.border }}
        >
          ★
        </Text>
      ))}
    </View>
  );
}

export function Empty({ title, text }: { title: string; text?: string }) {
  const { spacing } = useTheme();

  return (
    <View style={{ alignItems: 'center', paddingVertical: spacing.xxxl, gap: spacing.sm }}>
      <Txt variant="heading">{title}</Txt>
      {text ? (
        <Txt muted style={{ textAlign: 'center' }}>
          {text}
        </Txt>
      ) : null}
    </View>
  );
}

export function Loading({ label = 'Loading' }: { label?: string }) {
  const { colors, spacing } = useTheme();

  return (
    <View style={{ padding: spacing.xxxl, alignItems: 'center', gap: spacing.md }}>
      <ActivityIndicator color={colors.primary} />
      <Txt muted>{label}</Txt>
    </View>
  );
}

export function ErrorNote({ message }: { message: string }) {
  const { colors, radius, spacing } = useTheme();

  return (
    <View
      // Announced when it appears, so a failed submit is not silent for anyone
      // who cannot see the red box.
      accessibilityLiveRegion="polite"
      role="alert"
      style={{
        backgroundColor: colors.dangerSoft,
        borderRadius: radius.md,
        padding: spacing.md,
      }}
    >
      <Txt style={{ color: colors.danger }}>{message}</Txt>
    </View>
  );
}
