import { Alert, Anchor, Button, CopyButton, Group, Loader, Paper, Skeleton, Stack, Text, Title, ActionIcon, Tooltip } from '@mantine/core';
import { IconArrowsExchange, IconCheck, IconCopy } from '@tabler/icons-react';
import { Link } from 'react-router-dom';
import ErrorAlert from '../../components/ErrorAlert';
import TransactionsTable from '../../components/TransactionsTable';
import { useAuth } from '../../context/AuthContext';
import { useAccounts, useTransactions } from '../../hooks';
import { formatMoney } from '../../utils/money';

export default function Dashboard() {
  const { user } = useAuth();
  const accounts = useAccounts();
  const txns = useTransactions();
  const account = accounts.data?.accounts?.[0];

  return (
    <Stack gap="lg" maw={900}>
      <Title order={2}>Hello, {user.name.split(' ')[0]}</Title>

      <ErrorAlert error={accounts.error} title="Could not load your account" />

      <Paper withBorder radius="lg" p="xl" bg="vault.9" c="white">
        {accounts.isPending ? (
          <Skeleton h={80} />
        ) : account ? (
          <Group justify="space-between" align="flex-end">
            <div>
              <Text c="vault.2" size="sm">Available balance</Text>
              <Text fz={44} fw={800} lh={1.1}>{formatMoney(account.balanceMinor)}</Text>
              <Group gap={6} mt="xs">
                <Text c="vault.2" size="sm">Account {account.accountNumber}</Text>
                <CopyButton value={account.accountNumber}>
                  {({ copied, copy }) => (
                    <Tooltip label={copied ? 'Copied' : 'Copy account number'}>
                      <ActionIcon variant="subtle" color="white" size="sm" onClick={copy} aria-label="Copy account number">
                        {copied ? <IconCheck size={14} /> : <IconCopy size={14} />}
                      </ActionIcon>
                    </Tooltip>
                  )}
                </CopyButton>
              </Group>
            </div>
            <Button component={Link} to="/transfers/new" color="white" variant="white" c="vault.9" leftSection={<IconArrowsExchange size={18} />}>
              Send money
            </Button>
          </Group>
        ) : (
          !accounts.error && <Alert color="yellow">No account found for your profile.</Alert>
        )}
      </Paper>

      <Paper withBorder radius="lg" p="lg">
        <Group justify="space-between" mb="sm">
          <Title order={4}>Recent transactions</Title>
          <Anchor component={Link} to="/transactions" size="sm">View all</Anchor>
        </Group>
        {txns.isPending ? <Loader size="sm" /> : txns.error ? <ErrorAlert error={txns.error} /> : <TransactionsTable transactions={txns.data.transactions.slice(0, 5)} />}
      </Paper>
    </Stack>
  );
}
