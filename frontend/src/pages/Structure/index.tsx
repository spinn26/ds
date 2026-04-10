import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, Chip, CircularProgress,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Paper, IconButton, TextField, InputAdornment,
  ToggleButton, ToggleButtonGroup,
} from '@mui/material';
import { AccountTree, Search, KeyboardArrowDown, KeyboardArrowRight } from '@mui/icons-material';
import { motion } from 'framer-motion';
import api from '../../api/client';
import { t } from '../../i18n';

interface StructureMember {
  id: number;
  personName: string;
  active: boolean;
  qualification: { level: number; title: string } | null;
  activityName: string;
  personalVolume: number;
  groupVolume: number;
  groupVolumeCumulative: number;
  clientCount: number;
  contractCount: number;
  hasChildren: boolean;
}

const fmt = (n: number) => n.toLocaleString('ru-RU', { minimumFractionDigits: 2 });

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
          <Chip label={member.activityName} size="small"
            color={member.active ? 'success' : 'default'} />
        </TableCell>
        <TableCell align="right">{fmt(member.personalVolume)}</TableCell>
        <TableCell align="right">{fmt(member.groupVolume)}</TableCell>
        <TableCell align="right">{fmt(member.groupVolumeCumulative)}</TableCell>
        <TableCell align="center">{member.clientCount}</TableCell>
        <TableCell align="center">{member.contractCount}</TableCell>
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
  const [search, setSearch] = useState('');
  const [activeFilter, setActiveFilter] = useState('all');

  useEffect(() => {
    setLoading(true);
    const params: any = {};
    if (search) params.search = search;
    if (activeFilter !== 'all') params.active = activeFilter;

    api.get('/structure', { params })
      .then((res) => setMembers(res.data.data))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [search, activeFilter]);

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <AccountTree sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.teamStructure')}</Typography>
        <Chip label={`${members.length}`} color="primary" size="small" />
      </Box>

      <Card sx={{ mb: 2 }}>
        <CardContent sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center', py: 2 }}>
          <TextField
            size="small" placeholder="Поиск по ФИО..." value={search}
            onChange={(e) => setSearch(e.target.value)}
            sx={{ minWidth: 250 }}
            slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search /></InputAdornment> } }}
          />
          <ToggleButtonGroup value={activeFilter} exclusive size="small"
            onChange={(_, val) => val && setActiveFilter(val)}>
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
                  <TableCell>Партнёр</TableCell>
                  <TableCell>Квалификация</TableCell>
                  <TableCell>Статус</TableCell>
                  <TableCell align="right">ЛП</TableCell>
                  <TableCell align="right">ГП</TableCell>
                  <TableCell align="right">НГП</TableCell>
                  <TableCell align="center">Клиенты</TableCell>
                  <TableCell align="center">Контракты</TableCell>
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
