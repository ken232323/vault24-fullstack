import { Anchor, Button, PasswordInput, Stack, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { zodResolver } from 'mantine-form-zod-resolver';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import ErrorAlert from '../../components/ErrorAlert';
import { useAuth } from '../../context/AuthContext';
import { USE_MOCK } from '../../services/api';
import { loginSchema } from '../../schemas';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const form = useForm({ initialValues: { email: '', password: '' }, validate: zodResolver(loginSchema) });

  const onSubmit = (values) =>
    login.mutate(values, { onSuccess: () => navigate(location.state?.from || '/dashboard', { replace: true }) });

  return (
    <form onSubmit={form.onSubmit(onSubmit)} noValidate>
      <Stack>
        <div>
          <Title order={2}>Log in</Title>
          <Text c="dimmed" size="sm">Welcome back to Vault24.</Text>
        </div>
        <ErrorAlert error={login.error} title="Could not log in" />
        <TextInput label="Email" type="email" autoComplete="email" data-autofocus {...form.getInputProps('email')} />
        <PasswordInput label="Password" autoComplete="current-password" {...form.getInputProps('password')} />
        <Button type="submit" loading={login.isPending}>Log in</Button>
        <Text size="sm" ta="center">
          New to Vault24? <Anchor component={Link} to="/register">Create an account</Anchor>
        </Text>
        {USE_MOCK && <Text size="xs" c="dimmed" ta="center">Demo: demo@vault24.test / Password123!</Text>}
      </Stack>
    </form>
  );
}
