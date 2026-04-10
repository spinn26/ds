import React, { useState, useEffect } from 'react';
import {
  Box, Typography, TextField, Card, CardContent, Chip,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Paper, TablePagination, CircularProgress, InputAdornment,
} from '@mui/material';
import { Search, Description } from '@mui/icons-material';
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
  statusName: string;
  ammount: number;
  currencySymbol: string;
  openDate: string | null;
}

const statusColor = (s: string): 'success' | 'warning' | 'error' | 'default' => {
  if (s === 'Активирован') return 'success';
  if (s?.includes('Закрыт')) return 'error';
  if (s === 'Сбор документов') return 'warning';
  return 'default';
};

const MyContracts: React.FC = () => {
  const [contracts, setContracts] = useState<ContractItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(0);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    const params: any = { page: page + 1 };
    if (search) params.search = search;

    api.get('/contracts/my', { params })
      .then((res) => { setContracts(res.data.data); setTotal(res.data.total); })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [page, search]);

  const fmt = (n: number) => n ? n.toLocaleString('ru-RU', { minimumFractionDigits: 0 }) : '—';

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <Description sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.myContracts')}</Typography>
        <Chip label={`${total}`} color="primary" size="small" />
      </Box>

      <Card sx={{ mb: 2 }}>
        <CardContent sx={{ py: 2 }}>
          <TextField
            size="small" placeholder="Поиск по клиенту или номеру..." value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(0); }}
            sx={{ minWidth: 300 }}
            slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search /></InputAdornment> } }}
          />
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
                  <TableCell>Клиент</TableCell>
                  <TableCell>Продукт</TableCell>
                  <TableCell>Программа</TableCell>
                  <TableCell>Статус</TableCell>
                  <TableCell align="right">Сумма</TableCell>
                  <TableCell>Открыт</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {contracts.map((c) => (
                  <TableRow key={c.id} hover>
                    <TableCell sx={{ fontWeight: 600 }}>{c.number || c.id}</TableCell>
                    <TableCell>{c.clientName}</TableCell>
                    <TableCell>{c.productName}</TableCell>
                    <TableCell>{c.programName}</TableCell>
                    <TableCell>
                      <Chip label={c.statusName || '—'} size="small" color={statusColor(c.statusName)} />
                    </TableCell>
                    <TableCell align="right">
                      {fmt(c.ammount)} {c.currencySymbol || ''}
                    </TableCell>
                    <TableCell>{c.openDate || '—'}</TableCell>
                  </TableRow>
                ))}
                {contracts.length === 0 && (
                  <TableRow><TableCell colSpan={7} sx={{ textAlign: 'center', py: 4, color: 'text.secondary' }}>
                    Контракты не найдены
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

export default MyContracts;
