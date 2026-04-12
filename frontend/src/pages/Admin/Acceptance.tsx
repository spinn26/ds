import React from 'react';
import { Chip } from '@mui/material';
import { FactCheck } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const columns: Column[] = [
  { key: 'personName', label: 'ФИО' },
  { key: 'dateAccepted', label: 'Дата акцепта' },
  { key: 'source', label: 'Источник' },
  {
    key: 'acceptance',
    label: 'Акцепт',
    render: (val) => (
      <Chip label={val ? 'Да' : 'Нет'} size="small" color={val ? 'success' : 'default'} />
    ),
  },
];

const Acceptance: React.FC = () => (
  <AdminTable
    title="Акцепт документов"
    icon={<FactCheck color="primary" />}
    columns={columns}
    fetchData={adminApi.acceptance}
    searchPlaceholder="Поиск по ФИО..."
  />
);

export default Acceptance;
