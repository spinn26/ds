import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, CircularProgress, Grid,
} from '@mui/material';
import { BarChart } from '@mui/icons-material';
import { adminApi } from '../../api/admin';

const cardColors = ['#4caf50', '#2196f3', '#ff9800', '#f44336', '#9c27b0', '#00bcd4', '#795548', '#607d8b'];

const PartnerStatuses: React.FC = () => {
  const [data, setData] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    adminApi.partnerStatuses()
      .then((res) => setData(res.data as any))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <BarChart color="primary" />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>Статусы партнёров</Typography>
      </Box>

      {loading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}><CircularProgress /></Box>
      ) : (
        <Grid container spacing={2}>
          {data.map((item: any, idx: number) => (
            <Grid size={{ xs: 12, sm: 6, md: 4, lg: 3 }} key={idx}>
              <Card sx={{ bgcolor: cardColors[idx % cardColors.length], color: '#fff' }}>
                <CardContent>
                  <Typography variant="h4" sx={{ fontWeight: 700 }}>{item.count ?? 0}</Typography>
                  <Typography variant="body1" sx={{ mt: 1 }}>{item.name ?? '—'}</Typography>
                </CardContent>
              </Card>
            </Grid>
          ))}
          {data.length === 0 && (
            <Grid size={12}>
              <Typography color="text.secondary" sx={{ textAlign: 'center', py: 4 }}>
                Данные не найдены
              </Typography>
            </Grid>
          )}
        </Grid>
      )}
    </Box>
  );
};

export default PartnerStatuses;
