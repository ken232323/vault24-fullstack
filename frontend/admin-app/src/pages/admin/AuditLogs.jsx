import { Group, Loader, Paper, Select, Stack, Table, Text, TextInput, Title } from '@mantine/core';
import { IconSearch } from '@tabler/icons-react';
import { useState } from 'react';
import ErrorAlert from '../../components/ErrorAlert';
import { OutcomeBadge } from '../../components/AdminBits';
import { useAdminAuditLogs } from '../../hooks';
import { formatDate } from '../../utils/money';

export default function AuditLogs() {
  const { data, isPending, error } = useAdminAuditLogs();
  const [q, setQ] = useState('');
  const [outcome, setOutcome] = useState('all');
  const rows = (data?.logs ?? []).filter(
    (l) => (outcome === 'all' || l.outcome === outcome) && `${l.actorName} ${l.action} ${l.resourceType}`.toLowerCase().includes(q.toLowerCase())
  );

  return (
    <Stack gap="lg" maw={1100}>
      <Title order={2}>Audit logs</Title>
      <Group>
        <TextInput w={{ base: '100%', sm: 300 }} placeholder="Search actor or action" leftSection={<IconSearch size={16} />} value={q} onChange={(e) => setQ(e.currentTarget.value)} aria-label="Search audit logs" />
        <Select w={160} value={outcome} onChange={(v) => setOutcome(v || 'all')} allowDeselect={false} aria-label="Filter by outcome"
          data={[{ value: 'all', label: 'All outcomes' }, { value: 'success', label: 'Success' }, { value: 'failure', label: 'Failure' }, { value: 'denied', label: 'Denied' }, { value: 'blocked', label: 'Blocked' }]} />
      </Group>
      <Paper withBorder radius="lg" p="lg">
        {isPending ? <Loader size="sm" /> : error ? <ErrorAlert error={error} title="Could not load audit logs" /> : rows.length === 0 ? (
          <Text c="dimmed">No events match these filters.</Text>
        ) : (
          <Table.ScrollContainer minWidth={760}>
            <Table verticalSpacing="xs" highlightOnHover>
              <Table.Thead>
                <Table.Tr><Table.Th>Time</Table.Th><Table.Th>Actor</Table.Th><Table.Th>Action</Table.Th><Table.Th>Resource</Table.Th><Table.Th>Outcome</Table.Th><Table.Th>Request ID</Table.Th><Table.Th>Details</Table.Th></Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {rows.map((l) => (
                  <Table.Tr key={l.id}>
                    <Table.Td c="dimmed" style={{ whiteSpace: 'nowrap' }}>{formatDate(l.createdAt)}</Table.Td>
                    <Table.Td>{l.actorName}</Table.Td>
                    <Table.Td ff="monospace" fz="sm">{l.action}</Table.Td>
                    <Table.Td>{l.resourceType}</Table.Td>
                    <Table.Td><OutcomeBadge outcome={l.outcome} /></Table.Td>
                    <Table.Td ff="monospace" fz="xs">{l.requestId}</Table.Td>
                    <Table.Td fz="xs" c="dimmed">{l.metadata && Object.keys(l.metadata).length ? JSON.stringify(l.metadata) : '-'}</Table.Td>
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
