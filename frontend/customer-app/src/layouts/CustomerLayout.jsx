import { AppShell, Avatar, Burger, Group, Menu, NavLink, Text, Title, UnstyledButton } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { IconArrowsExchange, IconHome2, IconListDetails, IconLock, IconLogout, IconUser } from '@tabler/icons-react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const links = [
  { to: '/dashboard', label: 'Dashboard', icon: IconHome2 },
  { to: '/transfers/new', label: 'Send money', icon: IconArrowsExchange },
  { to: '/transactions', label: 'Transactions', icon: IconListDetails },
  { to: '/profile', label: 'Profile', icon: IconUser },
];

export default function CustomerLayout() {
  const [opened, { toggle, close }] = useDisclosure();
  const { user, logout } = useAuth();
  const { pathname } = useLocation();
  const navigate = useNavigate();

  const handleLogout = () => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) });

  return (
    <AppShell header={{ height: 60 }} navbar={{ width: 240, breakpoint: 'sm', collapsed: { mobile: !opened } }} padding="md">
      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between">
          <Group gap="xs">
            <Burger opened={opened} onClick={toggle} hiddenFrom="sm" size="sm" aria-label="Toggle navigation" />
            <IconLock size={22} />
            <Title order={4}>Vault24</Title>
          </Group>
          <Menu position="bottom-end" width={200}>
            <Menu.Target>
              <UnstyledButton aria-label="Account menu">
                <Group gap="xs">
                  <Avatar color="vault" radius="xl" size="sm">{user.name.charAt(0).toUpperCase()}</Avatar>
                  <Text size="sm" fw={600} visibleFrom="xs">{user.name}</Text>
                </Group>
              </UnstyledButton>
            </Menu.Target>
            <Menu.Dropdown>
              <Menu.Item component={Link} to="/profile" leftSection={<IconUser size={16} />}>Profile</Menu.Item>
              <Menu.Item color="red" leftSection={<IconLogout size={16} />} onClick={handleLogout}>Log out</Menu.Item>
            </Menu.Dropdown>
          </Menu>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="sm">
        {links.map(({ to, label, icon: Icon }) => (
          <NavLink key={to} component={Link} to={to} label={label} leftSection={<Icon size={18} />} active={pathname === to} onClick={close} />
        ))}
      </AppShell.Navbar>

      <AppShell.Main>
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
