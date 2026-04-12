import React from 'react';
import { Chip } from '@mui/material';
import { Description } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const fmt = (n: number) => n?.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) ?? '—';

const columns: Column[] = [
  { key: 'number', label: 'Номер' },
  { key: 'clientName', label: 'Клиент' },
  { key: 'consultantName', label: 'Консультант' },
  { key: 'productName', label: 'Продукт' },
  {
    key: 'statusName',
    label: 'Статус',
    render: (val) => <Chip label={val ?? '—'} size="small" color="info" />,
  },
  { key: 'ammount', label: 'Сумма', align: 'right', render: (v) => fmt(v) },
  { key: 'openDate', label: 'Дата открытия' },
];

const ContractManager: React.FC = () => (
  <AdminTable
    title="Менеджер контрактов"
    icon={<Description color="primary" />}
    columns={columns}
    fetchData={adminApi.contracts}
    searchPlaceholder="Поиск по номеру, клиенту..."
  />
);

export default ContractManager;
