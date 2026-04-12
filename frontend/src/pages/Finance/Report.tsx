import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, Grid, TextField,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Paper, CircularProgress, Chip, Tabs, Tab,
} from '@mui/material';
import { Assessment } from '@mui/icons-material';
import { motion } from 'framer-motion';
import api from '../../api/client';
import { t } from '../../i18n';

interface Commission {
  id: number; date: string; type: string;
  amount: number; amountRUB: number; amountUSD: number;
  personalVolume: number; groupVolume: number;
  groupBonus: number; groupBonusRub: number; percent: number | null;
}

interface Payment {
  id: number; amount: number; paymentDate: string;
  status: number; comment: string | null;
}

interface ReportData {
  commissions: Commission[];
  payments: Payment[];
  summary: { totalAmountRUB: number; totalAmountUSD: number; totalPersonalVolume: number; totalGroupVolume: number; totalGroupBonus: number; commissionsCount: number } | null;
  balance: { amount: number; amountRUB: number } | null;
  period: string;
}

const fmt = (n: number) => n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (s: string) => s ? new Date(s).toLocaleDateString('ru-RU') : '—';
const paymentStatus = (s: number) => {
  if (s === 1) return { label: 'Ожидает', color: 'warning' as const };
  if (s === 2) return { label: 'Выплачено', color: 'success' as const };
  return { label: 'Неизвестно', color: 'default' as const };
};

const FinanceReport: React.FC = () => {
  const [data, setData] = useState<ReportData | null>(null);
  const [loading, setLoading] = useState(true);
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [tab, setTab] = useState(0);

  useEffect(() => {
    setLoading(true);
    api.get('/finance/report', { params: { month: period } })
      .then((res) => setData(res.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [period]);

  if (loading) return <Box sx={{ display: 'flex', justifyContent: 'center', py: 10 }}><CircularProgress /></Box>;
  if (!data) return null;

  const { commissions, payments, summary, balance } = data;

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 3, flexWrap: 'wrap', gap: 2 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
          <Assessment sx={{ fontSize: 32, color: 'primary.main' }} />
          <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.report')}</Typography>
        </Box>
        <TextField type="month" value={period} onChange={(e) => setPeriod(e.target.value)}
          size="small" sx={{ width: 200 }} />
      </Box>

      {/* Summary cards */}
      {summary && (
        <Grid container spacing={2} sx={{ mb: 3 }}>
          {[
            { label: 'Начислено (RUB)', value: fmt(summary.totalAmountRUB) },
            { label: 'ЛП за период', value: fmt(summary.totalPersonalVolume) },
            { label: 'ГП за период', value: fmt(summary.totalGroupVolume) },
            { label: 'Групповой бонус', value: fmt(summary.totalGroupBonus) },
          ].map((item) => (
            <Grid size={{ xs: 6, md: 3 }} key={item.label}>
              <Card>
                <CardContent sx={{ textAlign: 'center', p: 2 }}>
                  <Typography variant="caption" color="text.secondary">{item.label}</Typography>
                  <Typography variant="h5" sx={{ fontWeight: 700 }}>{item.value}</Typography>
                </CardContent>
              </Card>
            </Grid>
          ))}
        </Grid>
      )}

      {balance && (
        <Card sx={{ mb: 3, bgcolor: '#f0f7ff' }}>
          <CardContent sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', p: 2 }}>
            <Typography variant="body1" sx={{ fontWeight: 600 }}>Текущий баланс</Typography>
            <Typography variant="h5" sx={{ fontWeight: 700 }}>{fmt(balance.amountRUB)} RUB</Typography>
          </CardContent>
        </Card>
      )}

      <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ mb: 2 }}>
        <Tab label={`Начисления (${commissions.length})`} />
        <Tab label={`Выплаты (${payments.length})`} />
      </Tabs>

      {tab === 0 && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
          <TableContainer component={Paper}>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#f5f5f5' }}>
                  <TableCell>Дата</TableCell>
                  <TableCell>Тип</TableCell>
                  <TableCell align="right">%</TableCell>
                  <TableCell align="right">Сумма (RUB)</TableCell>
                  <TableCell align="right">ЛП</TableCell>
                  <TableCell align="right">ГП</TableCell>
                  <TableCell align="right">Гр. бонус</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {commissions.map((c) => (
                  <TableRow key={c.id} hover>
                    <TableCell>{fmtDate(c.date)}</TableCell>
                    <TableCell><Chip label={c.type || '—'} size="small" variant="outlined" /></TableCell>
                    <TableCell align="right">{c.percent ?? '—'}</TableCell>
                    <TableCell align="right">{fmt(c.amountRUB)}</TableCell>
                    <TableCell align="right">{fmt(c.personalVolume)}</TableCell>
                    <TableCell align="right">{fmt(c.groupVolume)}</TableCell>
                    <TableCell align="right">{fmt(c.groupBonusRub)}</TableCell>
                  </TableRow>
                ))}
                {commissions.length === 0 && (
                  <TableRow><TableCell colSpan={7} sx={{ textAlign: 'center', py: 4, color: 'text.secondary' }}>
                    Нет начислений за период
                  </TableCell></TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>
        </motion.div>
      )}

      {tab === 1 && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
          <TableContainer component={Paper}>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#f5f5f5' }}>
                  <TableCell>Дата</TableCell>
                  <TableCell align="right">Сумма</TableCell>
                  <TableCell>Статус</TableCell>
                  <TableCell>Комментарий</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {payments.map((p) => {
                  const st = paymentStatus(p.status);
                  return (
                    <TableRow key={p.id} hover>
                      <TableCell>{fmtDate(p.paymentDate)}</TableCell>
                      <TableCell align="right">{fmt(p.amount)}</TableCell>
                      <TableCell><Chip label={st.label} size="small" color={st.color} /></TableCell>
                      <TableCell>{p.comment || '—'}</TableCell>
                    </TableRow>
                  );
                })}
                {payments.length === 0 && (
                  <TableRow><TableCell colSpan={4} sx={{ textAlign: 'center', py: 4, color: 'text.secondary' }}>
                    Нет выплат
                  </TableCell></TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>
        </motion.div>
      )}
    </Box>
  );
};

export default FinanceReport;
