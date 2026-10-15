import { Alert, Button, Divider, Group, Modal, Paper, Stack, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { IconCircleCheck } from '@tabler/icons-react';
import { zodResolver } from 'mantine-form-zod-resolver';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import ErrorAlert from '../../components/ErrorAlert';
import { useAccounts } from '../../hooks';
import { transferSchema } from '../../schemas';
import { api } from '../../services/api';
import { formatMoney, toMinor } from '../../utils/money';

export default function Transfer() {
  const qc = useQueryClient();
  const { data } = useAccounts();
  const account = data?.accounts?.[0];
  const [review, setReview] = useState(null); // { values, amountMinor, idempotencyKey }
  const [result, setResult] = useState(null);

  const form = useForm({
    initialValues: { toAccountNumber: '', amount: '', note: '' },
    validate: zodResolver(transferSchema),
  });

  const transfer = useMutation({
    mutationFn: api.createTransfer,
    onSuccess: (res) => {
      setResult(res);
      setReview(null);
      form.reset();
      // Balances shown are always re-fetched from the server, never calculated here.
      qc.invalidateQueries({ queryKey: ['accounts'] });
      qc.invalidateQueries({ queryKey: ['transactions'] });
    },
  });

  // New idempotency key per reviewed request. Retrying the same review reuses it,
  // so a double click or network retry can never create a second transfer.
  const openReview = (values) => {
    transfer.reset();
    setReview({ values, amountMinor: toMinor(values.amount), idempotencyKey: crypto.randomUUID() });
  };

  const confirm = () =>
    transfer.mutate({
      toAccountNumber: review.values.toAccountNumber.trim(),
      amountMinor: review.amountMinor,
      note: review.values.note.trim(),
      idempotencyKey: review.idempotencyKey,
    });

  const closeReview = () => !transfer.isPending && setReview(null);

  if (result)
    return (
      <Stack maw={520} gap="lg">
        <Alert color="green" icon={<IconCircleCheck />} title="Transfer complete">
          {formatMoney(result.transaction.amountMinor)} was sent. Reference <b>{result.transaction.reference}</b>.
        </Alert>
        <Paper withBorder p="lg" radius="lg">
          <Text size="sm" c="dimmed">New balance (confirmed by server)</Text>
          <Text fz={32} fw={800}>{formatMoney(result.balances.fromBalanceMinor)}</Text>
        </Paper>
        <Group>
          <Button onClick={() => setResult(null)}>Send another</Button>
          <Button variant="default" component={Link} to="/transactions">View transactions</Button>
        </Group>
      </Stack>
    );

  return (
    <Stack maw={520} gap="lg">
      <Title order={2}>Send money</Title>
      {account && <Text c="dimmed">Available: <b>{formatMoney(account.balanceMinor)}</b></Text>}

      <Paper withBorder radius="lg" p="lg">
        <form onSubmit={form.onSubmit(openReview)} noValidate>
          <Stack>
            <TextInput label="Recipient account number" placeholder="10 digits" inputMode="numeric" maxLength={10} {...form.getInputProps('toAccountNumber')} />
            <TextInput label="Amount (K)" placeholder="0.00" inputMode="decimal" {...form.getInputProps('amount')} />
            <TextInput label="Note (optional)" maxLength={60} {...form.getInputProps('note')} />
            <Button type="submit">Review transfer</Button>
          </Stack>
        </form>
      </Paper>

      <Modal opened={!!review} onClose={closeReview} title="Confirm transfer" centered closeOnClickOutside={!transfer.isPending}>
        {review && (
          <Stack>
            <ErrorAlert error={transfer.error} title="Transfer not completed" />
            <Group justify="space-between"><Text c="dimmed">To account</Text><Text fw={600}>{review.values.toAccountNumber}</Text></Group>
            <Group justify="space-between"><Text c="dimmed">Amount</Text><Text fw={800} fz="lg">{formatMoney(review.amountMinor)}</Text></Group>
            {review.values.note && <Group justify="space-between"><Text c="dimmed">Note</Text><Text>{review.values.note}</Text></Group>}
            <Divider />
            <Group justify="flex-end">
              <Button variant="default" onClick={closeReview} disabled={transfer.isPending}>Edit</Button>
              <Button onClick={confirm} loading={transfer.isPending}>{transfer.isError ? 'Try again' : 'Confirm and send'}</Button>
            </Group>
          </Stack>
        )}
      </Modal>
    </Stack>
  );
}
