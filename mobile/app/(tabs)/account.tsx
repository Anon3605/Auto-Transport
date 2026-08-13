import { View } from 'react-native';

import { Badge, Button, Card, Row, Screen, Txt } from '@/src/components/ui';
import { useSession } from '@/src/store/session';
import { useTheme } from '@/src/theme/useTheme';

export default function AccountScreen() {
  const { user, signOut } = useSession();
  const { spacing } = useTheme();

  return (
    <Screen>
      <Txt variant="display">Account</Txt>

      <Card>
        <Txt variant="heading">{user?.name ?? 'Signed in'}</Txt>
        <Txt muted>{user?.email}</Txt>
        {user?.phone ? <Txt muted>{user.phone}</Txt> : null}

        <Row style={{ flexWrap: 'wrap', marginTop: spacing.sm }}>
          {user?.email_verified ? (
            <Badge label="Email verified" tone="success" />
          ) : (
            // Load-bearing: guest quote history is only claimed after verification,
            // so an unverified account is missing something the user may expect.
            <Badge label="Email not verified" tone="warning" />
          )}
          {(user?.roles ?? []).map((role) => (
            <Badge key={role} label={role} tone="primary" />
          ))}
        </Row>
      </Card>

      <View style={{ gap: spacing.sm }}>
        <Button label="Sign out" variant="secondary" onPress={signOut} />
      </View>
    </Screen>
  );
}
