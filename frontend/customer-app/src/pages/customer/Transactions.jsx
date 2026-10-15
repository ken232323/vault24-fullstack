import { Loader, Paper, SegmentedControl, Stack, Title } from '@mantine/core';
import { useState } from 'react';
import ErrorAlert from '../../components/ErrorAlert';
import TransactionsTable from '../../components/TransactionsTable';
import { useTransactions } from '../../hooks';

export default function Transactions() {
  const { data, isPending, error } = useTransactions();
  const [filter, setFilter] = useState('all');
  const rows = (data?.transactions ?? []).filter((t) => filter === 'all' || t.direction === filter);

  return (
    <Stack gap="lg" maw={900}>
      <Title order={2}>Transactions</Title>
      <SegmentedControl
        w="fit-content"
        value={filter}
        onChange={setFilter}
        data={[{ label: 'All', value: 'all' }, { label: 'Sent', value: 'sent' }, { label: 'Received', value: 'received' }]}
      />
      <Paper withBorder radius="lg" p="lg">
        {isPending ? <Loader size="sm" /> : error ? <ErrorAlert error={error} title="Could not load transactions" /> : <TransactionsTable transactions={rows} />}
      </Paper>
    </Stack>
  );
}
