import { Box, Group, Paper, Stack, Text, Title } from '@mantine/core';
import { IconLock } from '@tabler/icons-react';
import { Outlet } from 'react-router-dom';

export default function AuthLayout() {
  return (
    <Box style={{ minHeight: '100vh', display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))' }}>
      <Stack justify="space-between" p="xl" bg="vault.9" c="white" visibleFrom="sm" style={{ minHeight: 280 }}>
        <Group gap="xs">
          <IconLock size={26} />
          <Title order={3} c="white">Vault24</Title>
        </Group>
        <Stack gap="xs" maw={420}>
          <Title order={1} c="white" fz={40} lh={1.1}>Your money, checked twice by the server.</Title>
          <Text c="vault.1">Every balance you see is confirmed by Vault24, never guessed by your browser.</Text>
        </Stack>
        <Text size="xs" c="vault.3">Fictional prototype. Simulated money only.</Text>
      </Stack>
      <Stack justify="center" align="center" p="md">
        <Paper w="100%" maw={420} p="xl" withBorder radius="lg">
          <Outlet />
        </Paper>
      </Stack>
    </Box>
  );
}
