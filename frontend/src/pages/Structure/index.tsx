import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, Chip, CircularProgress,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Paper, IconButton, TextField, InputAdornment, Grid,
  FormControl, InputLabel, Select, MenuItem, Button, Collapse,
} from '@mui/material';
import {
  AccountTree, Search, KeyboardArrowDown, KeyboardArrowRight,
  FilterList, ExpandMore, ExpandLess,
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import api from '../../api/client';
import { t } from '../../i18n';

interface StructureMember {
  id: number;
  personName: string;
  active: boolean;
  activityId: number;
  activityName: string;
  qualification: { level: number; title: string } | null;
  personalVolume: number;
  groupVolume: number;
  groupVolumeCumulative: number;
  clientCount: number;
  contractCount: number;
  hasChildren: boolean;
  birthDate: string | null;
  city: string | null;
  dateActivity: string | null;
}

interface FilterOption { id: number; name?: string; level?: number; title?: string; }

const fmt = (n: number) => n.toLocaleString('ru-RU', { minimumFractionDigits: 2 });

const activityColor = (id: number): 'success' | 'info' | 'warning' | 'error' | 'default' => {
  if (id === 1) return 'success';   // Активный
  if (id === 4) return 'info';      // Зарегистрирован
  if (id === 3) return 'warning';   // Терминирован
  if (id === 5) return 'error';     // Исключен
  return 'default';
};

const MemberRow: React.FC<{ member: StructureMember; depth: number }> = ({ member, depth }) => {
  const [open, setOpen] = useState(false);
  const [children, setChildren] = useState<StructureMember[]>([]);
  const [loading, setLoading] = useState(false);

  const toggleChildren = async () => {
    if (!open && children.length === 0 && member.hasChildren) {
      setLoading(true);
      try {
        const res = await api.get(`/structure/${member.id}/children`);
        setChildren(res.data.data);
      } catch {}
      setLoading(false);
    }
    setOpen(!open);
  };

  return (
    <>
      <TableRow hover sx={{ bgcolor: depth > 0 ? `rgba(0,0,0,${0.02 * depth})` : 'inherit' }}>
        <TableCell sx={{ pl: 2 + depth * 3 }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
            {member.hasChildren ? (
              <IconButton size="small" onClick={toggleChildren}>
                {loading ? <CircularProgress size={16} /> : open ? <KeyboardArrowDown /> : <KeyboardArrowRight />}
              </IconButton>
            ) : (
              <Box sx={{ width: 32 }} />
            )}
            <Typography variant="body2" sx={{ fontWeight: 600 }}>{member.personName}</Typography>
          </Box>
        </TableCell>
        <TableCell>
          {member.qualification
            ? <Chip label={`${member.qualification.level} [${member.qualification.title}]`} size="small" variant="outlined" />
            : '—'
          }
        </TableCell>
        <TableCell>
          <Chip label={member.activityName} size="small" color={activityColor(member.activityId)} />
        </TableCell>
        <TableCell>{member.dateActivity || '—'}</TableCell>
        <TableCell align="right">{fmt(member.personalVolume)}</TableCell>
        <TableCell align="right">{fmt(member.groupVolume)}</TableCell>
        <TableCell align="right">{fmt(member.groupVolumeCumulative)}</TableCell>
        <TableCell align="center">{member.clientCount}</TableCell>
      </TableRow>

      {open && children.map((child) => (
        <MemberRow key={child.id} member={child} depth={depth + 1} />
      ))}
    </>
  );
};

const TeamStructure: React.FC = () => {
  const [members, setMembers] = useState<StructureMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [filtersOpen, setFiltersOpen] = useState(false);

  // Filters
  const [search, setSearch] = useState('');
  const [activity, setActivity] = useState<string[]>([]);
  const [qualification, setQualification] = useState<string[]>([]);
  const [city, setCity] = useState('');
  const [lpMin, setLpMin] = useState('');
  const [lpMax, setLpMax] = useState('');
  const [gpMin, setGpMin] = useState('');
  const [gpMax, setGpMax] = useState('');
  const [ngpMin, setNgpMin] = useState('');
  const [ngpMax, setNgpMax] = useState('');

  // Filter options
  const [activityOptions, setActivityOptions] = useState<FilterOption[]>([]);
  const [qualificationOptions, setQualificationOptions] = useState<FilterOption[]>([]);

  useEffect(() => {
    api.get('/structure/activity-statuses').then((r) => setActivityOptions(r.data)).catch(() => {});
    api.get('/structure/qualification-levels').then((r) => setQualificationOptions(r.data)).catch(() => {});
  }, []);

  useEffect(() => {
    setLoading(true);
    const params: any = {};
    if (search) params.search = search;
    if (activity.length) params.activity = activity.join(',');
    if (qualification.length) params.qualification = qualification.join(',');
    if (city) params.city = city;
    if (lpMin) params.lp_min = lpMin;
    if (lpMax) params.lp_max = lpMax;
    if (gpMin) params.gp_min = gpMin;
    if (gpMax) params.gp_max = gpMax;
    if (ngpMin) params.ngp_min = ngpMin;
    if (ngpMax) params.ngp_max = ngpMax;

    api.get('/structure', { params })
      .then((res) => setMembers(res.data.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [search, activity, qualification, city, lpMin, lpMax, gpMin, gpMax, ngpMin, ngpMax]);

  const clearFilters = () => {
    setSearch(''); setActivity([]); setQualification([]); setCity('');
    setLpMin(''); setLpMax(''); setGpMin(''); setGpMax('');
    setNgpMin(''); setNgpMax('');
  };

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <AccountTree sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.teamStructure')}</Typography>
        <Chip label={`${members.length}`} color="primary" size="small" />
      </Box>

      {/* Filters */}
      <Card sx={{ mb: 2 }}>
        <CardContent sx={{ py: 2 }}>
          <Grid container spacing={2} sx={{ alignItems: 'center' }}>
            <Grid size={{ xs: 12, sm: 5 }}>
              <TextField
                fullWidth size="small" placeholder="Поиск по ФИО партнёра..." value={search}
                onChange={(e) => setSearch(e.target.value)}
                slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search /></InputAdornment> } }}
              />
            </Grid>
            <Grid size={{ xs: 6, sm: 3 }}>
              <FormControl fullWidth size="small">
                <InputLabel>Статус</InputLabel>
                <Select
                  multiple value={activity} label="Статус"
                  onChange={(e) => setActivity(typeof e.target.value === 'string' ? e.target.value.split(',') : e.target.value as string[])}
                  renderValue={(sel) => sel.map((id) => activityOptions.find((o) => String(o.id) === id)?.name).join(', ')}
                >
                  {activityOptions.map((o) => <MenuItem key={o.id} value={String(o.id)}>{o.name}</MenuItem>)}
                </Select>
              </FormControl>
            </Grid>
            <Grid size={{ xs: 6, sm: 2 }}>
              <FormControl fullWidth size="small">
                <InputLabel>Квалификация</InputLabel>
                <Select
                  multiple value={qualification} label="Квалификация"
                  onChange={(e) => setQualification(typeof e.target.value === 'string' ? e.target.value.split(',') : e.target.value as string[])}
                  renderValue={(sel) => sel.map((l) => qualificationOptions.find((o) => String(o.level) === l)?.title).join(', ')}
                >
                  {qualificationOptions.map((o) => <MenuItem key={o.id} value={String(o.level)}>{o.level}. {o.title}</MenuItem>)}
                </Select>
              </FormControl>
            </Grid>
            <Grid size={{ xs: 12, sm: 2 }}>
              <Button
                fullWidth variant="outlined" size="small"
                startIcon={filtersOpen ? <ExpandLess /> : <ExpandMore />}
                onClick={() => setFiltersOpen(!filtersOpen)}
              >
                <FilterList sx={{ mr: 0.5 }} /> Ещё
              </Button>
            </Grid>
          </Grid>

          <Collapse in={filtersOpen}>
            <Grid container spacing={2} sx={{ mt: 1 }}>
              <Grid size={{ xs: 12, sm: 3 }}>
                <TextField fullWidth size="small" label="Город" value={city}
                  onChange={(e) => setCity(e.target.value)} />
              </Grid>
              <Grid size={{ xs: 6, sm: 1.5 }}>
                <TextField fullWidth size="small" label="ЛП от" type="number" value={lpMin}
                  onChange={(e) => setLpMin(e.target.value)} />
              </Grid>
              <Grid size={{ xs: 6, sm: 1.5 }}>
                <TextField fullWidth size="small" label="ЛП до" type="number" value={lpMax}
                  onChange={(e) => setLpMax(e.target.value)} />
              </Grid>
              <Grid size={{ xs: 6, sm: 1.5 }}>
                <TextField fullWidth size="small" label="ГП от" type="number" value={gpMin}
                  onChange={(e) => setGpMin(e.target.value)} />
              </Grid>
              <Grid size={{ xs: 6, sm: 1.5 }}>
                <TextField fullWidth size="small" label="ГП до" type="number" value={gpMax}
                  onChange={(e) => setGpMax(e.target.value)} />
              </Grid>
              <Grid size={{ xs: 6, sm: 1.5 }}>
                <TextField fullWidth size="small" label="НГП от" type="number" value={ngpMin}
                  onChange={(e) => setNgpMin(e.target.value)} />
              </Grid>
              <Grid size={{ xs: 6, sm: 1.5 }}>
                <TextField fullWidth size="small" label="НГП до" type="number" value={ngpMax}
                  onChange={(e) => setNgpMax(e.target.value)} />
              </Grid>
            </Grid>
            <Box sx={{ mt: 1 }}>
              <Button size="small" onClick={clearFilters}>Сбросить фильтры</Button>
            </Box>
          </Collapse>
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
                  <TableCell>Партнёр</TableCell>
                  <TableCell>Квалификация</TableCell>
                  <TableCell>Статус</TableCell>
                  <TableCell>Дата смены</TableCell>
                  <TableCell align="right">ЛП</TableCell>
                  <TableCell align="right">ГП</TableCell>
                  <TableCell align="right">НГП</TableCell>
                  <TableCell align="center">Клиенты</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {members.map((m) => (
                  <MemberRow key={m.id} member={m} depth={0} />
                ))}
                {members.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={8} sx={{ textAlign: 'center', py: 4, color: 'text.secondary' }}>
                      Нет партнёров в структуре
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>
        </motion.div>
      )}
    </Box>
  );
};

export default TeamStructure;
