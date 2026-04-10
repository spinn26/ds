import React from 'react';
import {
  Box, Grid, Card, CardContent, Typography, LinearProgress, Chip,
} from '@mui/material';
import {
  TrendingUp, TrendingDown, People, AccountBalance,
} from '@mui/icons-material';

interface StatCardProps {
  title: string;
  value: string;
  change?: string;
  changeType?: 'up' | 'down';
  icon: React.ReactNode;
  color: string;
}

const StatCard: React.FC<StatCardProps> = ({ title, value, change, changeType, icon, color }) => (
  <Card>
    <CardContent sx={{ p: 3 }}>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <Box>
          <Typography variant="body2" color="text.secondary" gutterBottom>
            {title}
          </Typography>
          <Typography variant="h4" sx={{ fontWeight: 700, mb: 1 }}>
            {value}
          </Typography>
          {change && (
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
              {changeType === 'up' ? (
                <TrendingUp sx={{ fontSize: 16, color: 'success.main' }} />
              ) : (
                <TrendingDown sx={{ fontSize: 16, color: 'error.main' }} />
              )}
              <Typography
                variant="caption"
                sx={{ color: changeType === 'up' ? 'success.main' : 'error.main' }}
              >
                {change} к прошлому месяцу
              </Typography>
            </Box>
          )}
        </Box>
        <Box
          sx={{
            bgcolor: `${color}15`,
            borderRadius: 2,
            p: 1,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          {React.cloneElement(icon as React.ReactElement<any>, { sx: { color, fontSize: 28 } })}
        </Box>
      </Box>
    </CardContent>
  </Card>
);

const Dashboard: React.FC = () => {
  return (
    <Box>
      <Typography variant="h5" sx={{ mb: 3 }}>
        Дашборд партнера
      </Typography>

      {/* Qualification Info */}
      <Card sx={{ mb: 3 }}>
        <CardContent sx={{ p: 3 }}>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
            <Box>
              <Typography variant="body2" color="text.secondary">Статус</Typography>
              <Typography variant="h6">Финансовый консультант</Typography>
            </Box>
            <Chip label="Условия перехода" color="secondary" variant="outlined" clickable />
          </Box>

          <Grid container spacing={3}>
            <Grid size={{ xs: 12, md: 4 }}>
              <Typography variant="body2" color="text.secondary" gutterBottom>
                Закрытая квалификация
              </Typography>
              <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                <Chip label="10" size="small" color="secondary" />
                <Typography variant="body2" sx={{ fontWeight: 600 }}>Co-founder DS</Typography>
              </Box>
            </Grid>
            <Grid size={{ xs: 12, md: 4 }}>
              <Typography variant="body2" color="text.secondary" gutterBottom>
                Уровень расчёта комиссионных
              </Typography>
              <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                <Chip label="3" size="small" color="primary" />
                <Typography variant="body2" sx={{ fontWeight: 600 }}>Expert</Typography>
                <Typography variant="body2" color="text.secondary">Комиссия 25%</Typography>
              </Box>
            </Grid>
            <Grid size={{ xs: 12, md: 4 }}>
              <Typography variant="body2" color="text.secondary" gutterBottom>НГП</Typography>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                <LinearProgress
                  variant="determinate"
                  value={0}
                  sx={{ flexGrow: 1, height: 8, borderRadius: 4 }}
                />
                <Typography variant="body2">0 / 400 000</Typography>
              </Box>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Volume Indicators */}
      <Typography variant="h6" sx={{ mb: 2 }}>Показатели</Typography>
      <Grid container spacing={3} sx={{ mb: 3 }}>
        <Grid size={{ xs: 12, md: 4 }}>
          <StatCard title="Личные продажи" value="0.00" change="-100.00%" changeType="down" icon={<AccountBalance />} color="#4CAF50" />
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <StatCard title="Групповые продажи" value="0.00" change="-100.00%" changeType="down" icon={<People />} color="#2196F3" />
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <StatCard title="Накопленные групповые продажи" value="4 651 289.09" change="+0.00%" changeType="up" icon={<TrendingUp />} color="#FF9800" />
        </Grid>
      </Grid>

      {/* Team Stats */}
      <Grid container spacing={3}>
        {[
          { label: 'Резиденты 1 линии', value: '14', color: 'primary.main' },
          { label: 'Всего резидентов', value: '43', color: 'primary.main' },
          { label: 'Финконсультанты 1 линии', value: '13', color: 'secondary.main' },
          { label: 'Всего финконсультантов', value: '16', color: 'secondary.main' },
        ].map((stat) => (
          <Grid size={{ xs: 6, md: 3 }} key={stat.label}>
            <Card>
              <CardContent sx={{ textAlign: 'center', p: 3 }}>
                <Typography variant="body2" color="text.secondary" gutterBottom>{stat.label}</Typography>
                <Typography variant="h3" sx={{ fontWeight: 700, color: stat.color }}>{stat.value}</Typography>
              </CardContent>
            </Card>
          </Grid>
        ))}
        <Grid size={{ xs: 12, md: 6 }}>
          <Card>
            <CardContent sx={{ textAlign: 'center', p: 3 }}>
              <Typography variant="body2" color="text.secondary" gutterBottom>Клиенты команды</Typography>
              <Typography variant="h3" sx={{ fontWeight: 700 }}>320</Typography>
            </CardContent>
          </Card>
        </Grid>
        <Grid size={{ xs: 12, md: 6 }}>
          <Card>
            <CardContent sx={{ textAlign: 'center', p: 3 }}>
              <Typography variant="body2" color="text.secondary" gutterBottom>Капитал в управлении</Typography>
              <Typography variant="h3" sx={{ fontWeight: 700 }}>117 420 USD</Typography>
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </Box>
  );
};

export default Dashboard;
