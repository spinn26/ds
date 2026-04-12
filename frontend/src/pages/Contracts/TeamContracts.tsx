import React, { useState, useEffect } from 'react';
import {
  Box, Typography, TextField, Card, CardContent, Chip,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Paper, TablePagination, CircularProgress, InputAdornment, Grid,
  FormControl, InputLabel, Select, MenuItem,
} from '@mui/material';
import { Search, FolderShared } from '@mui/icons-material';
import { motion } from 'framer-motion';
import api from '../../api/client';
import { t } from '../../i18n';

interface ContractItem {
  id: number;
  number: string;
  clientName: string;
  consultantName: string;
  productName: string;
  programName: string;
  term: number | null;
  statusName: string;
  ammount: number;
  currencySymbol: string;
  openDate: string | null;
}

interface FilterOption { id: number; name: string; }

const statusColor = (s: string): 'success' | 'warning' | 'error' | 'default' => {
  if (s === 'Активирован') return 'success';
  if (s?.includes('Закрыт')) return 'error';
  if (s === 'Сбор документов') return 'warning';
  return 'default';
};

const TeamContracts: React.FC = () => {
  const [contracts, setContracts] = useState<ContractItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(0);
  const [search, setSearch] = useState('');
  const [consultantSearch, setConsultantSearch] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [statuses, setStatuses] = useState<FilterOption[]>([]);

  useEffect(() => {
    api.get('/contracts/statuses').then((r) => setStatuses(r.data)).catch(() => {});
  }, []);

  useEffect(() => {
    setLoading(true);
    const params: any = { page: page + 1 };
    if (search) params.search = search;
    if (consultantSearch) params.consultant_search = consultantSearch;
    if (status) params.status = status;

    api.get('/contracts/team', { params })
      .then((res) => { setContracts(res.data.data); setTotal(res.data.total); })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [page, search, consultantSearch, status]);

  const fmt = (n: number) => n ? n.toLocaleString('ru-RU', { minimumFractionDigits: 0 }) : '—';

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <FolderShared sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.teamContracts')}</Typography>
        <Chip label={`${total}`} color="primary" size="small" />
      </Box>

      <Card sx={{ mb: 2 }}>
        <CardContent sx={{ py: 2 }}>
          <Grid container spacing={2} sx={{ alignItems: 'center' }}>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TextField
                fullWidth size="small" placeholder="ФИО клиента или номер..." value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(0); }}
                slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search /></InputAdornment> } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TextField
                fullWidth size="small" placeholder="ФИО консультанта..." value={consultantSearch}
                onChange={(e) => { setConsultantSearch(e.target.value); setPage(0); }}
                slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search /></InputAdornment> } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <FormControl fullWidth size="small">
                <InputLabel>Статус контракта</InputLabel>
                <Select value={status} label="Статус контракта" onChange={(e) => { setStatus(e.target.value); setPage(0); }}>
                  <MenuItem value="">Все</MenuItem>
                  {statuses.map((s) => <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>)}
                </Select>
              </FormControl>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {loading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}><CircularProgress /></Box>
      ) : (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
          <TableContainer component={Paper}>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#f5f5f5' }}>
                  <TableCell>Номер</TableCell>
                  <TableCell>ФИО клиента</TableCell>
                  <TableCell>ФИО ФК</TableCell>
                  <TableCell>Продукт</TableCell>
                  <TableCell>Статус</TableCell>
                  <TableCell align="center">Срок</TableCell>
                  <TableCell align="right">Сумма</TableCell>
                  <TableCell>Открыт</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {contracts.map((c) => (
                  <TableRow key={c.id} hover>
                    <TableCell sx={{ fontWeight: 600 }}>{c.number || c.id}</TableCell>
                    <TableCell>{c.clientName}</TableCell>
                    <TableCell>{c.consultantName}</TableCell>
                    <TableCell>{c.productName || '—'}</TableCell>
                    <TableCell>
                      <Chip label={c.statusName || '—'} size="small" color={statusColor(c.statusName)} />
                    </TableCell>
                    <TableCell align="center">{c.term || '—'}</TableCell>
                    <TableCell align="right">{fmt(c.ammount)} {c.currencySymbol || ''}</TableCell>
                    <TableCell>{c.openDate || '—'}</TableCell>
                  </TableRow>
                ))}
                {contracts.length === 0 && (
                  <TableRow><TableCell colSpan={8} sx={{ textAlign: 'center', py: 4, color: 'text.secondary' }}>
                    Контракты команды не найдены
                  </TableCell></TableRow>
                )}
              </TableBody>
            </Table>
            <TablePagination
              component="div" count={total} page={page} rowsPerPage={25}
              onPageChange={(_, p) => setPage(p)} rowsPerPageOptions={[25]}
              labelDisplayedRows={({ from, to, count }) => `${from}–${to} из ${count}`}
            />
          </TableContainer>
        </motion.div>
      )}
    </Box>
  );
};

export default TeamContracts;
