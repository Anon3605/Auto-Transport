import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { View } from 'react-native';

import { api } from '@/src/api/client';
import { endpoints } from '@/src/api/endpoints';
import { Badge, Button, Card, Empty, ErrorNote, Loading, Row, Screen, Txt } from '@/src/components/ui';
import { useSession } from '@/src/store/session';
import { statusLabel, statusTone } from '@/src/lib/booking';
import type { Booking, BookingStatus } from '@/src/types/api';
import { useTheme } from '@/src/theme/useTheme';

/**
 * What a driver may report, and the next step from each state.
 *
 * Deliberately narrower than the full state machine — confirming and cancelling
 * stay with dispatch. The server enforces the same list; this only decides which
 * single button to show, so a driver never has to choose between statuses.
 */
const NEXT_STEP: Partial<Record<BookingStatus, { status: BookingStatus; label: string }>> = {
  assigned: { status: 'picked_up', label: 'Mark picked up' },
  picked_up: { status: 'in_transit', label: 'Start transit' },
  in_transit: { status: 'delivered', label: 'Mark delivered' },
};

export default function DriverJobsScreen() {
  const { spacing } = useTheme();
  const { user } = useSession();
  const queryClient = useQueryClient();
  const [failed, setFailed] = useState<string | null>(null);

  const jobs = useQuery({
    queryKey: ['driver-jobs'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Booking[] }>(endpoints.driver.jobs);
      return data.data;
    },
  });

  const advance = useMutation({
    mutationFn: async ({ ulid, status }: { ulid: string; status: BookingStatus }) => {
      const { data } = await api.post(endpoints.driver.status(ulid), { status });
      return data;
    },
    onSuccess: () => {
      setFailed(null);
      queryClient.invalidateQueries({ queryKey: ['driver-jobs'] });
    },
    // A 422 here means dispatch moved the job while this screen was stale.
    onError: (error) =>
      setFailed((error as { message?: string })?.message ?? 'Could not update that job.'),
  });

  /*
   * An unapproved driver must not be shown an empty job list. "No jobs assigned"
   * and "your account has not been approved" look identical otherwise, and the
   * first reading is that the app is broken. The server tells us which it is via
   * user.is_active.
   */
  if (user !== null && !user.is_active) {
    return (
      <Screen>
        <View style={{ gap: spacing.xs }}>
          <Txt variant="display">Awaiting approval</Txt>
          <Txt muted>Your driver account is not active yet.</Txt>
        </View>

        <Card style={{ gap: spacing.sm }}>
          <Txt variant="heading">What happens next</Txt>
          <Txt muted>
            We verify your licence details and link you to a carrier. Until that is
            done, no work can be assigned to you — this screen will show your jobs
            as soon as it is.
          </Txt>
          <Txt variant="caption" muted>
            Account status: {user.status}
          </Txt>
        </Card>

        <Button label="Check again" variant="secondary" onPress={() => jobs.refetch()} />
      </Screen>
    );
  }

  if (jobs.isLoading) return <Screen><Loading label="Loading your jobs" /></Screen>;

  if (jobs.isError) {
    return (
      <Screen>
        <ErrorNote message={(jobs.error as { message?: string })?.message ?? 'Could not load your jobs.'} />
        <Button label="Try again" variant="secondary" onPress={() => jobs.refetch()} />
      </Screen>
    );
  }

  const list = jobs.data ?? [];

  return (
    <Screen>
      <View style={{ gap: spacing.xs }}>
        <Txt variant="display">My jobs</Txt>
        <Txt muted>Loads assigned to you, earliest collection first.</Txt>
      </View>

      {failed ? <ErrorNote message={failed} /> : null}

      {list.length === 0 ? (
        <Empty
          title="No jobs assigned"
          text="When dispatch assigns you a load it appears here. Completed jobs are hidden."
        />
      ) : (
        list.map((job) => {
          const next = NEXT_STEP[job.status];

          return (
            <Card key={job.ulid}>
              <Row style={{ justifyContent: 'space-between' }}>
                <Txt variant="label" muted>{job.booking_number}</Txt>
                <Badge label={statusLabel(job.status)} tone={statusTone(job.status)} />
              </Row>

              <Txt variant="heading">
                {job.pickup_city} → {job.dropoff_city}
              </Txt>

              <Txt muted>
                {job.scheduled_pickup_date
                  ? `Collect ${job.scheduled_pickup_date}`
                  : 'Collection date to be confirmed'}
              </Txt>

              {job.service ? <Txt variant="caption" muted>{job.service.name}</Txt> : null}

              {next ? (
                <View style={{ marginTop: spacing.sm }}>
                  <Button
                    label={next.label}
                    loading={advance.isPending}
                    onPress={() => advance.mutate({ ulid: job.ulid, status: next.status })}
                  />
                </View>
              ) : (
                <Txt variant="caption" muted>
                  Waiting on dispatch — nothing for you to do yet.
                </Txt>
              )}
            </Card>
          );
        })
      )}
    </Screen>
  );
}
