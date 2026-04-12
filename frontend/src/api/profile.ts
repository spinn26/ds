import api from './client';

export interface RequisiteData {
  id: number;
  individualEntrepreneur: string;
  inn: string;
  ogrn: string | null;
  address: string | null;
  registrationDate: string | null;
  email: string | null;
  phone: string | null;
  verified: boolean;
  statusName: string | null;
}

export interface BankRequisiteData {
  id: number;
  bankName: string;
  bankBik: string;
  accountNumber: string;
  correspondentAccount: string | null;
  beneficiaryName: string;
  verified: boolean;
}

export interface SignedDocuments {
  accepted: boolean;
  acceptedAt: string | null;
  documents: { id: number; name: string; link: string }[];
}

export interface ReferralInfo {
  canInvite: boolean;
  referralCode: string | null;
  referralLink: string | null;
}

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

export interface ProfileData {
  user: {
    id: number;
    email: string;
    firstName: string;
    lastName: string;
    patronymic: string | null;
    phone: string | null;
    nicTG: string | null;
    gender: string | null;
    birthDate: string | null;
    role: string;
  };
  location: {
    taxResidency: string | null;
    city: string | null;
  };
  consultant: {
    id: number;
    personName: string;
    participantCode: string | null;
    active: boolean;
    dateCreated: string | null;
    inviterName: string | null;
  } | null;
  statusInfo: StatusInfo | null;
  signedDocuments: SignedDocuments;
  requisites: RequisiteData | null;
  bankRequisites: BankRequisiteData | null;
  referral: ReferralInfo | null;
}

export interface AgreementDocument {
  id: number;
  name: string;
  link: string;
  number: number;
}

export const profileApi = {
  get: () => api.get<ProfileData>('/profile'),
  update: (data: { phone?: string; nicTG?: string; gender?: string; birthDate?: string }) =>
    api.put('/profile', data),
  changePassword: (data: { current_password: string; password: string; password_confirmation: string }) =>
    api.post('/profile/password', data),
  updateRequisites: (data: Partial<RequisiteData>) =>
    api.put('/profile/requisites', data),
  updateBankRequisites: (data: Partial<BankRequisiteData>) =>
    api.put('/profile/bank-requisites', data),
  getAgreementDocuments: () =>
    api.get<AgreementDocument[]>('/profile/agreement-documents'),
};
