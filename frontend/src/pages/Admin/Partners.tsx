import React from 'react';
import { Chip } from '@mui/material';
import { People } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const fmt = (n: number) => n?.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) ?? '—';

const activityColor: Record<number, 'success' | 'info' | 'warning' | 'error'> = {
  1: 'success',
  4: 'info',
  3: 'warning',
  5: 'error',
};

const columns: Column[] = [
  { key: 'personName', label: 'ФИО' },
  {
    key: 'activityName',
    label: 'Активность',
    render: (val, row) => (
      <Chip label={val ?? '—'} size="small" color={activityColor[row.activityId] || 'default'} />
    ),
  },
  { key: 'statusName', label: 'Статус' },
  { key: 'personalVolume', label: 'ЛО', align: 'right', render: (v) => fmt(v) },
  { key: 'groupVolumeCumulative', label: 'ГО (накоп.)', align: 'right', render: (v) => fmt(v) },
  { key: 'participantCode', label: 'Код участника' },
  { key: 'dateCreated', label: 'Дата регистрации' },
  { key: 'terminationCount', label: 'Расторжения' },
];

const Partners: React.FC = () => (
  <AdminTable
    title="Партнёры"
    icon={<People color="primary" />}
    columns={columns}
    fetchData={adminApi.partners}
    searchPlaceholder="Поиск по ФИО, коду..."
  />
);

export default Partners;
