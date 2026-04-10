import React, { useState } from 'react';
import { useNavigate, Link as RouterLink } from 'react-router-dom';
import {
  Box, Card, CardContent, Typography, TextField, Button,
  Alert, InputAdornment, IconButton, CircularProgress, Grid,
} from '@mui/material';
import { Visibility, VisibilityOff, Email, Lock, Phone } from '@mui/icons-material';
import { useAuth } from '../../hooks/useAuth';

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
      if (err.response?.data?.errors) {
        setErrors(err.response.data.errors);
      }
      setError(err.response?.data?.message || 'Ошибка регистрации');
    } finally {
      setLoading(false);
    }
  };

  const fieldError = (field: string) => errors[field]?.[0] || '';

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        bgcolor: 'background.default',
        background: 'linear-gradient(135deg, #e8f5e9 0%, #f5f5f5 50%, #fff3e0 100%)',
        p: 2,
      }}
    >
      <Card sx={{ width: '100%', maxWidth: 520, boxShadow: '0 8px 40px rgba(0,0,0,0.12)' }}>
        <CardContent sx={{ p: { xs: 3, sm: 4 } }}>
          <Box sx={{ textAlign: 'center', mb: 3 }}>
            <Typography variant="h4" sx={{ color: 'primary.main', fontWeight: 800, mb: 0.5 }}>
              DS
            </Typography>
            <Typography variant="body2" sx={{ color: 'text.secondary', letterSpacing: 3, textTransform: 'uppercase' }}>
              Consulting Platform
            </Typography>
          </Box>

          <Typography variant="h5" sx={{ mb: 3, textAlign: 'center' }}>
            Регистрация
          </Typography>

          {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

          <form onSubmit={handleSubmit}>
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 4 }}>
                <TextField
                  fullWidth label="Фамилия" value={form.lastName}
                  onChange={update('lastName')} required
                  error={!!fieldError('lastName')} helperText={fieldError('lastName')}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 4 }}>
                <TextField
                  fullWidth label="Имя" value={form.firstName}
                  onChange={update('firstName')} required
                  error={!!fieldError('firstName')} helperText={fieldError('firstName')}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 4 }}>
                <TextField
                  fullWidth label="Отчество" value={form.patronymic}
                  onChange={update('patronymic')}
                />
              </Grid>
              <Grid size={12}>
                <TextField
                  fullWidth label="Email" type="email" value={form.email}
                  onChange={update('email')} required
                  error={!!fieldError('email')} helperText={fieldError('email')}
                  slotProps={{ input: { startAdornment: <InputAdornment position="start"><Email color="action" /></InputAdornment> } }}
                />
              </Grid>
              <Grid size={12}>
                <TextField
                  fullWidth label="Телефон" value={form.phone}
                  onChange={update('phone')}
                  slotProps={{ input: { startAdornment: <InputAdornment position="start"><Phone color="action" /></InputAdornment> } }}
                />
              </Grid>
              <Grid size={12}>
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
              </Grid>
              <Grid size={12}>
                <TextField
                  fullWidth label="Подтверждение пароля" type="password"
                  value={form.password_confirmation} onChange={update('password_confirmation')} required
                  slotProps={{ input: { startAdornment: <InputAdornment position="start"><Lock color="action" /></InputAdornment> } }}
                />
              </Grid>
            </Grid>

            <Button
              type="submit" fullWidth variant="contained" size="large"
              disabled={loading} sx={{ py: 1.5, fontSize: 16, mt: 3, mb: 2 }}
            >
              {loading ? <CircularProgress size={24} color="inherit" /> : 'Зарегистрироваться'}
            </Button>
          </form>

          <Typography variant="body2" sx={{ textAlign: 'center', color: 'text.secondary' }}>
            Уже есть аккаунт?{' '}
            <Typography
              component={RouterLink} to="/login" variant="body2"
              sx={{ color: 'primary.main', textDecoration: 'none', fontWeight: 600 }}
            >
              Войти
            </Typography>
          </Typography>
        </CardContent>
      </Card>
    </Box>
  );
};

export default Register;
