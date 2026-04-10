import React from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import {
  Drawer, List, ListItemButton, ListItemIcon, ListItemText,
  Toolbar, Typography, Box, Divider, useMediaQuery, useTheme,
} from '@mui/material';
import {
  Dashboard, Share, AccountBalance, Calculate,
  People, Description, FolderShared,
  AccountTree, Inventory, Security, EmojiEvents,
  Help, Chat,
} from '@mui/icons-material';

const DRAWER_WIDTH = 260;

type MenuItem = {
  label: string;
  icon: React.ReactNode;
  path?: string;
} | {
  group: string;
};

const menuItems: MenuItem[] = [
  { label: 'Дашборд', icon: <Dashboard />, path: '/' },
  { label: 'Рефералки', icon: <Share />, path: '/referrals' },
  { group: 'Финансы' },
  { label: 'Отчёт начислений', icon: <AccountBalance />, path: '/finance/report' },
  { label: 'Калькулятор объёмов', icon: <Calculate />, path: '/finance/calculator' },
  { group: 'Клиенты' },
  { label: 'Список клиентов', icon: <People />, path: '/clients' },
  { group: 'Контракты' },
  { label: 'Контракты клиентов', icon: <Description />, path: '/contracts' },
  { label: 'Контракты команды', icon: <FolderShared />, path: '/contracts/team' },
  { group: 'Структура' },
  { label: 'Структура', icon: <AccountTree />, path: '/structure' },
  { label: 'Продукты', icon: <Inventory />, path: '/products' },
  { label: 'Инсмарт', icon: <Security />, path: '/inssmart' },
  { group: 'Конкурсы' },
  { label: 'Список конкурсов', icon: <EmojiEvents />, path: '/contests' },
  { group: 'Помощь' },
  { label: 'Инструкции', icon: <Help />, path: '/help' },
  { label: 'Коммуникация', icon: <Chat />, path: '/communication' },
];

interface SidebarProps {
  mobileOpen: boolean;
  onClose: () => void;
}

const Sidebar: React.FC<SidebarProps> = ({ mobileOpen, onClose }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));

  const drawerContent = (
    <>
      <Toolbar sx={{ px: 2, py: 1.5 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <Typography variant="h6" sx={{ color: 'primary.main', fontWeight: 800 }}>
            DS
          </Typography>
          <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 500 }}>
            PLATFORM
          </Typography>
        </Box>
      </Toolbar>
      <Divider />
      <List sx={{ px: 1, overflow: 'auto' }}>
        {menuItems.map((item, index) => {
          if ('group' in item) {
            return (
              <Typography
                key={`group-${index}`}
                variant="overline"
                sx={{ px: 2, pt: 2, pb: 0.5, display: 'block', color: 'text.secondary', fontSize: 11 }}
              >
                {item.group}
              </Typography>
            );
          }

          const isActive = item.path === '/'
            ? location.pathname === '/'
            : location.pathname.startsWith(item.path || '');

          return (
            <ListItemButton
              key={item.label}
              onClick={() => {
                if (item.path) navigate(item.path);
                if (isMobile) onClose();
              }}
              selected={isActive}
              sx={{
                borderRadius: 2,
                mb: 0.3,
                '&.Mui-selected': {
                  bgcolor: 'primary.main',
                  color: '#fff',
                  '&:hover': { bgcolor: 'primary.dark' },
                  '& .MuiListItemIcon-root': { color: '#fff' },
                },
              }}
            >
              <ListItemIcon sx={{ minWidth: 36, color: isActive ? '#fff' : 'text.secondary' }}>
                {item.icon}
              </ListItemIcon>
              <ListItemText
                primary={item.label}
                slotProps={{ primary: { sx: { fontSize: 14, fontWeight: isActive ? 600 : 400 } } }}
              />
            </ListItemButton>
          );
        })}
      </List>
    </>
  );

  if (isMobile) {
    return (
      <Drawer
        variant="temporary"
        open={mobileOpen}
        onClose={onClose}
        ModalProps={{ keepMounted: true }}
        sx={{
          '& .MuiDrawer-paper': { width: DRAWER_WIDTH, boxSizing: 'border-box' },
        }}
      >
        {drawerContent}
      </Drawer>
    );
  }

  return (
    <Drawer
      variant="permanent"
      sx={{
        width: DRAWER_WIDTH,
        flexShrink: 0,
        '& .MuiDrawer-paper': {
          width: DRAWER_WIDTH,
          boxSizing: 'border-box',
          borderRight: '1px solid',
          borderColor: 'divider',
        },
      }}
    >
      {drawerContent}
    </Drawer>
  );
};

export { DRAWER_WIDTH };
export default Sidebar;
