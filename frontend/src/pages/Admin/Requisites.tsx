import React from 'react';
import { Chip, Button, Stack } from '@mui/material';
import { AccountBalance } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const columns: Column[] = [
  { key: 'consultantName', label: 'Консультант' },
  { key: 'individualEntrepreneur', label: 'ИП' },
  { key: 'inn', label: 'ИНН' },
  {
    key: 'verified',
    label: 'Верифицирован',
    render: (val) => (
      <Chip label={val ? 'Да' : 'Нет'} size="small" color={val ? 'success' : 'warning'} />
    ),
  },
  {
    key: 'hasBankRequisites',
    label: 'Банк. реквизиты',
    render: (val) => (
      <Chip label={val ? 'Да' : 'Нет'} size="small" color={val ? 'success' : 'default'} />
    ),
  },
  {
    key: 'bankVerified',
    label: 'Банк верифицирован',
    render: (val) => (
      <Chip label={val ? 'Да' : 'Нет'} size="small" color={val ? 'success' : 'default'} />
    ),
  },
];

const Requisites: React.FC = () => (
  <AdminTable
    title="Реквизиты партнёров"
    icon={<AccountBalance color="primary" />}
    columns={columns}
    fetchData={adminApi.requisites}
    searchPlaceholder="Поиск по ФИО, ИНН..."
    actions={(row) => (
      <Stack direction="row" spacing={1}>
        <Button
          size="small"
          variant="contained"
          color="success"
          onClick={() => adminApi.verifyRequisites(row.id, { action: 'verify' })}
        >
          Подтвердить
        </Button>
        <Button
          size="small"
          variant="outlined"
          color="error"
          onClick={() => adminApi.verifyRequisites(row.id, { action: 'reject' })}
        >
          Отклонить
        </Button>
      </Stack>
    )}
  />
);

export default Requisites;
