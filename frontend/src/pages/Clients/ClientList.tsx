import React, { useState, useEffect } from 'react';
import {
  Box, Typography, TextField, Card, CardContent, Chip,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Paper, TablePagination, CircularProgress, InputAdornment,
} from '@mui/material';
import { Search, People } from '@mui/icons-material';
import { motion } from 'framer-motion';
import api from '../../api/client';
import { t } from '../../i18n';

interface ClientItem {
  id: number;
  personName: string;
  birthDate: string | null;
  city: string | null;
  phone: string | null;
  email: string | null;
  products: string[];
}

const ClientList: React.FC = () => {
  const [clients, setClients] = useState<ClientItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(0);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    const params: any = { page: page + 1 };
    if (search) params.search = search;

    api.get('/clients', { params })
      .then((res) => { setClients(res.data.data); setTotal(res.data.total); })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [page, search]);

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <People sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.clientList')}</Typography>
        <Chip label={`${total}`} color="primary" size="small" />
      </Box>

      <Card sx={{ mb: 2 }}>
        <CardContent sx={{ py: 2 }}>
          <TextField
            size="small" placeholder="Поиск по ФИО..." value={search}
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
                  <TableCell>ФИО клиента</TableCell>
                  <TableCell>Дата рождения</TableCell>
                  <TableCell>Место жительства</TableCell>
                  <TableCell>Телефон</TableCell>
                  <TableCell>Email</TableCell>
                  <TableCell>Открытые продукты</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {clients.map((c) => (
                  <TableRow key={c.id} hover>
                    <TableCell sx={{ fontWeight: 600 }}>{c.personName}</TableCell>
                    <TableCell>{c.birthDate || '—'}</TableCell>
                    <TableCell>{c.city || '—'}</TableCell>
                    <TableCell>{c.phone || '—'}</TableCell>
                    <TableCell>{c.email || '—'}</TableCell>
                    <TableCell>
                      {c.products.length > 0
                        ? c.products.map((p) => (
                            <Chip key={p} label={p} size="small" variant="outlined" sx={{ mr: 0.5, mb: 0.5 }} />
                          ))
                        : '—'
                      }
                    </TableCell>
                  </TableRow>
                ))}
                {clients.length === 0 && (
                  <TableRow><TableCell colSpan={6} sx={{ textAlign: 'center', py: 4, color: 'text.secondary' }}>
                    Клиенты не найдены
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

export default ClientList;
