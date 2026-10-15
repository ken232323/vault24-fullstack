import { createContext, useContext } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api';

const AuthContext = createContext(null);

// Identity always comes from the server session (GET /auth/me), never from client storage.
export function AuthProvider({ children }) {
  const qc = useQueryClient();

  const meQuery = useQuery({
    queryKey: ['me'],
    staleTime: 5 * 60 * 1000,
    queryFn: async () => {
      try {
        return (await api.me()).user;
      } catch (e) {
        if (e.status === 401) return null;
        throw e;
      }
    },
  });

  const loginMutation = useMutation({
    mutationFn: api.login,
    onSuccess: ({ user }) => qc.setQueryData(['me'], user),
  });

  const registerMutation = useMutation({ mutationFn: api.register });

  const logoutMutation = useMutation({
    mutationFn: api.logout,
    onSettled: () => {
      qc.clear(); // drop all cached financial data on logout
      qc.setQueryData(['me'], null);
    },
  });

  const value = {
    user: meQuery.data ?? null,
    loading: meQuery.isPending,
    login: loginMutation,
    register: registerMutation,
    logout: logoutMutation,
  };
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
