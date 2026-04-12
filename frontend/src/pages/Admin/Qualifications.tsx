import React from 'react';
import { EmojiEvents } from '@mui/icons-material';
import AdminTable, { Column } from '../../components/AdminTable';
import { adminApi } from '../../api/admin';

const fmt = (n: number) => n?.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) ?? '—';

const columns: Column[] = [
  { key: 'consultantName', label: 'Консультант' },
  { key: 'personalVolume', label: 'ЛО', align: 'right', render: (v) => fmt(v) },
  { key: 'groupVolume', label: 'ГО', align: 'right', render: (v) => fmt(v) },
  { key: 'groupVolumeCumulative', label: 'ГО (накоп.)', align: 'right', render: (v) => fmt(v) },
  { key: 'nominalLevel', label: 'Номинальный уровень' },
  { key: 'calculationLevel', label: 'Расчётный уровень' },
  { key: 'result', label: 'Результат' },
  { key: 'date', label: 'Дата' },
];

const Qualifications: React.FC = () => (
  <AdminTable
    title="Квалификации"
    icon={<EmojiEvents color="primary" />}
    columns={columns}
    fetchData={adminApi.qualifications}
    searchPlaceholder="Поиск по консультанту..."
  />
);

export default Qualifications;
