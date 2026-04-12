import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, TextField, InputAdornment,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Paper, TablePagination, CircularProgress, Chip,
} from '@mui/material';
import { Search } from '@mui/icons-material';

export interface Column {
  key: string;
  label: string;
  align?: 'left' | 'right' | 'center';
  render?: (value: any, row: any) => React.ReactNode;
}

interface AdminTableProps {
  title: string;
  icon: React.ReactNode;
  columns: Column[];
  fetchData: (params: any) => Promise<{ data: { data: any[]; total: number } }>;
  searchPlaceholder?: string;
  extraFilters?: React.ReactNode;
  actions?: (row: any) => React.ReactNode;
}

const AdminTable: React.FC<AdminTableProps> = ({
  title, icon, columns, fetchData, searchPlaceholder, extraFilters, actions,
}) => {
  const [data, setData] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(0);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);

  const load = () => {
    setLoading(true);
    const params: any = { page: page + 1 };
    if (search) params.search = search;

    fetchData(params)
      .then((res) => { setData(res.data.data); setTotal(res.data.total); })
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, [page, search]);

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        {icon}
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{title}</Typography>
        <Chip label={`${total}`} color="primary" size="small" />
      </Box>

      <Card sx={{ mb: 2 }}>
        <CardContent sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center', py: 2 }}>
          <TextField
            size="small" placeholder={searchPlaceholder || 'Поиск...'} value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(0); }}
            sx={{ minWidth: 280 }}
            slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search /></InputAdornment> } }}
          />
          {extraFilters}
        </CardContent>
      </Card>

      {loading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}><CircularProgress /></Box>
      ) : (
        <TableContainer component={Paper}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: '#f5f5f5' }}>
                {columns.map((col) => (
                  <TableCell key={col.key} align={col.align || 'left'}>{col.label}</TableCell>
                ))}
                {actions && <TableCell align="center">Действия</TableCell>}
              </TableRow>
            </TableHead>
            <TableBody>
              {data.map((row, idx) => (
                <TableRow key={row.id || idx} hover>
                  {columns.map((col) => (
                    <TableCell key={col.key} align={col.align || 'left'}>
                      {col.render ? col.render(row[col.key], row) : (row[col.key] ?? '—')}
                    </TableCell>
                  ))}
                  {actions && <TableCell align="center">{actions(row)}</TableCell>}
                </TableRow>
              ))}
              {data.length === 0 && (
                <TableRow>
                  <TableCell colSpan={columns.length + (actions ? 1 : 0)} sx={{ textAlign: 'center', py: 4, color: 'text.secondary' }}>
                    Данные не найдены
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
          <TablePagination
            component="div" count={total} page={page} rowsPerPage={25}
            onPageChange={(_, p) => setPage(p)} rowsPerPageOptions={[25]}
            labelDisplayedRows={({ from, to, count }) => `${from}–${to} из ${count}`}
          />
        </TableContainer>
      )}
    </Box>
  );
};

export default AdminTable;
