import { Alert, Center, Loader } from '@mantine/core';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const Spinner = () => (
  <Center h="100vh">
    <Loader />
  </Center>
);
const NoAccess = () => <Alert color="red" title="No access">You do not have permission to view this page.</Alert>;

// UX only. The backend must check the role on every admin endpoint.
export function ProtectedRoute() {
  const { user, loading } = useAuth();
  const location = useLocation();
  if (loading) return <Spinner />;
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  if (!['staff', 'admin'].includes(user.role)) return <Center h="100vh" p="md"><NoAccess /></Center>;
  return <Outlet />;
}

// Inline guard for pages only some roles may open (e.g. staff management = admin only).
export function RequireRole({ roles }) {
  const { user } = useAuth();
  return roles.includes(user.role) ? <Outlet /> : <NoAccess />;
}

export function GuestRoute() {
  const { user, loading } = useAuth();
  if (loading) return <Spinner />;
  if (user) return <Navigate to="/" replace />;
  return <Outlet />;
}
