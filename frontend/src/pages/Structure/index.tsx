import React from 'react';
import { Box, Typography } from '@mui/material';

const TeamStructure: React.FC = () => {
  return (
    <Box>
      <Typography variant="h5" sx={{ mb: 3 }}>Структура моей команды</Typography>
      <Typography color="text.secondary">Раздел в разработке</Typography>
    </Box>
  );
};

export default TeamStructure;
