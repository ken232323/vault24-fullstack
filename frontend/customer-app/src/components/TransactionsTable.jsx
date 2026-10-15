import { Badge, Table, Text } from '@mantine/core';
import { formatDate, formatMoney } from '../utils/money';

const statusColor = { completed: 'green', pending: 'yellow', failed: 'red' };

export default function TransactionsTable({ transactions }) {
  if (!transactions.length)
    return <Text c="dimmed" py="md">No transactions yet. Send money to see activity here.</Text>;

  return (
    <Table.ScrollContainer minWidth={560}>
      <Table verticalSpacing="sm" highlightOnHover>
        <Table.Thead>
          <Table.Tr>
            <Table.Th>Date</Table.Th>
            <Table.Th>Details</Table.Th>
            <Table.Th>Reference</Table.Th>
            <Table.Th>Status</Table.Th>
            <Table.Th ta="right">Amount</Table.Th>
          </Table.Tr>
        </Table.Thead>
        <Table.Tbody>
          {transactions.map((t) => {
            const sent = t.direction === 'sent';
            return (
              <Table.Tr key={t.id}>
                <Table.Td>{formatDate(t.createdAt)}</Table.Td>
                <Table.Td>
                  <Text size="sm" fw={600}>{sent ? 'To' : 'From'} {t.counterparty}</Text>
                  {t.note && <Text size="xs" c="dimmed">{t.note}</Text>}
                </Table.Td>
                <Table.Td><Text size="sm" ff="monospace">{t.reference}</Text></Table.Td>
                <Table.Td><Badge variant="light" color={statusColor[t.status] || 'gray'}>{t.status}</Badge></Table.Td>
                <Table.Td ta="right" fw={700} c={sent ? undefined : 'vault.7'}>
                  {sent ? '-' : '+'}{formatMoney(t.amountMinor)}
                </Table.Td>
              </Table.Tr>
            );
          })}
        </Table.Tbody>
      </Table>
    </Table.ScrollContainer>
  );
}
