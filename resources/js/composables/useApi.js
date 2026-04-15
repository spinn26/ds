import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query';
import { computed } from 'vue';
import api from '../api';

/**
 * Cached dashboard data with vue-query.
 */
export function useDashboard(month) {
  return useQuery({
    queryKey: ['dashboard', month],
    queryFn: async () => {
      const { data } = await api.get('/dashboard', { params: { month: month.value } });
      return data;
    },
    staleTime: 2 * 60_000, // fresh for 2 minutes
  });
}

/**
 * Cached status levels (rarely change).
 */
export function useStatusLevels() {
  return useQuery({
    queryKey: ['status-levels'],
    queryFn: async () => {
      const { data } = await api.get('/status-levels');
      return data;
    },
    staleTime: 30 * 60_000, // fresh for 30 minutes
    gcTime: 60 * 60_000,
  });
}

/**
 * Cached workspace data.
 */
export function useWorkspace() {
  return useQuery({
    queryKey: ['workspace'],
    queryFn: async () => {
      const { data } = await api.get('/workspace');
      return data;
    },
    staleTime: 60_000,
  });
}

/**
 * Cached profile data.
 */
export function useProfile() {
  return useQuery({
    queryKey: ['profile'],
    queryFn: async () => {
      const { data } = await api.get('/profile');
      return data;
    },
    staleTime: 5 * 60_000,
  });
}

/**
 * Finance report for a given month.
 */
export function useFinanceReport(month) {
  return useQuery({
    queryKey: ['finance-report', month],
    queryFn: async () => {
      const { data } = await api.get('/finance/report', { params: { month: month.value } });
      return data;
    },
    staleTime: 2 * 60_000,
  });
}

/**
 * Notifications with short cache.
 */
export function useNotifications() {
  return useQuery({
    queryKey: ['notifications'],
    queryFn: async () => {
      const [listRes, countRes] = await Promise.all([
        api.get('/notifications'),
        api.get('/notifications/unread-count'),
      ]);
      return {
        items: listRes.data || [],
        unreadCount: countRes.data.count || 0,
      };
    },
    staleTime: 30_000, // refresh every 30s
    refetchInterval: 60_000,
  });
}

/**
 * Products list (cached long).
 */
export function useProducts() {
  return useQuery({
    queryKey: ['products'],
    queryFn: async () => {
      const { data } = await api.get('/products');
      return data;
    },
    staleTime: 10 * 60_000,
  });
}

/**
 * Invalidate queries helper.
 */
export function useInvalidate() {
  const queryClient = useQueryClient();
  return {
    invalidate: (key) => queryClient.invalidateQueries({ queryKey: [key] }),
    invalidateAll: () => queryClient.invalidateQueries(),
  };
}
