import { View } from 'react-native';

import { Badge, Button, Card, Row, Screen, Txt } from '@/src/components/ui';
import { useSession } from '@/src/store/session';
import { useTheme } from '@/src/theme/useTheme';

/**
 * Staff landing screen.
 *
 * Staff work in the web admin panel, not here — dispatch boards, moderation
 * queues and permission matrices are desktop work. Before this screen existed an
 * admin signing in got the customer shopfront, which is worse than useless: it
 * implies the app is where their work happens, and they can waste real time
 * looking for a dashboard that was never in this build.
 *
 * So this says so plainly instead of pretending.
 */
export default function StaffScreen() {
  const { user, signOut } = useSession();
  const { colors, radius, spacing } = useTheme();

  return (
    <Screen>
      <View style={{ gap: spacing.xs, paddingTop: spacing.xl }}>
        <Txt variant="display">Staff account</Txt>
        <Txt muted>Signed in as {user?.name}.</Txt>
      </View>

      <View
        style={{
          backgroundColor: colors.primarySoft,
          borderRadius: radius.md,
          padding: spacing.lg,
          gap: spacing.sm,
        }}
      >
        <Txt variant="heading" style={{ color: colors.primary }}>
          Use the web panel
        </Txt>
        <Txt style={{ color: colors.primary }}>
          Bookings, quotes, review moderation, users and settings all live in the
          admin panel in a browser. This app is the customer and driver client.
        </Txt>
      </View>

      <Card>
        <Txt variant="heading">Where to go</Txt>
        <Txt muted selectable>
          {'{your server}'}/admin
        </Txt>
        <Txt variant="caption" muted>
          Running locally that is http://127.0.0.1:8000/admin — sign in with the same
          credentials. Five failed attempts lock the account for 15 minutes.
        </Txt>
      </Card>

      <Card>
        <Txt variant="heading">Your access</Txt>
        <Row style={{ flexWrap: 'wrap', marginTop: spacing.xs }}>
          {(user?.roles ?? []).map((role) => (
            <Badge key={role} label={role} tone="primary" />
          ))}
        </Row>
        <Txt variant="caption" muted>
          What each role may open is set by the permission matrix in the panel.
        </Txt>
      </Card>

      <Button label="Sign out" variant="secondary" onPress={signOut} />
    </Screen>
  );
}
