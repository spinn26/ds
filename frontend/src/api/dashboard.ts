import api from './client';

export interface DashboardData {
  consultant: {
    id: number;
    personName: string;
    participantCode: string | null;
    active: boolean;
    statusName: string;
    ambassadorProducts: string | null;
  };
  qualification: {
    nominalLevel: StatusLevel | null;
    nextLevel: StatusLevel | null;
  };
  volumes: {
    personalVolume: number;
    groupVolume: number;
    groupVolumeCumulative: number;
    prevPersonalVolume: number;
    prevGroupVolume: number;
    prevGroupVolumeCumulative: number;
  };
  team: {
    myClients: number;
    teamClients: number;
    firstLineResidents: number;
    totalResidents: number;
    firstLineConsultants: number;
    totalConsultants: number;
    capitalUsd: number;
  };
  period: string;
}

export interface StatusLevel {
  id: number;
  level: number;
  title: string;
  percent: number;
  groupVolume: number;
  groupVolumeCumulative: number;
  pool: number;
}

export const dashboardApi = {
  get: (month?: string) => api.get<DashboardData>('/dashboard', { params: { month } }),
  getStatusLevels: () => api.get<StatusLevel[]>('/status-levels'),
};
