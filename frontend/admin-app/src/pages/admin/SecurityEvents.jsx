import { Loader, Paper, SegmentedControl, Stack, Table, Text, Title } from '@mantine/core';
import { useState } from 'react';
import ErrorAlert from '../../components/ErrorAlert';
import { SeverityBadge } from '../../components/AdminBits';
import { useAdminSecurityEvents } from '../../hooks';
import { formatDate } from '../../utils/money';

export default function SecurityEvents() {
  const { data, isPending, error } = useAdminSecurityEvents();
  const [sev, setSev] = useState('all');
  const rows = (data?.events ?? []).filter((e) => sev === 'all' || e.severity === sev);

  return (
    <Stack gap="lg" maw={1000}>
      <Title order={2}>Security events</Title>
      <Text c="dimmed" size="sm">Failed logins, blocked attempts, denied access and failed transfers.</Text>
      <SegmentedControl w="fit-content" value={sev} onChange={setSev} data={[{ label: 'All', value: 'all' }, { label: 'High', value: 'high' }, { label: 'Medium', value: 'medium' }, { label: 'Low', value: 'low' }]} />
      <Paper withBorder radius="lg" p="lg">
        {isPending ? <Loader size="sm" /> : error ? <ErrorAlert error={error} title="Could not load security events" /> : rows.length === 0 ? (
          <Text c="dimmed">No events at this severity.</Text>
        ) : (
          <Table.ScrollContainer minWidth={640}>
            <Table verticalSpacing="sm" highlightOnHover>
              <Table.Thead>
                <Table.Tr><Table.Th>Severity</Table.Th><Table.Th>Event</Table.Th><Table.Th>Actor</Table.Th><Table.Th>Detail</Table.Th><Table.Th>Time</Table.Th><Table.Th>Request ID</Table.Th></Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {rows.map((e) => (
                  <Table.Tr key={e.id}>
                    <Table.Td><SeverityBadge severity={e.severity} /></Table.Td>
                    <Table.Td ff="monospace" fz="sm">{e.type}</Table.Td>
                    <Table.Td>{e.actorName}</Table.Td>
                    <Table.Td c="dimmed">{e.detail || '-'}</Table.Td>
                    <Table.Td c="dimmed" style={{ whiteSpace: 'nowrap' }}>{formatDate(e.createdAt)}</Table.Td>
                    <Table.Td ff="monospace" fz="xs">{e.requestId}</Table.Td>
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
