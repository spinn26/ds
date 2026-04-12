import api from './client';

export interface Message {
  id: number;
  category: number;
  categoryTitle: string | null;
  message: string;
  date: string;
  direction: 'ds2p' | 'p2ds';
  read: boolean;
  isIncoming: boolean;
}

export interface Category {
  id: number;
  title: string;
}

export const communicationApi = {
  list: (params?: { page?: number; category?: number }) =>
    api.get<{ data: Message[]; total: number; unreadCount: number }>('/communication', { params }),
  unreadCount: () =>
    api.get<{ count: number }>('/communication/unread-count'),
  send: (data: { category: number; message: string }) =>
    api.post<{ message: string; data: Message }>('/communication', data),
  markRead: (id: number) =>
    api.post(`/communication/${id}/read`),
  categories: () =>
    api.get<Category[]>('/communication/categories'),
};
