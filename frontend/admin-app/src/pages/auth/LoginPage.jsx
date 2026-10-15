import { Button, PasswordInput, Stack, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { zodResolver } from 'mantine-form-zod-resolver';
import { useLocation, useNavigate } from 'react-router-dom';
import ErrorAlert from '../../components/ErrorAlert';
import { useAuth } from '../../context/AuthContext';
import { USE_MOCK } from '../../services/api';
import { loginSchema } from '../../schemas';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const form = useForm({ initialValues: { email: '', password: '' }, validate: zodResolver(loginSchema) });

  const onSubmit = (values) => login.mutate(values, { onSuccess: () => navigate(location.state?.from || '/', { replace: true }) });

  return (
    <form onSubmit={form.onSubmit(onSubmit)} noValidate>
      <Stack>
        <div>
          <Title order={2}>Staff sign in</Title>
          <Text c="dimmed" size="sm">Accounts are created by an administrator.</Text>
        </div>
        <ErrorAlert error={login.error} title="Could not sign in" />
        <TextInput label="Work email" type="email" autoComplete="email" data-autofocus {...form.getInputProps('email')} />
        <PasswordInput label="Password" autoComplete="current-password" {...form.getInputProps('password')} />
        <Button type="submit" loading={login.isPending}>Sign in</Button>
        {USE_MOCK && <Text size="xs" c="dimmed" ta="center">Demo (Password123!): admin@vault24.test or staff@vault24.test</Text>}
      </Stack>
    </form>
  );
}
