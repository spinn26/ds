import React from 'react';
import { Box, Card, CardContent, Typography } from '@mui/material';
import { FileUpload } from '@mui/icons-material';

const TransactionImport: React.FC = () => (
  <Box>
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
      <FileUpload color="primary" />
      <Typography variant="h5" sx={{ fontWeight: 600 }}>Импорт транзакций</Typography>
    </Box>
    <Card sx={{ maxWidth: 600 }}>
      <CardContent sx={{ textAlign: 'center', py: 6 }}>
        <FileUpload sx={{ fontSize: 64, color: 'text.disabled', mb: 2 }} />
        <Typography variant="h6" color="text.secondary">
          Импорт транзакций — в разработке
        </Typography>
        <Typography variant="body2" color="text.disabled" sx={{ mt: 1 }}>
          Функционал будет доступен в ближайшем обновлении
        </Typography>
      </CardContent>
    </Card>
  </Box>
);

export default TransactionImport;
