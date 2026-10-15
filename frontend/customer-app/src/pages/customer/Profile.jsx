import { Button, Paper, Stack, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from 'mantine-form-zod-resolver';
import ErrorAlert from '../../components/ErrorAlert';
import { useAuth } from '../../context/AuthContext';
import { profileSchema } from '../../schemas';
import { api } from '../../services/api';

export default function Profile() {
  const { user } = useAuth();
  const qc = useQueryClient();
  const form = useForm({ initialValues: { name: user.name }, validate: zodResolver(profileSchema) });

  // Only permitted fields are sent. No user ID: the server uses the session identity.
  const update = useMutation({
    mutationFn: api.updateProfile,
    onSuccess: ({ user: u }) => {
      qc.setQueryData(['me'], u);
      form.resetDirty({ name: u.name });
      notifications.show({ color: 'green', title: 'Profile saved', message: 'Your name has been updated.' });
    },
  });

  return (
    <Stack gap="lg" maw={520}>
      <Title order={2}>Profile</Title>
      <Paper withBorder radius="lg" p="lg">
        <form onSubmit={form.onSubmit((v) => update.mutate(v))} noValidate>
          <Stack>
            <ErrorAlert error={update.error} title="Could not save profile" />
            <TextInput label="Full name" {...form.getInputProps('name')} />
            <TextInput label="Email" value={user.email} disabled description="Contact support to change your email." />
            <Text size="xs" c="dimmed">Passwords and balances cannot be changed here.</Text>
            <Button type="submit" loading={update.isPending} disabled={!form.isDirty()}>Save changes</Button>
          </Stack>
        </form>
      </Paper>
    </Stack>
  );
}
