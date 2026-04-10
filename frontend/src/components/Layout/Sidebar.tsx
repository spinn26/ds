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
  Help, Chat, Upload, SwapHoriz, Receipt,
  Paid, BarChart, Assessment, CheckCircle,
  CreditCard, History, CurrencyExchange, Settings,
  AdminPanelSettings, PersonSearch, EventNote,
} from '@mui/icons-material';
import { useAuth } from '../../hooks/useAuth';

const DRAWER_WIDTH = 260;

type MenuItem = {
  label: string;
  icon: React.ReactNode;
  path?: string;
  adminOnly?: boolean;
} | {
  group: string;
  adminOnly?: boolean;
};

const menuItems: MenuItem[] = [
  // === Partner sections ===
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

  // === Admin sections ===
  { group: 'Данные партнёров', adminOnly: true },
  { label: 'Менеджер контрактов', icon: <Description />, path: '/admin/contracts', adminOnly: true },
  { label: 'Загрузка контрактов', icon: <Upload />, path: '/admin/contracts/upload', adminOnly: true },
  { label: 'Партнёры', icon: <PersonSearch />, path: '/admin/partners', adminOnly: true },
  { label: 'Статусы партнёров', icon: <EventNote />, path: '/admin/partners/statuses', adminOnly: true },
  { label: 'Клиенты', icon: <People />, path: '/admin/clients', adminOnly: true },
  { label: 'Акцепт документов', icon: <CheckCircle />, path: '/admin/acceptance', adminOnly: true },
  { label: 'Реквизиты партнёров', icon: <CreditCard />, path: '/admin/requisites', adminOnly: true },
  { label: 'История перестановок', icon: <History />, path: '/admin/transfers', adminOnly: true },

  { group: 'Транзакции и объёмы', adminOnly: true },
  { label: 'Импорт транзакций', icon: <Upload />, path: '/admin/transactions/import', adminOnly: true },
  { label: 'Транзакции', icon: <SwapHoriz />, path: '/admin/transactions', adminOnly: true },
  { label: 'Комиссии', icon: <Receipt />, path: '/admin/commissions', adminOnly: true },
  { label: 'Пул', icon: <Paid />, path: '/admin/pool', adminOnly: true },
  { label: 'Квалификации', icon: <BarChart />, path: '/admin/qualifications', adminOnly: true },

  { group: 'Начисления и выплаты', adminOnly: true },
  { label: 'Прочие начисления', icon: <AccountBalance />, path: '/admin/charges', adminOnly: true },
  { label: 'Реестр выплат', icon: <Paid />, path: '/admin/payments', adminOnly: true },

  { group: 'Отчёты и настройки', adminOnly: true },
  { label: 'Отчёты', icon: <Assessment />, path: '/admin/reports', adminOnly: true },
  { label: 'Доступность отчётов', icon: <Settings />, path: '/admin/reports/availability', adminOnly: true },
  { label: 'Валюты и НДС', icon: <CurrencyExchange />, path: '/admin/currencies', adminOnly: true },
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
  const { user } = useAuth();

  const isAdmin = user?.role?.includes('admin') || user?.role?.includes('backoffice');

  const visibleItems = menuItems.filter((item) => {
    if ('adminOnly' in item && item.adminOnly) return isAdmin;
    return true;
  });

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
          {isAdmin && (
            <AdminPanelSettings sx={{ color: 'secondary.main', fontSize: 18, ml: 0.5 }} />
          )}
        </Box>
      </Toolbar>
      <Divider />
      <List sx={{ px: 1, overflow: 'auto' }}>
        {visibleItems.map((item, index) => {
          if ('group' in item) {
            return (
              <Typography
                key={`group-${index}`}
                variant="overline"
                sx={{
                  px: 2, pt: 2, pb: 0.5, display: 'block', fontSize: 11,
                  color: ('adminOnly' in item && item.adminOnly) ? 'secondary.main' : 'text.secondary',
                  fontWeight: ('adminOnly' in item && item.adminOnly) ? 700 : 400,
                }}
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
              key={item.path}
              onClick={() => {
                if (item.path) navigate(item.path);
                if (isMobile) onClose();
              }}
              selected={isActive}
              sx={{
                borderRadius: 2,
                mb: 0.3,
                '&.Mui-selected': {
                  bgcolor: item.adminOnly ? 'secondary.main' : 'primary.main',
                  color: '#fff',
                  '&:hover': { bgcolor: item.adminOnly ? 'secondary.dark' : 'primary.dark' },
                  '& .MuiListItemIcon-root': { color: '#fff' },
                },
              }}
            >
              <ListItemIcon sx={{ minWidth: 36, color: isActive ? '#fff' : 'text.secondary' }}>
                {item.icon}
              </ListItemIcon>
              <ListItemText
                primary={item.label}
                slotProps={{ primary: { sx: { fontSize: 13, fontWeight: isActive ? 600 : 400 } } }}
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
        sx={{ '& .MuiDrawer-paper': { width: DRAWER_WIDTH, boxSizing: 'border-box' } }}
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
