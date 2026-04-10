import React from 'react';
import {
  AppBar, Toolbar, Typography, Box, Chip, IconButton, Avatar, Menu, MenuItem,
  useMediaQuery, useTheme,
} from '@mui/material';
import { Notifications, Menu as MenuIcon } from '@mui/icons-material';
import { DRAWER_WIDTH } from './Sidebar';

interface TopBarProps {
  onMenuToggle: () => void;
}

const TopBar: React.FC<TopBarProps> = ({ onMenuToggle }) => {
  const [anchorEl, setAnchorEl] = React.useState<null | HTMLElement>(null);
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));

  return (
    <AppBar
      position="fixed"
      elevation={0}
      sx={{
        width: isMobile ? '100%' : `calc(100% - ${DRAWER_WIDTH}px)`,
        ml: isMobile ? 0 : `${DRAWER_WIDTH}px`,
        bgcolor: '#fff',
        borderBottom: '1px solid',
        borderColor: 'divider',
      }}
    >
      <Toolbar sx={{ justifyContent: 'space-between' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          {isMobile && (
            <IconButton onClick={onMenuToggle} edge="start">
              <MenuIcon />
            </IconButton>
          )}
          {isMobile && (
            <Typography variant="h6" sx={{ color: 'primary.main', fontWeight: 800 }}>
              DS
            </Typography>
          )}
        </Box>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <Chip label="Активный" color="success" size="small" variant="outlined" />
          <IconButton size="small">
            <Notifications sx={{ color: 'text.secondary' }} />
          </IconButton>
          <IconButton size="small" onClick={(e) => setAnchorEl(e.currentTarget)}>
            <Avatar sx={{ width: 32, height: 32, bgcolor: 'primary.main', fontSize: 14 }}>
              ДК
            </Avatar>
          </IconButton>
          <Menu anchorEl={anchorEl} open={Boolean(anchorEl)} onClose={() => setAnchorEl(null)}>
            <MenuItem onClick={() => { setAnchorEl(null); window.location.href = '/partner/profile'; }}>
              Профиль
            </MenuItem>
            <MenuItem onClick={() => { localStorage.removeItem('auth_token'); window.location.href = '/login'; }}>
              Выйти
            </MenuItem>
          </Menu>
        </Box>
      </Toolbar>
    </AppBar>
  );
};

export default TopBar;
