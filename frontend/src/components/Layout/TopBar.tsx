import React from 'react';
import {
  AppBar, Toolbar, Typography, Box, Chip, IconButton, Avatar, Menu, MenuItem,
  useMediaQuery, useTheme,
} from '@mui/material';
import { Notifications, Menu as MenuIcon } from '@mui/icons-material';
import { useNavigate } from 'react-router-dom';
import { DRAWER_WIDTH } from './Sidebar';
import { useAuth } from '../../hooks/useAuth';
import { t } from '../../i18n';

interface TopBarProps {
  onMenuToggle: () => void;
}

const TopBar: React.FC<TopBarProps> = ({ onMenuToggle }) => {
  const [anchorEl, setAnchorEl] = React.useState<null | HTMLElement>(null);
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  const initials = user
    ? `${user.firstName?.[0] || ''}${user.lastName?.[0] || ''}`.toUpperCase()
    : '?';

  const isAdmin = user?.role?.includes('admin') || user?.role?.includes('backoffice');

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
          {isAdmin && (
            <Chip label={t('topbar.administrator')} color="secondary" size="small" />
          )}
          <Chip label={t('topbar.active')} color="success" size="small" variant="outlined" />
          {!isMobile && user && (
            <Typography variant="body2" sx={{ color: 'text.secondary' }}>
              {user.firstName} {user.lastName}
            </Typography>
          )}
          <IconButton size="small">
            <Notifications sx={{ color: 'text.secondary' }} />
          </IconButton>
          <IconButton size="small" onClick={(e) => setAnchorEl(e.currentTarget)}>
            <Avatar sx={{ width: 32, height: 32, bgcolor: isAdmin ? 'secondary.main' : 'primary.main', fontSize: 14 }}>
              {initials}
            </Avatar>
          </IconButton>
          <Menu anchorEl={anchorEl} open={Boolean(anchorEl)} onClose={() => setAnchorEl(null)}>
            <MenuItem onClick={() => { setAnchorEl(null); navigate('/profile'); }}>
              {t('nav.profile')}
            </MenuItem>
            {isAdmin && (
              <MenuItem onClick={() => { setAnchorEl(null); window.location.href = '/admin'; }}>
                {t('topbar.filamentAdmin')}
              </MenuItem>
            )}
            <MenuItem onClick={() => { setAnchorEl(null); logout(); }}>
              {t('auth.logout')}
            </MenuItem>
          </Menu>
        </Box>
      </Toolbar>
    </AppBar>
  );
};

export default TopBar;
