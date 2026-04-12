import React from 'react';
import { Box, Card, CardContent, Typography } from '@mui/material';
import { Visibility } from '@mui/icons-material';

const ReportAvailability: React.FC = () => (
  <Box>
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
      <Visibility color="primary" />
      <Typography variant="h5" sx={{ fontWeight: 600 }}>Доступность отчётов</Typography>
    </Box>
    <Card sx={{ maxWidth: 600 }}>
      <CardContent sx={{ textAlign: 'center', py: 6 }}>
        <Visibility sx={{ fontSize: 64, color: 'text.disabled', mb: 2 }} />
        <Typography variant="h6" color="text.secondary">
          Доступность отчётов — в разработке
        </Typography>
        <Typography variant="body2" color="text.disabled" sx={{ mt: 1 }}>
          Функционал будет доступен в ближайшем обновлении
        </Typography>
      </CardContent>
    </Card>
  </Box>
);

export default ReportAvailability;
