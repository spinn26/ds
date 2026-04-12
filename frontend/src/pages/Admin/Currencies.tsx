import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, CircularProgress,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Paper,
} from '@mui/material';
import { CurrencyExchange } from '@mui/icons-material';
import { adminApi } from '../../api/admin';

const Currencies: React.FC = () => {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    adminApi.currencies()
      .then((res) => setData(res.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}>
        <CircularProgress />
      </Box>
    );
  }

  const currencies = data?.currencies ?? data?.data ?? [];
  const vatRates = data?.vatRates ?? data?.vat ?? [];

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <CurrencyExchange color="primary" />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>Валюты и НДС</Typography>
      </Box>

      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Typography variant="h6" sx={{ mb: 2 }}>Валюты</Typography>
          <TableContainer component={Paper} variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#f5f5f5' }}>
                  <TableCell>ID</TableCell>
                  <TableCell>Код</TableCell>
                  <TableCell>Название</TableCell>
                  <TableCell>Символ</TableCell>
                  <TableCell align="right">Курс</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {(Array.isArray(currencies) ? currencies : []).map((c: any, idx: number) => (
                  <TableRow key={c.id ?? idx} hover>
                    <TableCell>{c.id ?? '—'}</TableCell>
                    <TableCell>{c.code ?? '—'}</TableCell>
                    <TableCell>{c.name ?? '—'}</TableCell>
                    <TableCell>{c.symbol ?? '—'}</TableCell>
                    <TableCell align="right">
                      {c.rate?.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) ?? '—'}
                    </TableCell>
                  </TableRow>
                ))}
                {(!Array.isArray(currencies) || currencies.length === 0) && (
                  <TableRow>
                    <TableCell colSpan={5} sx={{ textAlign: 'center', py: 3, color: 'text.secondary' }}>
                      Данные не найдены
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Typography variant="h6" sx={{ mb: 2 }}>Ставки НДС</Typography>
          <TableContainer component={Paper} variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#f5f5f5' }}>
                  <TableCell>ID</TableCell>
                  <TableCell>Название</TableCell>
                  <TableCell align="right">Ставка (%)</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {(Array.isArray(vatRates) ? vatRates : []).map((v: any, idx: number) => (
                  <TableRow key={v.id ?? idx} hover>
                    <TableCell>{v.id ?? '—'}</TableCell>
                    <TableCell>{v.name ?? '—'}</TableCell>
                    <TableCell align="right">{v.rate ?? v.percent ?? '—'}</TableCell>
                  </TableRow>
                ))}
                {(!Array.isArray(vatRates) || vatRates.length === 0) && (
                  <TableRow>
                    <TableCell colSpan={3} sx={{ textAlign: 'center', py: 3, color: 'text.secondary' }}>
                      Данные не найдены
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>
        </CardContent>
      </Card>
    </Box>
  );
};

export default Currencies;
