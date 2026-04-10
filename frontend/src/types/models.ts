export interface User {
  id: number;
  email: string;
  firstName: string;
  lastName: string;
  patronymic?: string;
  phone?: string;
  role: string;
  gender?: string;
  birthDate?: string;
}

export interface Consultant {
  id: number;
  personName: string;
  active: boolean;
  status: number;
  country: number;
  personalVolume: number;
  groupVolume: number;
  groupVolumeCumulative: number;
  participantCode?: string;
  inviterName?: string;
  dateCreated?: string;
}

export interface Client {
  id: number;
  personName: string;
  consultantName?: string;
  consultant: number;
  active: boolean;
  source?: string;
  comment?: string;
  dateCreated?: string;
}

export interface Contract {
  id: number;
  number: string;
  clientName: string;
  consultantName: string;
  productName: string;
  programName: string;
  status: number;
  ammount: number;
  currency: number;
  openDate?: string;
  closeDate?: string;
  comment?: string;
}

export interface Transaction {
  id: number;
  contract: number;
  amount: number;
  amountRUB: number;
  amountUSD: number;
  currency: number;
  dsCommissionPercentage: number;
  date: string;
  comment?: string;
}

export interface Commission {
  id: number;
  transaction: number;
  consultant: number;
  amount: number;
  amountRUB: number;
  amountUSD: number;
  personalVolume: number;
  groupVolume: number;
  groupBonus: number;
  groupBonusRub: number;
  date: string;
  type: string;
}

export interface QualificationLog {
  id: number;
  consultant: number;
  consultantPersonName: string;
  personalVolume: number;
  groupVolume: number;
  groupVolumeCumulative: number;
  levelNew: number;
  levelPrevious: number;
  date: string;
}

export interface Product {
  id: number;
  name: string;
  typeName: string;
  active: boolean;
}

export interface Program {
  id: number;
  name: string;
  productName: string;
  providerName: string;
  currencyName: string;
  active: boolean;
}
