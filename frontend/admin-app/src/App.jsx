import { Navigate, Route, Routes } from 'react-router-dom';
import { GuestRoute, ProtectedRoute, RequireRole } from './components/RouteGuards';
import AuthLayout from './layouts/AuthLayout';
import AdminLayout from './layouts/AdminLayout';
import LoginPage from './pages/auth/LoginPage';
import AdminOverview from './pages/admin/AdminOverview';
import Customers from './pages/admin/Customers';
import AuditLogs from './pages/admin/AuditLogs';
import SecurityEvents from './pages/admin/SecurityEvents';
import StaffAccounts from './pages/admin/StaffAccounts';

// No sign-up route: staff and admin accounts are created by an admin inside the app.
export default function App() {
  return (
    <Routes>
      <Route element={<GuestRoute />}>
        <Route element={<AuthLayout />}>
          <Route path="/login" element={<LoginPage />} />
        </Route>
      </Route>
      <Route element={<ProtectedRoute />}>
        <Route element={<AdminLayout />}>
          <Route path="/" element={<AdminOverview />} />
          <Route path="/customers" element={<Customers />} />
          <Route path="/audit-logs" element={<AuditLogs />} />
          <Route path="/security-events" element={<SecurityEvents />} />
          <Route element={<RequireRole roles={['admin']} />}>
            <Route path="/staff" element={<StaffAccounts />} />
          </Route>
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
