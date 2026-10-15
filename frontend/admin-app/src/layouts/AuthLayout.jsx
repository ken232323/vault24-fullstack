import { Box, Group, Paper, Stack, Text, Title } from '@mantine/core';
import { IconShieldLock } from '@tabler/icons-react';
import { Outlet } from 'react-router-dom';

export default function AuthLayout() {
  return (
    <Box bg="vault.9" style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', padding: 16 }}>
      <Stack w="100%" maw={420} gap="md">
        <Group gap="xs" c="white" justify="center">
          <IconShieldLock size={28} />
          <Title order={3} c="white">Vault24 Staff</Title>
        </Group>
        <Paper p="xl" radius="lg">
          <Outlet />
        </Paper>
        <Text size="xs" c="vault.3" ta="center">Authorised staff only. Fictional prototype.</Text>
      </Stack>
    </Box>
  );
}
