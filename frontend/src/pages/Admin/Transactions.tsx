import React from 'react';
import { Receipt } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const fmt = (n: number) => n?.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) ?? '—';

const columns: Column[] = [
  { key: 'id', label: 'ID' },
  { key: 'contract', label: 'Контракт' },
  { key: 'amount', label: 'Сумма', align: 'right', render: (v) => fmt(v) },
  { key: 'amountRUB', label: 'Сумма (RUB)', align: 'right', render: (v) => fmt(v) },
  { key: 'amountUSD', label: 'Сумма (USD)', align: 'right', render: (v) => fmt(v) },
  { key: 'date', label: 'Дата' },
  { key: 'currencySymbol', label: 'Валюта' },
];

const AdminTransactions: React.FC = () => (
  <AdminTable
    title="Транзакции"
    icon={<Receipt color="primary" />}
    columns={columns}
    fetchData={adminApi.transactions}
    searchPlaceholder="Поиск по контракту, ID..."
  />
);

export default AdminTransactions;
