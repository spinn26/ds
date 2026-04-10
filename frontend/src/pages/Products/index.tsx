import React from 'react';
import { Box, Typography } from '@mui/material';

const Products: React.FC = () => {
  return (
    <Box>
      <Typography variant="h5" sx={{ mb: 3 }}>Перечень продуктов DS-Consulting</Typography>
      <Typography color="text.secondary">Раздел в разработке</Typography>
    </Box>
  );
};

export default Products;
