import React from 'react';
import { Hub } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const fmt = (n: number) => n?.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) ?? '—';

const columns: Column[] = [
  { key: 'consultantName', label: 'Консультант' },
  { key: 'amount', label: 'Сумма', align: 'right', render: (v) => fmt(v) },
  { key: 'percent', label: '%' },
  { key: 'date', label: 'Дата' },
];

const Pool: React.FC = () => (
  <AdminTable
    title="Комиссии пула"
    icon={<Hub color="primary" />}
    columns={columns}
    fetchData={adminApi.pool}
    searchPlaceholder="Поиск по консультанту..."
  />
);

export default Pool;
