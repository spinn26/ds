import React from 'react';
import { Percent } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const fmt = (n: number) => n?.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) ?? '—';

const columns: Column[] = [
  { key: 'consultantName', label: 'Консультант' },
  { key: 'type', label: 'Тип' },
  { key: 'amountRUB', label: 'Сумма (RUB)', align: 'right', render: (v) => fmt(v) },
  { key: 'personalVolume', label: 'ЛО', align: 'right', render: (v) => fmt(v) },
  { key: 'groupVolume', label: 'ГО', align: 'right', render: (v) => fmt(v) },
  { key: 'groupBonusRub', label: 'Групп. бонус (RUB)', align: 'right', render: (v) => fmt(v) },
  { key: 'percent', label: '%' },
  { key: 'date', label: 'Дата' },
];

const Commissions: React.FC = () => (
  <AdminTable
    title="Комиссии по транзакциям"
    icon={<Percent color="primary" />}
    columns={columns}
    fetchData={adminApi.commissions}
    searchPlaceholder="Поиск по консультанту..."
  />
);

export default Commissions;
