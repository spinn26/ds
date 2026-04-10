import React, { useState, useEffect } from 'react';
import { useNavigate, Link as RouterLink, useSearchParams } from 'react-router-dom';
import {
  Box, Card, CardContent, Typography, TextField, Button,
  Alert, InputAdornment, IconButton, CircularProgress, Grid,
  Stepper, Step, StepLabel, Checkbox, FormControlLabel, Divider,
} from '@mui/material';
import {
  Visibility, VisibilityOff, Email, Lock, Phone, Telegram,
  CalendarMonth, LocationCity, ArrowBack, CheckCircle,
} from '@mui/icons-material';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from '../../hooks/useAuth';
import { authApi, ReferralCheckResult } from '../../api/auth';
import AnimatedBackground from './AnimatedBackground';

const steps = ['Ввод данных', 'Проверка'];

const Register: React.FC = () => {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [step, setStep] = useState(0);
  const [form, setForm] = useState({
    firstName: '', lastName: '', patronymic: '',
    email: '', phone: '', telegram: '', birthDate: '', city: '',
    password: '', password_confirmation: '',
    consentPersonalData: false, consentTerms: false,
  });
  const [refCode] = useState(searchParams.get('ref') || '');
  const [mentor, setMentor] = useState<ReferralCheckResult['mentor'] | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [loading, setLoading] = useState(false);

  // Load mentor info from ref code
  useEffect(() => {
    if (refCode) {
      authApi.checkReferral(refCode).then((res) => {
        if (res.data.valid && res.data.mentor) {
          setMentor(res.data.mentor);
        }
      }).catch(() => {});
    }
  }, [refCode]);

  const update = (field: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm({ ...form, [field]: e.target.value });

  const updateCheck = (field: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm({ ...form, [field]: e.target.checked });

  const fieldError = (field: string) => errors[field]?.[0] || '';

  const activationDate = new Date();
  activationDate.setDate(activationDate.getDate() + 90);

  // Step 1 → Step 2: validate + check duplicates
  const handleNext = async () => {
    setError('');
    setErrors({});

    // Client-side validation
    const required = ['lastName', 'firstName', 'email', 'password', 'password_confirmation'];
    const missing = required.filter((f) => !(form as any)[f]);
    if (missing.length) {
      setError('Заполните все обязательные поля');
      return;
    }
    if (form.password !== form.password_confirmation) {
      setError('Пароли не совпадают');
      return;
    }
    if (form.password.length < 6) {
      setError('Пароль должен быть не менее 6 символов');
      return;
    }
    if (!form.consentPersonalData || !form.consentTerms) {
      setError('Необходимо дать согласие');
      return;
    }

    // Check duplicates
    setLoading(true);
    try {
      const res = await authApi.checkDuplicates({
        email: form.email,
        phone: form.phone || undefined,
        refCode: refCode || undefined,
      });
      if (res.data.duplicate) {
        setError(res.data.message || 'Обнаружен дубликат');
        setLoading(false);
        return;
      }
      setStep(1);
    } catch (err: any) {
      setError('Ошибка проверки данных');
    } finally {
      setLoading(false);
    }
  };

  // Step 2: submit registration
  const handleSubmit = async () => {
    setError('');
    setLoading(true);
    try {
      await register({
        ...form,
        refCode: refCode || undefined,
      });
      navigate('/');
    } catch (err: any) {
      if (err.response?.data?.errors) setErrors(err.response.data.errors);
      setError(err.response?.data?.message || 'Ошибка регистрации');
      setStep(0);
    } finally {
      setLoading(false);
    }
  };

  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', position: 'relative', overflow: 'hidden', p: 2 }}>
      <AnimatedBackground />

      <motion.div
        initial={{ opacity: 0, y: 30 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.6 }}
        style={{ zIndex: 1, width: '100%', maxWidth: 580 }}
      >
        <Card sx={{ boxShadow: '0 20px 60px rgba(0,0,0,0.15)', backdropFilter: 'blur(20px)', bgcolor: 'rgba(255,255,255,0.95)' }}>
          <CardContent sx={{ p: { xs: 3, sm: 4 } }}>
            {/* Header */}
            <Box sx={{ textAlign: 'center', mb: 3 }}>
              <Typography variant="h3" sx={{ color: 'primary.main', fontWeight: 900, mb: 0.5 }}>DS</Typography>
              <Typography variant="body2" sx={{ color: 'text.secondary', letterSpacing: 4, textTransform: 'uppercase' }}>
                Consulting Platform
              </Typography>
            </Box>

            <Typography variant="h5" sx={{ mb: 2, textAlign: 'center', fontWeight: 600 }}>Регистрация</Typography>

            {/* Stepper */}
            <Stepper activeStep={step} sx={{ mb: 3 }}>
              {steps.map((label) => (
                <Step key={label}><StepLabel>{label}</StepLabel></Step>
              ))}
            </Stepper>

            {error && (
              <motion.div initial={{ opacity: 0, y: -10 }} animate={{ opacity: 1, y: 0 }}>
                <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>
              </motion.div>
            )}

            {mentor && (
              <Alert severity="info" icon={false} sx={{ mb: 2 }}>
                Наставник: <strong>{mentor.name}</strong> (код: {mentor.code})
              </Alert>
            )}

            <AnimatePresence mode="wait">
              {/* ===== STEP 1: Form ===== */}
              {step === 0 && (
                <motion.div key="step1" initial={{ opacity: 0, x: -20 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: 20 }}>
                  <Grid container spacing={2}>
                    <Grid size={{ xs: 12, sm: 4 }}>
                      <TextField fullWidth label="Фамилия *" value={form.lastName} onChange={update('lastName')}
                        error={!!fieldError('lastName')} helperText={fieldError('lastName')} />
                    </Grid>
                    <Grid size={{ xs: 12, sm: 4 }}>
                      <TextField fullWidth label="Имя *" value={form.firstName} onChange={update('firstName')}
                        error={!!fieldError('firstName')} helperText={fieldError('firstName')} />
                    </Grid>
                    <Grid size={{ xs: 12, sm: 4 }}>
                      <TextField fullWidth label="Отчество" value={form.patronymic} onChange={update('patronymic')} />
                    </Grid>
                    <Grid size={12}>
                      <TextField fullWidth label="Email *" type="email" value={form.email} onChange={update('email')}
                        error={!!fieldError('email')} helperText={fieldError('email')}
                        slotProps={{ input: { startAdornment: <InputAdornment position="start"><Email color="action" /></InputAdornment> } }} />
                    </Grid>
                    <Grid size={{ xs: 12, sm: 6 }}>
                      <TextField fullWidth label="Телефон" value={form.phone} onChange={update('phone')}
                        slotProps={{ input: { startAdornment: <InputAdornment position="start"><Phone color="action" /></InputAdornment> } }} />
                    </Grid>
                    <Grid size={{ xs: 12, sm: 6 }}>
                      <TextField fullWidth label="Telegram" value={form.telegram} onChange={update('telegram')}
                        placeholder="@username"
                        slotProps={{ input: { startAdornment: <InputAdornment position="start"><Telegram color="action" /></InputAdornment> } }} />
                    </Grid>
                    <Grid size={{ xs: 12, sm: 6 }}>
                      <TextField fullWidth label="Дата рождения" type="date" value={form.birthDate}
                        onChange={update('birthDate')} slotProps={{ inputLabel: { shrink: true },
                        input: { startAdornment: <InputAdornment position="start"><CalendarMonth color="action" /></InputAdornment> } }} />
                    </Grid>
                    <Grid size={{ xs: 12, sm: 6 }}>
                      <TextField fullWidth label="Город" value={form.city} onChange={update('city')}
                        slotProps={{ input: { startAdornment: <InputAdornment position="start"><LocationCity color="action" /></InputAdornment> } }} />
                    </Grid>
                    <Grid size={12}>
                      <TextField fullWidth label="Пароль *" type={showPassword ? 'text' : 'password'}
                        value={form.password} onChange={update('password')}
                        error={!!fieldError('password')} helperText={fieldError('password')}
                        slotProps={{ input: {
                          startAdornment: <InputAdornment position="start"><Lock color="action" /></InputAdornment>,
                          endAdornment: <InputAdornment position="end">
                            <IconButton onClick={() => setShowPassword(!showPassword)} edge="end" size="small">
                              {showPassword ? <VisibilityOff /> : <Visibility />}
                            </IconButton>
                          </InputAdornment>,
                        } }} />
                    </Grid>
                    <Grid size={12}>
                      <TextField fullWidth label="Подтверждение пароля *" type="password"
                        value={form.password_confirmation} onChange={update('password_confirmation')}
                        slotProps={{ input: { startAdornment: <InputAdornment position="start"><Lock color="action" /></InputAdornment> } }} />
                    </Grid>
                    <Grid size={12}>
                      <FormControlLabel
                        control={<Checkbox checked={form.consentPersonalData} onChange={updateCheck('consentPersonalData')} color="primary" />}
                        label={<Typography variant="body2">Согласен на обработку персональных данных *</Typography>}
                      />
                      <FormControlLabel
                        control={<Checkbox checked={form.consentTerms} onChange={updateCheck('consentTerms')} color="primary" />}
                        label={<Typography variant="body2">Согласен с правилами использования платформы *</Typography>}
                      />
                    </Grid>
                  </Grid>

                  <motion.div whileHover={{ scale: 1.02 }} whileTap={{ scale: 0.98 }}>
                    <Button fullWidth variant="contained" size="large" onClick={handleNext} disabled={loading}
                      sx={{ py: 1.5, fontSize: 16, mt: 2, mb: 2, background: 'linear-gradient(135deg, #4CAF50 0%, #66BB6A 100%)', boxShadow: '0 4px 20px rgba(76,175,80,0.4)' }}>
                      {loading ? <CircularProgress size={24} color="inherit" /> : 'Далее →'}
                    </Button>
                  </motion.div>
                </motion.div>
              )}

              {/* ===== STEP 2: Review ===== */}
              {step === 1 && (
                <motion.div key="step2" initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -20 }}>
                  <Alert severity="warning" sx={{ mb: 2 }}>
                    <strong>Проверьте данные.</strong> Исправить ФИО после завершения регистрации можно только через техподдержку.
                  </Alert>

                  {/* User data review */}
                  <Box sx={{ bgcolor: '#f9f9f9', borderRadius: 2, p: 2.5, mb: 2 }}>
                    <Grid container spacing={1.5}>
                      {[
                        { label: 'Фамилия', value: form.lastName },
                        { label: 'Имя', value: form.firstName },
                        { label: 'Отчество', value: form.patronymic || '—' },
                        { label: 'Email', value: form.email },
                        { label: 'Телефон', value: form.phone || '—' },
                        { label: 'Telegram', value: form.telegram || '—' },
                        { label: 'Дата рождения', value: form.birthDate || '—' },
                        { label: 'Город', value: form.city || '—' },
                      ].map((item) => (
                        <Grid size={{ xs: 6 }} key={item.label}>
                          <Typography variant="caption" color="text.secondary">{item.label}</Typography>
                          <Typography variant="body2" sx={{ fontWeight: 600 }}>{item.value}</Typography>
                        </Grid>
                      ))}
                    </Grid>
                  </Box>

                  {/* Mentor info */}
                  {mentor && (
                    <Box sx={{ bgcolor: '#f0f9f0', borderRadius: 2, p: 2.5, mb: 2, border: '1px solid', borderColor: 'primary.light' }}>
                      <Typography variant="subtitle2" sx={{ mb: 1, color: 'primary.main' }}>Информация о наставнике</Typography>
                      <Typography variant="body2"><strong>Наставник:</strong> {mentor.name}</Typography>
                      <Typography variant="body2"><strong>ID:</strong> {mentor.code}</Typography>
                      <Typography variant="body2">
                        <strong>Стартовый период:</strong> {new Date().toLocaleDateString('ru')} – {activationDate.toLocaleDateString('ru')}
                      </Typography>
                    </Box>
                  )}

                  {/* Activation info */}
                  <Box sx={{ bgcolor: '#fff8e1', borderRadius: 2, p: 2.5, mb: 3, border: '1px solid #ffe082' }}>
                    <Typography variant="subtitle2" sx={{ mb: 1, color: '#f57c00' }}>Стартовый период</Typography>
                    <Typography variant="body2" sx={{ mb: 1 }}>
                      <strong>Стартовый период</strong> — время, в течение которого ваш аккаунт должен быть активирован.
                      Для этого вам нужно сделать Личный объём не менее <strong>500 баллов</strong> суммарно за 90 дней с даты регистрации.
                    </Typography>
                    <Typography variant="body2">
                      Если объём <strong>500 баллов</strong> не будет выполнен — Соглашение о партнёрстве автоматически
                      аннулируется после даты окончания Стартового периода.
                    </Typography>
                  </Box>

                  <Box sx={{ display: 'flex', gap: 2 }}>
                    <Button variant="outlined" size="large" onClick={() => setStep(0)} startIcon={<ArrowBack />}
                      sx={{ flex: 1, py: 1.5 }}>
                      Назад
                    </Button>
                    <motion.div style={{ flex: 2 }} whileHover={{ scale: 1.02 }} whileTap={{ scale: 0.98 }}>
                      <Button fullWidth variant="contained" size="large" onClick={handleSubmit} disabled={loading}
                        startIcon={loading ? undefined : <CheckCircle />}
                        sx={{ py: 1.5, fontSize: 16, background: 'linear-gradient(135deg, #4CAF50 0%, #66BB6A 100%)', boxShadow: '0 4px 20px rgba(76,175,80,0.4)' }}>
                        {loading ? <CircularProgress size={24} color="inherit" /> : 'Завершить регистрацию'}
                      </Button>
                    </motion.div>
                  </Box>
                </motion.div>
              )}
            </AnimatePresence>

            <Divider sx={{ my: 2 }} />
            <Typography variant="body2" sx={{ textAlign: 'center', color: 'text.secondary' }}>
              Уже есть аккаунт?{' '}
              <Typography component={RouterLink} to="/login" variant="body2"
                sx={{ color: 'primary.main', textDecoration: 'none', fontWeight: 600, '&:hover': { textDecoration: 'underline' } }}>
                Войти
              </Typography>
            </Typography>
          </CardContent>
        </Card>
      </motion.div>
    </Box>
  );
};

export default Register;
