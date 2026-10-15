import { useQuery } from '@tanstack/react-query';
import { api } from './services/api';

export const useAccounts = () => useQuery({ queryKey: ['accounts'], queryFn: api.accounts });
export const useTransactions = () => useQuery({ queryKey: ['transactions'], queryFn: api.transactions });

