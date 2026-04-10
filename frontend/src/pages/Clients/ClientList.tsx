import React, { useState, useEffect } from 'react';
import {
  Box, Typography, TextField, Card, CardContent, Chip,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Paper, TablePagination, CircularProgress, InputAdornment,
  ToggleButton, ToggleButtonGroup,
} from '@mui/material';
import { Search, People } from '@mui/icons-material';
import { motion } from 'framer-motion';
import api from '../../api/client';
import { t } from '../../i18n';

interface ClientItem {
  id: number;
  personName: string;
  active: boolean;
  source: string | null;
  comment: string | null;
  dateCreated: string | null;
}

const ClientList: React.FC = () => {
  const [clients, setClients] = useState<ClientItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(0);
  const [search, setSearch] = useState('');
  const [activeFilter, setActiveFilter] = useState<string>('all');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    const params: any = { page: page + 1 };
    if (search) params.search = search;
    if (activeFilter !== 'all') params.active = activeFilter;

    api.get('/clients', { params })
      .then((res) => { setClients(res.data.data); setTotal(res.data.total); })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [page, search, activeFilter]);

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <People sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.clientList')}</Typography>
        <Chip label={`${total}`} color="primary" size="small" />
      </Box>

      <Card sx={{ mb: 2 }}>
        <CardContent sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center', py: 2 }}>
          <TextField
            size="small" placeholder="Поиск по ФИО..." value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(0); }}
            sx={{ minWidth: 250 }}
            slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search /></InputAdornment> } }}
          />
          <ToggleButtonGroup
            value={activeFilter} exclusive size="small"
            onChange={(_, val) => { setActiveFilter(val); setPage(0); }}
          >
            <ToggleButton value="all">Все</ToggleButton>
            <ToggleButton value="true">Активные</ToggleButton>
            <ToggleButton value="false">Неактивные</ToggleButton>
          </ToggleButtonGroup>
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
                  <TableCell>ID</TableCell>
                  <TableCell>ФИО</TableCell>
                  <TableCell>Статус</TableCell>
                  <TableCell>Источник</TableCell>
                  <TableCell>Дата создания</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {clients.map((c) => (
                  <TableRow key={c.id} hover>
                    <TableCell>{c.id}</TableCell>
                    <TableCell sx={{ fontWeight: 600 }}>{c.personName}</TableCell>
                    <TableCell>
                      <Chip label={c.active ? 'Активен' : 'Неактивен'} size="small"
                        color={c.active ? 'success' : 'default'} />
                    </TableCell>
                    <TableCell>{c.source || '—'}</TableCell>
                    <TableCell>{c.dateCreated || '—'}</TableCell>
                  </TableRow>
                ))}
                {clients.length === 0 && (
                  <TableRow><TableCell colSpan={5} sx={{ textAlign: 'center', py: 4, color: 'text.secondary' }}>
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
