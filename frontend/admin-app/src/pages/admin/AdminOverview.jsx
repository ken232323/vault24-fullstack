import { Alert, Paper, SimpleGrid, Skeleton, Stack, Table, Text, Title } from '@mantine/core';
import ErrorAlert from '../../components/ErrorAlert';
import { SeverityBadge } from '../../components/AdminBits';
import { useAdminAuditLogs, useAdminCustomers, useAdminSecurityEvents } from '../../hooks';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../../utils/money';

const Stat = ({ label, value, loading }) => (
  <Paper withBorder radius="lg" p="lg">
    <Text size="sm" c="dimmed">{label}</Text>
    {loading ? <Skeleton h={36} mt={4} /> : <Text fz={34} fw={800}>{value}</Text>}
  </Paper>
);

export default function AdminOverview() {
  const { user } = useAuth();
  const customers = useAdminCustomers();
  const audit = useAdminAuditLogs();
  const events = useAdminSecurityEvents();
  const evs = events.data?.events ?? [];

  return (
    <Stack gap="lg" maw={1000}>
      <Title order={2}>Overview</Title>
      {user.role === 'staff' && <Alert color="blue">You have limited staff access. Balances and audit metadata are hidden.</Alert>}
      <ErrorAlert error={customers.error || audit.error || events.error} title="Could not load some admin data" />
      <SimpleGrid cols={{ base: 1, xs: 2, md: 4 }}>
        <Stat label="Customers" value={customers.data?.customers.length} loading={customers.isPending} />
        <Stat label="Audit events" value={audit.data?.logs.length} loading={audit.isPending} />
        <Stat label="Security events" value={evs.length} loading={events.isPending} />
        <Stat label="High severity" value={evs.filter((e) => e.severity === 'high').length} loading={events.isPending} />
      </SimpleGrid>
      <Paper withBorder radius="lg" p="lg">
        <Title order={4} mb="sm">Latest security events</Title>
        {evs.length === 0 ? (
          <Text c="dimmed">No security events recorded.</Text>
        ) : (
          <Table.ScrollContainer minWidth={500}>
            <Table verticalSpacing="xs">
              <Table.Tbody>
                {evs.slice(0, 5).map((e) => (
                  <Table.Tr key={e.id}>
                    <Table.Td><SeverityBadge severity={e.severity} /></Table.Td>
                    <Table.Td>{e.type}</Table.Td>
                    <Table.Td>{e.actorName}</Table.Td>
                    <Table.Td c="dimmed">{formatDate(e.createdAt)}</Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
      </Paper>
    </Stack>
  );
}
