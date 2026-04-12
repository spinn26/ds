import React from 'react';
import { SwapHoriz } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const columns: Column[] = [
  { key: 'personName', label: 'ФИО' },
  { key: 'dateCreated', label: 'Дата' },
];

const Transfers: React.FC = () => (
  <AdminTable
    title="История перестановок"
    icon={<SwapHoriz color="primary" />}
    columns={columns}
    fetchData={adminApi.transfers}
    searchPlaceholder="Поиск по ФИО..."
  />
);

export default Transfers;
