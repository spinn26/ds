import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, Grid, TextField, Button,
  CircularProgress, Alert, Divider, Avatar, Chip,
  Select, MenuItem, InputLabel, FormControl,
} from '@mui/material';
import { Lock, Save } from '@mui/icons-material';
import { motion } from 'framer-motion';
import api from '../../api/client';
import { t } from '../../i18n';

interface ProfileData {
  user: {
    id: number; email: string; firstName: string; lastName: string;
    patronymic: string | null; phone: string | null; nicTG: string | null;
    gender: string | null; birthDate: string | null; role: string;
  };
  location: { taxResidency: string | null; city: string | null };
  consultant: {
    id: number; personName: string; participantCode: string | null;
    active: boolean; dateCreated: string | null; inviterName: string | null;
  } | null;
}

const Profile: React.FC = () => {
  const [data, setData] = useState<ProfileData | null>(null);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ phone: '', nicTG: '', gender: '', birthDate: '' });
  const [pwForm, setPwForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [msg, setMsg] = useState('');
  const [pwMsg, setPwMsg] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get('/profile').then((res) => {
      setData(res.data);
      const u = res.data.user;
      setForm({
        phone: u.phone || '',
        nicTG: u.nicTG || '',
        gender: u.gender || '',
        birthDate: u.birthDate ? u.birthDate.split('T')[0] : '',
      });
    }).finally(() => setLoading(false));
  }, []);

  const handleSave = async () => {
    setSaving(true); setMsg('');
    try {
      await api.put('/profile', form);
      setMsg('Профиль обновлён');
    } catch { setMsg('Ошибка сохранения'); }
    finally { setSaving(false); }
  };

  const handlePassword = async () => {
    setPwMsg('');
    try {
      await api.post('/profile/password', pwForm);
      setPwMsg('Пароль изменён');
      setPwForm({ current_password: '', password: '', password_confirmation: '' });
    } catch (err: any) {
      setPwMsg(err.response?.data?.message || 'Ошибка');
    }
  };

  if (loading) return <Box sx={{ display: 'flex', justifyContent: 'center', py: 10 }}><CircularProgress /></Box>;
  if (!data) return null;

  const { user, location, consultant } = data;
  const initials = `${user.firstName?.[0] || ''}${user.lastName?.[0] || ''}`.toUpperCase();

  return (
    <Box>
      <Typography variant="h5" sx={{ mb: 3, fontWeight: 600 }}>{t('nav.profile')}</Typography>

      {/* User card */}
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
        <Card sx={{ mb: 3 }}>
          <CardContent sx={{ p: 3 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 3, mb: 3 }}>
              <Avatar sx={{ width: 72, height: 72, bgcolor: 'primary.main', fontSize: 28 }}>{initials}</Avatar>
              <Box>
                <Typography variant="h6">{user.lastName} {user.firstName} {user.patronymic}</Typography>
                <Typography variant="body2" color="text.secondary">{user.email}</Typography>
                <Box sx={{ display: 'flex', gap: 1, mt: 0.5 }}>
                  {user.role?.split(',').map((r) => (
                    <Chip key={r} label={r.trim()} size="small" variant="outlined" />
                  ))}
                </Box>
              </Box>
            </Box>

            {consultant && (
              <Box sx={{ bgcolor: '#f9f9f9', borderRadius: 2, p: 2, mb: 3 }}>
                <Grid container spacing={2}>
                  <Grid size={{ xs: 6, sm: 3 }}>
                    <Typography variant="caption" color="text.secondary">ID консультанта</Typography>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>{consultant.id}</Typography>
                  </Grid>
                  <Grid size={{ xs: 6, sm: 3 }}>
                    <Typography variant="caption" color="text.secondary">Код участника</Typography>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>{consultant.participantCode || '—'}</Typography>
                  </Grid>
                  <Grid size={{ xs: 6, sm: 3 }}>
                    <Typography variant="caption" color="text.secondary">Наставник</Typography>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>{consultant.inviterName || '—'}</Typography>
                  </Grid>
                  <Grid size={{ xs: 6, sm: 3 }}>
                    <Typography variant="caption" color="text.secondary">Статус</Typography>
                    <Chip label={consultant.active ? 'Активен' : 'Неактивен'} size="small"
                      color={consultant.active ? 'success' : 'default'} />
                  </Grid>
                </Grid>
              </Box>
            )}

            <Divider sx={{ my: 2 }} />
            <Typography variant="subtitle1" sx={{ mb: 2, fontWeight: 600 }}>Персональные данные</Typography>

            {msg && <Alert severity={msg.includes('Ошибка') ? 'error' : 'success'} sx={{ mb: 2 }}>{msg}</Alert>}

            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField fullWidth label={t('auth.phone')} value={form.phone}
                  onChange={(e) => setForm({ ...form, phone: e.target.value })} />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField fullWidth label={t('auth.telegram')} value={form.nicTG} placeholder="@username"
                  onChange={(e) => setForm({ ...form, nicTG: e.target.value })} />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <FormControl fullWidth>
                  <InputLabel>Пол</InputLabel>
                  <Select value={form.gender} label="Пол"
                    onChange={(e) => setForm({ ...form, gender: e.target.value })}>
                    <MenuItem value="">—</MenuItem>
                    <MenuItem value="Мужской">Мужской</MenuItem>
                    <MenuItem value="female">Женский</MenuItem>
                  </Select>
                </FormControl>
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField fullWidth label={t('auth.birthDate')} type="date" value={form.birthDate}
                  onChange={(e) => setForm({ ...form, birthDate: e.target.value })}
                  slotProps={{ inputLabel: { shrink: true } }} />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField fullWidth label="Налоговое резидентство" value={location.taxResidency || '—'} disabled />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField fullWidth label={t('auth.city')} value={location.city || '—'} disabled />
              </Grid>
            </Grid>

            <Button variant="contained" startIcon={<Save />} onClick={handleSave} disabled={saving}
              sx={{ mt: 2, background: 'linear-gradient(135deg, #4CAF50 0%, #66BB6A 100%)' }}>
              {saving ? 'Сохранение...' : t('common.save')}
            </Button>
          </CardContent>
        </Card>
      </motion.div>

      {/* Password change */}
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }}>
        <Card>
          <CardContent sx={{ p: 3 }}>
            <Typography variant="subtitle1" sx={{ mb: 2, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 1 }}>
              <Lock fontSize="small" /> Изменение пароля
            </Typography>

            {pwMsg && <Alert severity={pwMsg.includes('Ошибка') || pwMsg.includes('неверен') ? 'error' : 'success'} sx={{ mb: 2 }}>{pwMsg}</Alert>}

            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 4 }}>
                <TextField fullWidth label="Текущий пароль" type="password" value={pwForm.current_password}
                  onChange={(e) => setPwForm({ ...pwForm, current_password: e.target.value })} />
              </Grid>
              <Grid size={{ xs: 12, sm: 4 }}>
                <TextField fullWidth label="Новый пароль" type="password" value={pwForm.password}
                  onChange={(e) => setPwForm({ ...pwForm, password: e.target.value })} />
              </Grid>
              <Grid size={{ xs: 12, sm: 4 }}>
                <TextField fullWidth label="Подтверждение" type="password" value={pwForm.password_confirmation}
                  onChange={(e) => setPwForm({ ...pwForm, password_confirmation: e.target.value })} />
              </Grid>
            </Grid>

            <Button variant="outlined" startIcon={<Lock />} onClick={handlePassword} sx={{ mt: 2 }}
              disabled={!pwForm.current_password || !pwForm.password}>
              Сменить пароль
            </Button>
          </CardContent>
        </Card>
      </motion.div>
    </Box>
  );
};

export default Profile;
