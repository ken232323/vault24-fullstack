import { Anchor, Button, PasswordInput, Stack, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { zodResolver } from 'mantine-form-zod-resolver';
import { Link, useNavigate } from 'react-router-dom';
import ErrorAlert from '../../components/ErrorAlert';
import { useAuth } from '../../context/AuthContext';
import { registerSchema } from '../../schemas';

const FIELD_ERRORS = { EMAIL_TAKEN: ['email', 'This email is already registered'] };

export default function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();
  const form = useForm({
    initialValues: { name: '', email: '', password: '', confirmPassword: '' },
    validate: zodResolver(registerSchema),
  });

  const onSubmit = ({ name, email, password }) =>
    register.mutate(
      { name, email, password }, // confirmPassword never leaves the browser; the server assigns the account number
      {
        onSuccess: (res) => {
          notifications.show({ color: 'green', title: 'Account created', message: `Your account number is ${res.accountNumber ?? 'shown on your dashboard'}. Log in to open your dashboard.`, autoClose: 15000 });
          navigate('/login', { replace: true });
        },
        onError: (e) => {
          if (FIELD_ERRORS[e.code]) form.setFieldError(...FIELD_ERRORS[e.code]);
          if (e.code === 'WEAK_PASSWORD') form.setFieldError('password', e.message);
        },
      }
    );

  return (
    <form onSubmit={form.onSubmit(onSubmit)} noValidate>
      <Stack>
        <div>
          <Title order={2}>Create your account</Title>
          <Text c="dimmed" size="sm">Fictional prototype. No real money is involved.</Text>
        </div>
        <ErrorAlert error={FIELD_ERRORS[register.error?.code] || register.error?.code === 'WEAK_PASSWORD' ? null : register.error} title="Could not create account" />
        <TextInput label="Full name" autoComplete="name" data-autofocus {...form.getInputProps('name')} />
        <TextInput label="Email" type="email" autoComplete="email" {...form.getInputProps('email')} />
        <PasswordInput label="Password" description="12+ characters with upper case, lower case and a number" autoComplete="new-password" {...form.getInputProps('password')} />
        <PasswordInput label="Confirm password" autoComplete="new-password" {...form.getInputProps('confirmPassword')} />
        <Button type="submit" loading={register.isPending}>Create account</Button>
        <Text size="sm" ta="center">
          Already registered? <Anchor component={Link} to="/login">Log in</Anchor>
        </Text>
      </Stack>
    </form>
  );
}
