import React, { useState, useEffect } from 'react';
import {
  Box, Grid, Card, CardContent, Typography, LinearProgress, Chip, Button,
  CircularProgress, Dialog, DialogTitle, DialogContent, DialogActions,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Paper,
  TextField, Alert,
} from '@mui/material';
import {
  TrendingUp, TrendingDown, People, AccountBalance, Remove,
  Groups, PersonAdd, PersonOff, Block, Timer,
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { useNavigate } from 'react-router-dom';
import { dashboardApi, DashboardData, StatusLevel } from '../../api/dashboard';
import { t } from '../../i18n';

const fmt = (n: number) => n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtInt = (n: number) => n.toLocaleString('ru-RU');

const changePercent = (current: number, prev: number): { value: string; type: 'up' | 'down' | 'neutral' } => {
  if (prev === 0 && current === 0) return { value: '0%', type: 'neutral' };
  if (prev === 0) return { value: '+100%', type: 'up' };
  const pct = ((current - prev) / prev) * 100;
  return {
    value: `${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%`,
    type: pct >= 0 ? 'up' : 'down',
  };
};

const changeDiff = (current: number, prev: number): { value: string; type: 'up' | 'down' | 'neutral' } => {
  const diff = current - prev;
  if (diff === 0) return { value: '0', type: 'neutral' };
  return { value: `${diff > 0 ? '+' : ''}${diff}`, type: diff > 0 ? 'up' : 'down' };
};

interface StatCardProps {
  title: string;
  value: string;
  change?: { value: string; type: 'up' | 'down' | 'neutral' };
  icon: React.ReactNode;
  color: string;
  onClick?: () => void;
}

const StatCard: React.FC<StatCardProps> = ({ title, value, change, icon, color, onClick }) => (
  <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
    <Card
      sx={{ cursor: onClick ? 'pointer' : 'default', '&:hover': onClick ? { boxShadow: 6 } : {} }}
      onClick={onClick}
    >
      <CardContent sx={{ p: 3 }}>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <Box>
            <Typography variant="body2" color="text.secondary" gutterBottom>{title}</Typography>
            <Typography variant="h4" sx={{ fontWeight: 700, mb: 1 }}>{value}</Typography>
            {change && (
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
                {change.type === 'up' ? <TrendingUp sx={{ fontSize: 16, color: 'success.main' }} /> :
                 change.type === 'down' ? <TrendingDown sx={{ fontSize: 16, color: 'error.main' }} /> :
                 <Remove sx={{ fontSize: 16, color: 'text.secondary' }} />}
                <Typography variant="caption" sx={{ color: change.type === 'up' ? 'success.main' : change.type === 'down' ? 'error.main' : 'text.secondary' }}>
                  {change.value} {t('dashboard.toLastMonth')}
                </Typography>
              </Box>
            )}
          </Box>
          <Box sx={{ bgcolor: `${color}15`, borderRadius: 2, p: 1, display: 'flex' }}>
            {React.cloneElement(icon as React.ReactElement<any>, { sx: { color, fontSize: 28 } })}
          </Box>
        </Box>
      </CardContent>
    </Card>
  </motion.div>
);

const Dashboard: React.FC = () => {
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [levelsOpen, setLevelsOpen] = useState(false);
  const [levels, setLevels] = useState<StatusLevel[]>([]);
  const navigate = useNavigate();

  const emptyData: DashboardData = {
    consultant: { id: 0, personName: '—', participantCode: null, active: false, statusName: 'Резидент', ambassadorProducts: null },
    statusInfo: { activityId: 4, activityName: 'Зарегистрирован', hasAccess: true, canInvite: false, terminationCount: 0, maxTerminations: 3 },
    qualification: { nominalLevel: null, nextLevel: null },
    volumes: { personalVolume: 0, groupVolume: 0, groupVolumeCumulative: 0, prevPersonalVolume: 0, prevGroupVolume: 0, prevGroupVolumeCumulative: 0 },
    team: { myClients: 0, teamClients: 0, firstLineResidents: 0, totalResidents: 0, firstLineConsultants: 0, totalConsultants: 0, capitalUsd: 0 },
    partners: { total: 0, registered: 0, active: 0, inactive: 0, terminated: 0, excluded: 0 },
    prevPartners: { total: 0, registered: 0, active: 0, inactive: 0, terminated: 0, excluded: 0 },
    period: period,
  };

  useEffect(() => {
    setLoading(true);
    dashboardApi.get(period)
      .then((res) => setData(res.data))
      .catch(() => setData(emptyData))
      .finally(() => setLoading(false));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [period]);

  const openLevels = async () => {
    if (levels.length === 0) {
      const res = await dashboardApi.getStatusLevels();
      setLevels(res.data);
    }
    setLevelsOpen(true);
  };

  if (loading) {
    return <Box sx={{ display: 'flex', justifyContent: 'center', py: 10 }}><CircularProgress /></Box>;
  }

  if (!data) {
    return <Typography color="error">{t('common.loading')}...</Typography>;
  }

  const { consultant, statusInfo, qualification, volumes, team, partners, prevPartners } = data;
  const level = qualification.nominalLevel;
  const nextLvl = qualification.nextLevel;

  const nqpProgress = nextLvl && nextLvl.groupVolumeCumulative > 0
    ? Math.min((volumes.groupVolumeCumulative / nextLvl.groupVolumeCumulative) * 100, 100)
    : 0;

  // Activation progress for status bar
  const activationProgress = statusInfo.requiredPoints && statusInfo.requiredPoints > 0
    ? Math.min(((statusInfo.currentPoints ?? 0) / statusInfo.requiredPoints) * 100, 100)
    : 0;

  return (
    <Box>
      {/* Period selector */}
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 3, flexWrap: 'wrap', gap: 2 }}>
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('dashboard.title')}</Typography>
        <TextField
          type="month"
          value={period}
          onChange={(e) => setPeriod(e.target.value)}
          size="small"
          sx={{ width: 200 }}
        />
      </Box>

      {/* Status alert with countdown */}
      {statusInfo.daysRemaining !== undefined && statusInfo.daysRemaining <= 90 && (
        <Alert
          severity={statusInfo.daysRemaining <= 30 ? 'warning' : 'info'}
          icon={<Timer />}
          sx={{ mb: 3 }}
        >
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap' }}>
            <Box>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                {statusInfo.activityName} — {statusInfo.daysRemaining} {t('dashboard.daysRemaining')}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {t('dashboard.requiredPoints')}: {statusInfo.requiredPoints}
                {' | '}
                Набрано: {fmtInt(statusInfo.currentPoints ?? 0)}
              </Typography>
            </Box>
            <LinearProgress
              variant="determinate"
              value={activationProgress}
              sx={{ flexGrow: 1, minWidth: 120, height: 8, borderRadius: 4 }}
              color={statusInfo.daysRemaining <= 30 ? 'warning' : 'primary'}
            />
          </Box>
        </Alert>
      )}

      {/* Qualification header */}
      <Card sx={{ mb: 3 }}>
        <CardContent sx={{ p: 3 }}>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2, flexWrap: 'wrap', gap: 1 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
              <Box>
                <Typography variant="body2" color="text.secondary">{t('dashboard.status')}</Typography>
                <Typography variant="h6">{consultant.statusName}</Typography>
              </Box>
              <Chip
                label={statusInfo.activityName}
                size="small"
                color={
                  statusInfo.activityId === 1 ? 'success' :
                  statusInfo.activityId === 4 ? 'info' :
                  statusInfo.activityId === 3 ? 'warning' :
                  statusInfo.activityId === 5 ? 'error' : 'default'
                }
              />
            </Box>
            <Button variant="outlined" color="secondary" onClick={openLevels}>
              {t('dashboard.transitionConditions')}
            </Button>
          </Box>

          <Grid container spacing={3}>
            <Grid size={{ xs: 12, md: 4 }}>
              <Typography variant="body2" color="text.secondary" gutterBottom>
                {t('dashboard.qualificationClosed')}
              </Typography>
              <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                <Chip label={level?.level ?? '—'} size="small" color="secondary" />
                <Typography variant="body2" sx={{ fontWeight: 600 }}>{level?.title ?? '—'}</Typography>
              </Box>
            </Grid>
            <Grid size={{ xs: 12, md: 4 }}>
              <Typography variant="body2" color="text.secondary" gutterBottom>
                {t('dashboard.commissionLevel')}
              </Typography>
              <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                <Chip label={`${level?.percent ?? 0}%`} size="small" color="primary" />
                <Typography variant="body2" sx={{ fontWeight: 600 }}>{t('dashboard.commission')} {level?.percent ?? 0}%</Typography>
              </Box>
            </Grid>
            <Grid size={{ xs: 12, md: 4 }}>
              <Typography variant="body2" color="text.secondary" gutterBottom>
                {t('dashboard.cumulativeGroupVolume')}
              </Typography>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                <LinearProgress
                  variant="determinate"
                  value={nqpProgress}
                  sx={{ flexGrow: 1, height: 8, borderRadius: 4 }}
                />
                <Typography variant="body2">
                  {fmtInt(volumes.groupVolumeCumulative)} / {fmtInt(nextLvl?.groupVolumeCumulative ?? 0)}
                </Typography>
              </Box>
            </Grid>
          </Grid>

          {consultant.ambassadorProducts && (
            <Box sx={{ mt: 2 }}>
              <Typography variant="body2" color="text.secondary">Амбассадор продуктов</Typography>
              <Box sx={{ display: 'flex', gap: 0.5, mt: 0.5, flexWrap: 'wrap' }}>
                {consultant.ambassadorProducts.split(',').map((p) => (
                  <Chip key={p} label={p.trim()} size="small" variant="outlined" />
                ))}
              </Box>
            </Box>
          )}
        </CardContent>
      </Card>

      {/* Volume indicators */}
      <Typography variant="h6" sx={{ mb: 2 }}>{t('dashboard.indicators')}</Typography>
      <Grid container spacing={3} sx={{ mb: 3 }}>
        <Grid size={{ xs: 12, md: 4 }}>
          <StatCard
            title={t('dashboard.personalSales')}
            value={fmt(volumes.personalVolume)}
            change={changePercent(volumes.personalVolume, volumes.prevPersonalVolume)}
            icon={<AccountBalance />}
            color="#4CAF50"
          />
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <StatCard
            title={t('dashboard.groupSales')}
            value={fmt(volumes.groupVolume)}
            change={changePercent(volumes.groupVolume, volumes.prevGroupVolume)}
            icon={<People />}
            color="#2196F3"
          />
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <StatCard
            title={t('dashboard.cumulativeGroupSales')}
            value={fmt(volumes.groupVolumeCumulative)}
            change={changePercent(volumes.groupVolumeCumulative, volumes.prevGroupVolumeCumulative)}
            icon={<TrendingUp />}
            color="#FF9800"
          />
        </Grid>
      </Grid>

      {/* Partner counts by status */}
      <Typography variant="h6" sx={{ mb: 2 }}>{t('dashboard.partners')}</Typography>
      <Grid container spacing={3} sx={{ mb: 3 }}>
        <Grid size={{ xs: 6, md: 3 }}>
          <StatCard
            title={t('dashboard.partnersTotal')}
            value={fmtInt(partners.total)}
            change={changeDiff(partners.total, prevPartners.total)}
            icon={<Groups />}
            color="#673AB7"
            onClick={() => navigate('/structure')}
          />
        </Grid>
        <Grid size={{ xs: 6, md: 3 }}>
          <StatCard
            title={t('dashboard.partnersRegistered')}
            value={fmtInt(partners.registered)}
            change={changeDiff(partners.registered, prevPartners.registered)}
            icon={<PersonAdd />}
            color="#2196F3"
            onClick={() => navigate('/structure')}
          />
        </Grid>
        <Grid size={{ xs: 6, md: 3 }}>
          <StatCard
            title={t('dashboard.partnersActive')}
            value={fmtInt(partners.active)}
            change={changeDiff(partners.active, prevPartners.active)}
            icon={<People />}
            color="#4CAF50"
            onClick={() => navigate('/structure')}
          />
        </Grid>
        <Grid size={{ xs: 6, md: 3 }}>
          <StatCard
            title={t('dashboard.partnersTerminated')}
            value={fmtInt(partners.terminated)}
            change={changeDiff(partners.terminated, prevPartners.terminated)}
            icon={<PersonOff />}
            color="#FF9800"
            onClick={() => navigate('/structure')}
          />
        </Grid>
      </Grid>

      {/* Team stats */}
      <Grid container spacing={3}>
        <Grid size={{ xs: 6, md: 3 }}>
          <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.3 }}>
            <Card sx={{ cursor: 'pointer', '&:hover': { boxShadow: 6 } }} onClick={() => navigate('/clients')}>
              <CardContent sx={{ textAlign: 'center', p: 3 }}>
                <Typography variant="body2" color="text.secondary" gutterBottom>{t('nav.clientList')}</Typography>
                <Typography variant="h3" sx={{ fontWeight: 700, color: 'primary.main' }}>{fmtInt(team.myClients)}</Typography>
              </CardContent>
            </Card>
          </motion.div>
        </Grid>
        <Grid size={{ xs: 6, md: 3 }}>
          <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.35 }}>
            <Card sx={{ cursor: 'pointer', '&:hover': { boxShadow: 6 } }} onClick={() => navigate('/clients')}>
              <CardContent sx={{ textAlign: 'center', p: 3 }}>
                <Typography variant="body2" color="text.secondary" gutterBottom>{t('dashboard.teamClients')}</Typography>
                <Typography variant="h3" sx={{ fontWeight: 700, color: 'primary.main' }}>{fmtInt(team.teamClients)}</Typography>
              </CardContent>
            </Card>
          </motion.div>
        </Grid>
        <Grid size={{ xs: 12, md: 6 }}>
          <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.4 }}>
            <Card>
              <CardContent sx={{ textAlign: 'center', p: 3 }}>
                <Typography variant="body2" color="text.secondary" gutterBottom>{t('dashboard.capitalUnderManagement')}</Typography>
                <Typography variant="h3" sx={{ fontWeight: 700 }}>{fmtInt(team.capitalUsd)} USD</Typography>
              </CardContent>
            </Card>
          </motion.div>
        </Grid>
      </Grid>

      {/* Status levels dialog */}
      <Dialog open={levelsOpen} onClose={() => setLevelsOpen(false)} maxWidth="lg" fullWidth>
        <DialogTitle>{t('dashboard.transitionConditions')}</DialogTitle>
        <DialogContent>
          <TableContainer component={Paper} variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#f5f5f5' }}>
                  <TableCell>{t('dashboard.qualLevel')}</TableCell>
                  <TableCell>{t('dashboard.qualTitle')}</TableCell>
                  <TableCell align="right">{t('dashboard.qualPercent')}</TableCell>
                  <TableCell align="right">{t('dashboard.qualNGP')}</TableCell>
                  <TableCell align="right">{t('dashboard.qualOP')}</TableCell>
                  <TableCell align="right">{t('dashboard.qualGapGP')}</TableCell>
                  <TableCell align="right">{t('dashboard.qualPool')}</TableCell>
                  <TableCell align="right">{t('dashboard.qualDsShare')}</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {levels.map((lv) => (
                  <TableRow
                    key={lv.id}
                    sx={{
                      bgcolor: level && lv.level === level.level ? 'rgba(76,175,80,0.1)' : 'inherit',
                    }}
                  >
                    <TableCell>{lv.level}</TableCell>
                    <TableCell sx={{ fontWeight: level && lv.level === level.level ? 700 : 400 }}>
                      {lv.title}
                      {level && lv.level === level.level && (
                        <Chip label="Текущий" size="small" color="success" sx={{ ml: 1 }} />
                      )}
                    </TableCell>
                    <TableCell align="right">{lv.percent}%</TableCell>
                    <TableCell align="right">{fmtInt(lv.groupVolumeCumulative)}</TableCell>
                    <TableCell align="right">{lv.personalVolume > 0 ? fmtInt(lv.personalVolume) : '—'}</TableCell>
                    <TableCell align="right">{lv.otrif > 0 ? `${lv.otrif}%` : '—'}</TableCell>
                    <TableCell align="right">{lv.pool > 0 ? `${lv.pool}%` : '—'}</TableCell>
                    <TableCell align="right">{lv.dsShare > 0 ? `${lv.dsShare}%` : '—'}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setLevelsOpen(false)}>{t('common.close')}</Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default Dashboard;
