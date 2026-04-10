import React, { useState } from 'react';
import { useNavigate, Link as RouterLink } from 'react-router-dom';
import {
  Box, Card, CardContent, Typography, TextField, Button,
  Alert, InputAdornment, IconButton, CircularProgress, Grid,
} from '@mui/material';
import { Visibility, VisibilityOff, Email, Lock, Phone } from '@mui/icons-material';
import { motion } from 'framer-motion';
import { useAuth } from '../../hooks/useAuth';
import AnimatedBackground from './AnimatedBackground';

const Register: React.FC = () => {
  const { register } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({
    firstName: '', lastName: '', patronymic: '',
    email: '', phone: '', password: '', password_confirmation: '',
  });
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [loading, setLoading] = useState(false);

  const update = (field: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm({ ...form, [field]: e.target.value });

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setErrors({});
    setLoading(true);
    try {
      await register(form);
      navigate('/');
    } catch (err: any) {
      if (err.response?.data?.errors) setErrors(err.response.data.errors);
      setError(err.response?.data?.message || 'Ошибка регистрации');
    } finally {
      setLoading(false);
    }
  };

  const fieldError = (field: string) => errors[field]?.[0] || '';

  const formFields = [
    { name: 'lastName', label: 'Фамилия', required: true, size: 4 },
    { name: 'firstName', label: 'Имя', required: true, size: 4 },
    { name: 'patronymic', label: 'Отчество', required: false, size: 4 },
  ];

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        position: 'relative',
        overflow: 'hidden',
        p: 2,
      }}
    >
      <AnimatedBackground />

      <motion.div
        initial={{ opacity: 0, y: 30, scale: 0.95 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        transition={{ duration: 0.6, ease: 'easeOut' }}
        style={{ zIndex: 1, width: '100%', maxWidth: 520 }}
      >
        <Card sx={{ boxShadow: '0 20px 60px rgba(0,0,0,0.15)', backdropFilter: 'blur(20px)', bgcolor: 'rgba(255,255,255,0.95)' }}>
          <CardContent sx={{ p: { xs: 3, sm: 4 } }}>
            <motion.div
              initial={{ opacity: 0, y: -10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.2 }}
            >
              <Box sx={{ textAlign: 'center', mb: 3 }}>
                <Typography variant="h3" sx={{ color: 'primary.main', fontWeight: 900, mb: 0.5 }}>
                  DS
                </Typography>
                <Typography variant="body2" sx={{ color: 'text.secondary', letterSpacing: 4, textTransform: 'uppercase', fontWeight: 500 }}>
                  Consulting Platform
                </Typography>
              </Box>
            </motion.div>

            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ delay: 0.3 }}
            >
              <Typography variant="h5" sx={{ mb: 3, textAlign: 'center', fontWeight: 600 }}>
                Регистрация
              </Typography>
            </motion.div>

            {error && (
              <motion.div initial={{ opacity: 0, x: -20 }} animate={{ opacity: 1, x: 0 }}>
                <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>
              </motion.div>
            )}

            <form onSubmit={handleSubmit}>
              <Grid container spacing={2}>
                {formFields.map((field, i) => (
                  <Grid size={{ xs: 12, sm: field.size }} key={field.name}>
                    <motion.div
                      initial={{ opacity: 0, x: -20 }}
                      animate={{ opacity: 1, x: 0 }}
                      transition={{ delay: 0.4 + i * 0.05 }}
                    >
                      <TextField
                        fullWidth label={field.label}
                        value={(form as any)[field.name]}
                        onChange={update(field.name)}
                        required={field.required}
                        error={!!fieldError(field.name)}
                        helperText={fieldError(field.name)}
                      />
                    </motion.div>
                  </Grid>
                ))}

                <Grid size={12}>
                  <motion.div initial={{ opacity: 0, x: -20 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.55 }}>
                    <TextField
                      fullWidth label="Email" type="email" value={form.email}
                      onChange={update('email')} required
                      error={!!fieldError('email')} helperText={fieldError('email')}
                      slotProps={{ input: { startAdornment: <InputAdornment position="start"><Email color="action" /></InputAdornment> } }}
                    />
                  </motion.div>
                </Grid>

                <Grid size={12}>
                  <motion.div initial={{ opacity: 0, x: -20 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.6 }}>
                    <TextField
                      fullWidth label="Телефон" value={form.phone}
                      onChange={update('phone')}
                      slotProps={{ input: { startAdornment: <InputAdornment position="start"><Phone color="action" /></InputAdornment> } }}
                    />
                  </motion.div>
                </Grid>

                <Grid size={12}>
                  <motion.div initial={{ opacity: 0, x: -20 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.65 }}>
                    <TextField
                      fullWidth label="Пароль" type={showPassword ? 'text' : 'password'}
                      value={form.password} onChange={update('password')} required
                      error={!!fieldError('password')} helperText={fieldError('password')}
                      slotProps={{
                        input: {
                          startAdornment: <InputAdornment position="start"><Lock color="action" /></InputAdornment>,
                          endAdornment: (
                            <InputAdornment position="end">
                              <IconButton onClick={() => setShowPassword(!showPassword)} edge="end" size="small">
                                {showPassword ? <VisibilityOff /> : <Visibility />}
                              </IconButton>
                            </InputAdornment>
                          ),
                        },
                      }}
                    />
                  </motion.div>
                </Grid>

                <Grid size={12}>
                  <motion.div initial={{ opacity: 0, x: -20 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.7 }}>
                    <TextField
                      fullWidth label="Подтверждение пароля" type="password"
                      value={form.password_confirmation} onChange={update('password_confirmation')} required
                      slotProps={{ input: { startAdornment: <InputAdornment position="start"><Lock color="action" /></InputAdornment> } }}
                    />
                  </motion.div>
                </Grid>
              </Grid>

              <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.75 }}
                whileHover={{ scale: 1.02 }}
                whileTap={{ scale: 0.98 }}
              >
                <Button
                  type="submit" fullWidth variant="contained" size="large"
                  disabled={loading}
                  sx={{
                    py: 1.5, fontSize: 16, mt: 3, mb: 2,
                    background: 'linear-gradient(135deg, #4CAF50 0%, #66BB6A 100%)',
                    boxShadow: '0 4px 20px rgba(76,175,80,0.4)',
                    '&:hover': { boxShadow: '0 6px 30px rgba(76,175,80,0.5)' },
                  }}
                >
                  {loading ? <CircularProgress size={24} color="inherit" /> : 'Зарегистрироваться'}
                </Button>
              </motion.div>
            </form>

            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.8 }}>
              <Typography variant="body2" sx={{ textAlign: 'center', color: 'text.secondary' }}>
                Уже есть аккаунт?{' '}
                <Typography
                  component={RouterLink} to="/login" variant="body2"
                  sx={{ color: 'primary.main', textDecoration: 'none', fontWeight: 600, '&:hover': { textDecoration: 'underline' } }}
                >
                  Войти
                </Typography>
              </Typography>
            </motion.div>
          </CardContent>
        </Card>
      </motion.div>
    </Box>
  );
};

export default Register;
