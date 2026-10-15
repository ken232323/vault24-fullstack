import { Badge, Loader, Paper, Stack, Table, Text, TextInput, Title } from '@mantine/core';
import { IconSearch } from '@tabler/icons-react';
import { useState } from 'react';
import ErrorAlert from '../../components/ErrorAlert';
import { useAuth } from '../../context/AuthContext';
import { useAdminCustomers } from '../../hooks';
import { formatDate, formatMoney } from '../../utils/money';

export default function Customers() {
  const { user } = useAuth();
  const { data, isPending, error } = useAdminCustomers();
  const [q, setQ] = useState('');
  const rows = (data?.customers ?? []).filter((c) => `${c.name} ${c.email} ${c.accountNumber}`.toLowerCase().includes(q.toLowerCase()));

  return (
    <Stack gap="lg" maw={1000}>
      <Title order={2}>Customers</Title>
      <TextInput w={{ base: '100%', sm: 320 }} placeholder="Search name, email or account" leftSection={<IconSearch size={16} />} value={q} onChange={(e) => setQ(e.currentTarget.value)} aria-label="Search customers" />
      <Paper withBorder radius="lg" p="lg">
        {isPending ? <Loader size="sm" /> : error ? <ErrorAlert error={error} title="Could not load customers" /> : rows.length === 0 ? (
          <Text c="dimmed">No customers match your search.</Text>
        ) : (
          <Table.ScrollContainer minWidth={640}>
            <Table verticalSpacing="sm" highlightOnHover>
              <Table.Thead>
                <Table.Tr><Table.Th>Name</Table.Th><Table.Th>Email</Table.Th><Table.Th>Account</Table.Th><Table.Th>Status</Table.Th><Table.Th>Joined</Table.Th><Table.Th ta="right">Balance</Table.Th></Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {rows.map((c) => (
                  <Table.Tr key={c.id}>
                    <Table.Td fw={600}>{c.name}</Table.Td>
                    <Table.Td>{c.email}</Table.Td>
                    <Table.Td ff="monospace">{c.accountNumber}</Table.Td>
                    <Table.Td><Badge variant="light" color={c.status === 'active' ? 'green' : 'gray'}>{c.status}</Badge></Table.Td>
                    <Table.Td c="dimmed">{formatDate(c.createdAt)}</Table.Td>
                    <Table.Td ta="right" fw={700}>{c.balanceMinor == null ? <Text span c="dimmed" size="sm">Hidden</Text> : formatMoney(c.balanceMinor)}</Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
        {user.role === 'staff' && <Text size="xs" c="dimmed" mt="sm">Balances are visible to admins only.</Text>}
      </Paper>
    </Stack>
  );
}
