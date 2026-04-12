import React from 'react';
import { Chip } from '@mui/material';
import { Payments as PaymentsIcon } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const fmt = (n: number) => n?.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) ?? '—';

const statusMap: Record<number, { label: string; color: 'warning' | 'success' }> = {
  1: { label: 'Ожидает', color: 'warning' },
  2: { label: 'Выплачено', color: 'success' },
};

const columns: Column[] = [
  { key: 'consultantName', label: 'Консультант' },
  { key: 'amount', label: 'Сумма', align: 'right', render: (v) => fmt(v) },
  { key: 'paymentDate', label: 'Дата выплаты' },
  {
    key: 'status',
    label: 'Статус',
    render: (val) => {
      const s = statusMap[val];
      return s
        ? <Chip label={s.label} size="small" color={s.color} />
        : <Chip label={val ?? '—'} size="small" color="default" />;
    },
  },
  { key: 'comment', label: 'Комментарий' },
];

const Payments: React.FC = () => (
  <AdminTable
    title="Реестр выплат партнёрам"
    icon={<PaymentsIcon color="primary" />}
    columns={columns}
    fetchData={adminApi.payments}
    searchPlaceholder="Поиск по консультанту..."
  />
);

export default Payments;
