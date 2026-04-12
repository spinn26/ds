import api from './client';

export interface PaginatedResponse<T> {
  data: T[];
  total: number;
}

export const adminApi = {
  // Partners
  partners: (params?: any) => api.get<PaginatedResponse<any>>('/admin/partners', { params }),
  changePartnerStatus: (id: number, data: { action: string; reason?: string }) =>
    api.post(`/admin/partners/${id}/status`, data),
  partnerStatuses: () => api.get<any[]>('/admin/partner-statuses'),

  // Clients
  clients: (params?: any) => api.get<PaginatedResponse<any>>('/admin/clients', { params }),

  // Requisites
  requisites: (params?: any) => api.get<PaginatedResponse<any>>('/admin/requisites', { params }),
  verifyRequisites: (id: number, data: { action: string; comment?: string }) =>
    api.post(`/admin/requisites/${id}/verify`, data),

  // Acceptance
  acceptance: (params?: any) => api.get<PaginatedResponse<any>>('/admin/acceptance', { params }),

  // Contracts
  contracts: (params?: any) => api.get<PaginatedResponse<any>>('/admin/contracts', { params }),

  // Transfers
  transfers: (params?: any) => api.get<PaginatedResponse<any>>('/admin/transfers', { params }),

  // Finance
  transactions: (params?: any) => api.get<PaginatedResponse<any>>('/admin/transactions', { params }),
  commissions: (params?: any) => api.get<PaginatedResponse<any>>('/admin/commissions', { params }),
  pool: (params?: any) => api.get<PaginatedResponse<any>>('/admin/pool', { params }),
  qualifications: (params?: any) => api.get<PaginatedResponse<any>>('/admin/qualifications', { params }),
  charges: (params?: any) => api.get<any>('/admin/charges', { params }),
  payments: (params?: any) => api.get<PaginatedResponse<any>>('/admin/payments', { params }),
  reports: () => api.get<any>('/admin/reports'),
  reportAvailability: () => api.get<any>('/admin/report-availability'),
  currencies: () => api.get<any>('/admin/currencies'),
  transactionImport: () => api.get<any>('/admin/transaction-import'),
};
