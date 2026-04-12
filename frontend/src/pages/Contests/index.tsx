import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, Grid, Chip, CircularProgress,
  FormControl, InputLabel, Select, MenuItem, Button, Alert,
} from '@mui/material';
import { EmojiEvents, OpenInNew } from '@mui/icons-material';
import { motion } from 'framer-motion';
import api from '../../api/client';
import { t } from '../../i18n';

interface Contest {
  id: number; name: string; description: string | null;
  typeName: string | null; status: number; statusLabel: string;
  start: string | null; end: string | null;
  numberOfWinners: number | null;
  resultsPublicationDate: string | null;
  presentation: string | null;
}

const statusColor = (s: number): 'success' | 'warning' | 'default' => {
  if (s === 1) return 'success';
  if (s === 2) return 'warning';
  return 'default';
};

const fmtDate = (s: string | null) => s ? new Date(s).toLocaleDateString('ru-RU') : '—';

const Contests: React.FC = () => {
  const [contests, setContests] = useState<Contest[]>([]);
  const [types, setTypes] = useState<{ id: number; name: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');

  useEffect(() => {
    setLoading(true);
    const params: any = {};
    if (statusFilter) params.status = statusFilter;
    if (typeFilter) params.type = typeFilter;

    api.get('/contests', { params })
      .then((res) => {
        setContests(res.data.contests);
        setTypes(res.data.types || []);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [statusFilter, typeFilter]);

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <EmojiEvents sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.contestList')}</Typography>
        <Chip label={`${contests.length}`} color="primary" size="small" />
      </Box>

      {/* Filters */}
      <Card sx={{ mb: 3 }}>
        <CardContent sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', py: 2 }}>
          <FormControl size="small" sx={{ minWidth: 160 }}>
            <InputLabel>Статус</InputLabel>
            <Select value={statusFilter} label="Статус"
              onChange={(e) => setStatusFilter(e.target.value)}>
              <MenuItem value="">Все</MenuItem>
              <MenuItem value="1">Активные</MenuItem>
              <MenuItem value="2">Завершённые</MenuItem>
              <MenuItem value="3">Архив</MenuItem>
            </Select>
          </FormControl>
          {types.length > 0 && (
            <FormControl size="small" sx={{ minWidth: 160 }}>
              <InputLabel>Тип</InputLabel>
              <Select value={typeFilter} label="Тип"
                onChange={(e) => setTypeFilter(e.target.value)}>
                <MenuItem value="">Все</MenuItem>
                {types.map((t) => <MenuItem key={t.id} value={t.id}>{t.name}</MenuItem>)}
              </Select>
            </FormControl>
          )}
        </CardContent>
      </Card>

      {loading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}><CircularProgress /></Box>
      ) : contests.length === 0 ? (
        <Alert severity="info">Конкурсов не найдено</Alert>
      ) : (
        <Grid container spacing={3}>
          {contests.map((c, idx) => (
            <Grid size={{ xs: 12, md: 6 }} key={c.id}>
              <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: idx * 0.05 }}
              >
                <Card sx={{
                  height: '100%',
                  borderLeft: c.status === 1 ? '4px solid #4CAF50' : c.status === 2 ? '4px solid #FF9800' : '4px solid #9E9E9E',
                }}>
                  <CardContent sx={{ p: 3 }}>
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: 1 }}>
                      <Typography variant="h6" sx={{ fontWeight: 600 }}>{c.name}</Typography>
                      <Chip label={c.statusLabel} size="small" color={statusColor(c.status)} />
                    </Box>

                    {c.typeName && (
                      <Chip label={c.typeName} size="small" variant="outlined" sx={{ mb: 1 }} />
                    )}

                    {c.description && (
                      <Typography variant="body2" color="text.secondary" sx={{ mb: 2, whiteSpace: 'pre-line' }}>
                        {c.description.length > 200 ? c.description.slice(0, 200) + '...' : c.description}
                      </Typography>
                    )}

                    <Grid container spacing={1} sx={{ mb: 1 }}>
                      <Grid size={{ xs: 6 }}>
                        <Typography variant="caption" color="text.secondary">Начало</Typography>
                        <Typography variant="body2">{fmtDate(c.start)}</Typography>
                      </Grid>
                      <Grid size={{ xs: 6 }}>
                        <Typography variant="caption" color="text.secondary">Окончание</Typography>
                        <Typography variant="body2">{fmtDate(c.end)}</Typography>
                      </Grid>
                      {c.numberOfWinners && (
                        <Grid size={{ xs: 6 }}>
                          <Typography variant="caption" color="text.secondary">Победителей</Typography>
                          <Typography variant="body2">{c.numberOfWinners}</Typography>
                        </Grid>
                      )}
                      {c.resultsPublicationDate && (
                        <Grid size={{ xs: 6 }}>
                          <Typography variant="caption" color="text.secondary">Результаты</Typography>
                          <Typography variant="body2">{fmtDate(c.resultsPublicationDate)}</Typography>
                        </Grid>
                      )}
                    </Grid>

                    {c.presentation && (
                      <Button
                        size="small" variant="outlined" startIcon={<OpenInNew />}
                        href={c.presentation} target="_blank" rel="noopener"
                        sx={{ mt: 1 }}
                      >
                        Презентация
                      </Button>
                    )}
                  </CardContent>
                </Card>
              </motion.div>
            </Grid>
          ))}
        </Grid>
      )}
    </Box>
  );
};

export default Contests;
