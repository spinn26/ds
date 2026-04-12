import React from 'react';
import { Chip } from '@mui/material';
import { PersonSearch } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const columns: Column[] = [
  { key: 'id', label: 'ID' },
  { key: 'personName', label: 'ФИО' },
  { key: 'consultantName', label: 'Консультант' },
  {
    key: 'active',
    label: 'Активен',
    render: (val) => (
      <Chip label={val ? 'Да' : 'Нет'} size="small" color={val ? 'success' : 'default'} />
    ),
  },
  { key: 'dateCreated', label: 'Дата создания' },
];

const AdminClients: React.FC = () => (
  <AdminTable
    title="Клиенты"
    icon={<PersonSearch color="primary" />}
    columns={columns}
    fetchData={adminApi.clients}
    searchPlaceholder="Поиск по ФИО, консультанту..."
  />
);

export default AdminClients;
