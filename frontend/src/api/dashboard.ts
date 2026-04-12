import api from './client';

export interface StatusInfo {
  activityId: number;
  activityName: string;
  hasAccess: boolean;
  canInvite: boolean;
  terminationCount: number;
  maxTerminations: number;
  activationDeadline?: string;
  yearPeriodEnd?: string;
  daysRemaining?: number;
  requiredPoints?: number;
  currentPoints?: number;
}

export interface PartnerCounts {
  total: number;
  registered: number;
  active: number;
  inactive: number;
  terminated: number;
  excluded: number;
}

export interface DashboardData {
  consultant: {
    id: number;
    personName: string;
    participantCode: string | null;
    active: boolean;
    statusName: string;
    ambassadorProducts: string | null;
  };
  statusInfo: StatusInfo;
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
  partners: PartnerCounts;
  prevPartners: PartnerCounts;
  period: string;
}

export interface StatusLevel {
  id: number;
  level: number;
  title: string;
  percent: number;
  personalVolume: number;
  groupVolume: number;
  groupVolumeCumulative: number;
  otrif: number;
  pool: number;
  dsShare: number;
}

export const dashboardApi = {
  get: (month?: string) => api.get<DashboardData>('/dashboard', { params: { month } }),
  getStatusLevels: () => api.get<StatusLevel[]>('/status-levels'),
};
