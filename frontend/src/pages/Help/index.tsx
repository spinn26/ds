import React from 'react';
import { Box, Typography } from '@mui/material';

const Help: React.FC = () => {
  return (
    <Box>
      <Typography variant="h5" sx={{ mb: 3 }}>Инструкции</Typography>
      <Typography color="text.secondary">Раздел в разработке</Typography>
    </Box>
  );
};

export default Help;
