import { AppShell, Badge, Burger, Group, Menu, NavLink, Text, Title, UnstyledButton, Avatar } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { IconAlertTriangle, IconLayoutDashboard, IconListSearch, IconLogout, IconShieldLock, IconUserPlus, IconUsers } from '@tabler/icons-react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const links = [
  { to: '/', label: 'Overview', icon: IconLayoutDashboard },
  { to: '/customers', label: 'Customers', icon: IconUsers },
  { to: '/audit-logs', label: 'Audit logs', icon: IconListSearch },
  { to: '/security-events', label: 'Security events', icon: IconAlertTriangle },
  { to: '/staff', label: 'Staff accounts', icon: IconUserPlus, adminOnly: true },
];

export default function AdminLayout() {
  const [opened, { toggle, close }] = useDisclosure();
  const { user, logout } = useAuth();
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const handleLogout = () => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) });

  return (
    <AppShell header={{ height: 60 }} navbar={{ width: 240, breakpoint: 'sm', collapsed: { mobile: !opened } }} padding="md">
      <AppShell.Header bg="vault.9" c="white" style={{ border: 0 }}>
        <Group h="100%" px="md" justify="space-between">
          <Group gap="xs">
            <Burger opened={opened} onClick={toggle} hiddenFrom="sm" size="sm" color="white" aria-label="Toggle navigation" />
            <IconShieldLock size={22} />
            <Title order={4} c="white">Vault24 Staff</Title>
            <Badge color="yellow" variant="filled" size="sm">{user.role}</Badge>
          </Group>
          <Menu position="bottom-end" width={180}>
            <Menu.Target>
              <UnstyledButton aria-label="Account menu">
                <Group gap="xs">
                  <Avatar color="white" variant="white" radius="xl" size="sm">{user.name.charAt(0).toUpperCase()}</Avatar>
                  <Text size="sm" fw={600} c="white" visibleFrom="xs">{user.name}</Text>
                </Group>
              </UnstyledButton>
            </Menu.Target>
            <Menu.Dropdown>
              <Menu.Item color="red" leftSection={<IconLogout size={16} />} onClick={handleLogout}>Log out</Menu.Item>
            </Menu.Dropdown>
          </Menu>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="sm">
        {links.filter((l) => !l.adminOnly || user.role === 'admin').map(({ to, label, icon: Icon }) => (
          <NavLink key={to} component={Link} to={to} label={label} leftSection={<Icon size={18} />} active={pathname === to} onClick={close} />
        ))}
      </AppShell.Navbar>

      <AppShell.Main>
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
