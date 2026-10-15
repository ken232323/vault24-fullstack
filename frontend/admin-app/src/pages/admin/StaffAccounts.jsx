import { Alert, Badge, Button, Loader, Paper, PasswordInput, Select, SimpleGrid, Stack, Table, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from 'mantine-form-zod-resolver';
import ErrorAlert from '../../components/ErrorAlert';
import { useStaffList } from '../../hooks';
import { staffSchema } from '../../schemas';
import { api } from '../../services/api';
import { formatDate } from '../../utils/money';

export default function StaffAccounts() {
  const qc = useQueryClient();
  const list = useStaffList();
  const form = useForm({ initialValues: { name: '', email: '', password: '', role: 'staff', currentPassword: '' }, validate: zodResolver(staffSchema) });

  const create = useMutation({
    mutationFn: api.createStaff,
    onSuccess: ({ user }) => {
      notifications.show({ color: 'green', title: 'Account created', message: `${user.name} can now sign in as ${user.role}.` });
      form.reset();
      qc.invalidateQueries({ queryKey: ['admin', 'staff'] });
    },
    onError: (e) => {
      if (e.code === 'EMAIL_TAKEN') form.setFieldError('email', 'This email is already in use');
      if (e.code === 'REAUTHENTICATION_FAILED') form.setFieldError('currentPassword', 'Your password is incorrect');
      if (e.code === 'WEAK_PASSWORD') form.setFieldError('password', e.message);
    },
  });

  return (
    <Stack gap="lg" maw={1000}>
      <Title order={2}>Staff accounts</Title>
      <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg">
        <Paper withBorder radius="lg" p="lg">
          <Title order={4} mb="sm">Create an account</Title>
          <form onSubmit={form.onSubmit((v) => create.mutate(v))} noValidate>
            <Stack>
              <ErrorAlert error={['EMAIL_TAKEN', 'REAUTHENTICATION_FAILED', 'WEAK_PASSWORD'].includes(create.error?.code) ? null : create.error} title="Could not create account" />
              <TextInput label="Full name" {...form.getInputProps('name')} />
              <TextInput label="Work email" type="email" {...form.getInputProps('email')} />
              <PasswordInput label="Temporary password" description="12+ characters with upper case, lower case and a number. Share it securely." autoComplete="new-password" {...form.getInputProps('password')} />
              <Select label="Role" allowDeselect={false} data={[{ value: 'staff', label: 'Staff (limited access)' }, { value: 'admin', label: 'Admin (full access)' }]} {...form.getInputProps('role')} />
              {form.values.role === 'admin' && <Alert color="yellow">Admins can see customer balances and create other staff and admin accounts.</Alert>}
              <PasswordInput label="Your password" description="Re-enter your own password to confirm this high-risk action." autoComplete="current-password" {...form.getInputProps('currentPassword')} />
              <Button type="submit" loading={create.isPending}>Create account</Button>
            </Stack>
          </form>
        </Paper>

        <Paper withBorder radius="lg" p="lg">
          <Title order={4} mb="sm">Existing accounts</Title>
          {list.isPending ? <Loader size="sm" /> : list.error ? <ErrorAlert error={list.error} title="Could not load accounts" /> : (
            <Table.ScrollContainer minWidth={360}>
              <Table verticalSpacing="xs">
                <Table.Tbody>
                  {list.data.staff.map((s) => (
                    <Table.Tr key={s.id}>
                      <Table.Td><Text fw={600} size="sm">{s.name}</Text><Text size="xs" c="dimmed">{s.email}</Text></Table.Td>
                      <Table.Td><Badge color={s.role === 'admin' ? 'yellow' : 'gray'}>{s.role}</Badge></Table.Td>
                      <Table.Td c="dimmed" fz="xs">{formatDate(s.createdAt)}</Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </Table.ScrollContainer>
          )}
        </Paper>
      </SimpleGrid>
    </Stack>
  );
}
