import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, Grid, TextField,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Paper, CircularProgress, Chip, LinearProgress, Slider,
} from '@mui/material';
import { Calculate } from '@mui/icons-material';
import { motion } from 'framer-motion';
import api from '../../api/client';
import { t } from '../../i18n';

interface Level {
  level: number; title: string; percent: number;
  groupVolumeCumulative: number; personalVolume: number;
  otrif: number; pool: number; dsShare: number;
}

interface CalcData {
  currentVolumes: { personalVolume: number; groupVolume: number; groupVolumeCumulative: number };
  currentLevel: { level: number; title: string; percent: number } | null;
  levels: Level[];
}

const fmt = (n: number) => n.toLocaleString('ru-RU', { minimumFractionDigits: 0 });

const VolumeCalculator: React.FC = () => {
  const [data, setData] = useState<CalcData | null>(null);
  const [loading, setLoading] = useState(true);
  const [addNGP, setAddNGP] = useState(0);

  useEffect(() => {
    api.get('/finance/calculator')
      .then((res) => setData(res.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Box sx={{ display: 'flex', justifyContent: 'center', py: 10 }}><CircularProgress /></Box>;
  if (!data) return null;

  const { currentVolumes, currentLevel, levels } = data;
  const projectedNGP = currentVolumes.groupVolumeCumulative + addNGP;

  // Find projected level
  let projectedLevel = levels[0];
  for (const lv of levels) {
    if (projectedNGP >= lv.groupVolumeCumulative) {
      projectedLevel = lv;
    }
  }

  const nextLevel = levels.find((l) => l.level === projectedLevel.level + 1);
  const progressToNext = nextLevel
    ? Math.min((projectedNGP / nextLevel.groupVolumeCumulative) * 100, 100)
    : 100;

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <Calculate sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.calculator')}</Typography>
      </Box>

      {/* Current volumes */}
      <Grid container spacing={2} sx={{ mb: 3 }}>
        {[
          { label: 'Текущий НГП', value: fmt(currentVolumes.groupVolumeCumulative) },
          { label: 'ГП (текущий период)', value: fmt(currentVolumes.groupVolume) },
          { label: 'ЛП (текущий период)', value: fmt(currentVolumes.personalVolume) },
          { label: 'Текущая квалификация', value: currentLevel ? `${currentLevel.level}. ${currentLevel.title} (${currentLevel.percent}%)` : '—' },
        ].map((item) => (
          <Grid size={{ xs: 6, md: 3 }} key={item.label}>
            <Card>
              <CardContent sx={{ textAlign: 'center', p: 2 }}>
                <Typography variant="caption" color="text.secondary">{item.label}</Typography>
                <Typography variant="h6" sx={{ fontWeight: 700 }}>{item.value}</Typography>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>

      {/* Projection slider */}
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <Card sx={{ mb: 3 }}>
          <CardContent sx={{ p: 3 }}>
            <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 2 }}>Прогноз квалификации</Typography>

            <Typography variant="body2" color="text.secondary" gutterBottom>
              Добавить к НГП: <strong>{fmt(addNGP)}</strong> баллов
            </Typography>
            <Slider
              value={addNGP}
              onChange={(_, v) => setAddNGP(v as number)}
              min={0}
              max={Math.max(500000, (nextLevel?.groupVolumeCumulative ?? 500000) * 1.5)}
              step={1000}
              sx={{ mb: 2 }}
            />

            <Grid container spacing={2}>
              <Grid size={{ xs: 12, md: 4 }}>
                <Typography variant="body2" color="text.secondary">Прогнозируемый НГП</Typography>
                <Typography variant="h5" sx={{ fontWeight: 700 }}>{fmt(projectedNGP)}</Typography>
              </Grid>
              <Grid size={{ xs: 12, md: 4 }}>
                <Typography variant="body2" color="text.secondary">Прогнозируемая квалификация</Typography>
                <Chip
                  label={`${projectedLevel.level}. ${projectedLevel.title} (${projectedLevel.percent}%)`}
                  color={projectedLevel.level > (currentLevel?.level ?? 0) ? 'success' : 'default'}
                />
              </Grid>
              <Grid size={{ xs: 12, md: 4 }}>
                {nextLevel && (
                  <Box>
                    <Typography variant="body2" color="text.secondary">
                      До {nextLevel.title}: {fmt(Math.max(0, nextLevel.groupVolumeCumulative - projectedNGP))} баллов
                    </Typography>
                    <LinearProgress
                      variant="determinate"
                      value={progressToNext}
                      sx={{ mt: 1, height: 8, borderRadius: 4 }}
                      color={progressToNext >= 100 ? 'success' : 'primary'}
                    />
                  </Box>
                )}
              </Grid>
            </Grid>
          </CardContent>
        </Card>
      </motion.div>

      {/* Qualification table */}
      <TableContainer component={Paper}>
        <Table size="small">
          <TableHead>
            <TableRow sx={{ bgcolor: '#f5f5f5' }}>
              <TableCell>Ур.</TableCell>
              <TableCell>Квалификация</TableCell>
              <TableCell align="right">%</TableCell>
              <TableCell align="right">НГП</TableCell>
              <TableCell align="right">ОП</TableCell>
              <TableCell align="right">Отрыв</TableCell>
              <TableCell align="right">Пул</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {levels.map((lv) => (
              <TableRow
                key={lv.level}
                sx={{
                  bgcolor: lv.level === projectedLevel.level ? 'rgba(76,175,80,0.1)' :
                           lv.level === (currentLevel?.level ?? 0) ? 'rgba(33,150,243,0.05)' : 'inherit',
                }}
              >
                <TableCell>{lv.level}</TableCell>
                <TableCell sx={{ fontWeight: lv.level === projectedLevel.level ? 700 : 400 }}>
                  {lv.title}
                  {lv.level === (currentLevel?.level ?? 0) && <Chip label="Текущий" size="small" color="primary" sx={{ ml: 1 }} />}
                  {lv.level === projectedLevel.level && lv.level !== (currentLevel?.level ?? 0) && <Chip label="Прогноз" size="small" color="success" sx={{ ml: 1 }} />}
                </TableCell>
                <TableCell align="right">{lv.percent}%</TableCell>
                <TableCell align="right">{fmt(lv.groupVolumeCumulative)}</TableCell>
                <TableCell align="right">{lv.personalVolume > 0 ? fmt(lv.personalVolume) : '—'}</TableCell>
                <TableCell align="right">{lv.otrif > 0 ? `${lv.otrif}%` : '—'}</TableCell>
                <TableCell align="right">{lv.pool > 0 ? `${lv.pool}%` : '—'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
    </Box>
  );
};

export default VolumeCalculator;
