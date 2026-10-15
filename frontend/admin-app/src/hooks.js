import { useQuery } from '@tanstack/react-query';
import { api } from './services/api';

export const useAdminCustomers = () => useQuery({ queryKey: ['admin', 'customers'], queryFn: api.adminCustomers });
export const useAdminAuditLogs = () => useQuery({ queryKey: ['admin', 'audit'], queryFn: api.adminAuditLogs });
export const useAdminSecurityEvents = () => useQuery({ queryKey: ['admin', 'security'], queryFn: api.adminSecurityEvents });
export const useStaffList = () => useQuery({ queryKey: ['admin', 'staff'], queryFn: api.staffList });
