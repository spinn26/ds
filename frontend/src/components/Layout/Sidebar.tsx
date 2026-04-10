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
  AdminPanelSettings, PersonSearch, EventNote, School,
} from '@mui/icons-material';
import { useAuth } from '../../hooks/useAuth';
import { t } from '../../i18n';

const DRAWER_WIDTH = 260;

type MenuItem = {
  label: string;
  icon: React.ReactNode;
  path?: string;
  adminOnly?: boolean;
  requireRole?: string; // 'consultant' = not for 'registered'
} | {
  group: string;
  adminOnly?: boolean;
  requireRole?: string;
};

const getMenuItems = (): MenuItem[] => [
  { label: t('nav.education'), icon: <School />, path: '/education' },

  { label: t('nav.dashboard'), icon: <Dashboard />, path: '/', requireRole: 'consultant' },
  { label: t('nav.referrals'), icon: <Share />, path: '/referrals', requireRole: 'consultant' },
  { group: t('nav.finance'), requireRole: 'consultant' },
  { label: t('nav.report'), icon: <AccountBalance />, path: '/finance/report', requireRole: 'consultant' },
  { label: t('nav.calculator'), icon: <Calculate />, path: '/finance/calculator', requireRole: 'consultant' },
  { group: t('nav.clients'), requireRole: 'consultant' },
  { label: t('nav.clientList'), icon: <People />, path: '/clients', requireRole: 'consultant' },
  { group: t('nav.contracts'), requireRole: 'consultant' },
  { label: t('nav.myContracts'), icon: <Description />, path: '/contracts', requireRole: 'consultant' },
  { label: t('nav.teamContracts'), icon: <FolderShared />, path: '/contracts/team', requireRole: 'consultant' },
  { group: t('nav.structure'), requireRole: 'consultant' },
  { label: t('nav.teamStructure'), icon: <AccountTree />, path: '/structure', requireRole: 'consultant' },
  { label: t('nav.products'), icon: <Inventory />, path: '/products', requireRole: 'consultant' },
  { label: t('nav.inssmart'), icon: <Security />, path: '/inssmart', requireRole: 'consultant' },
  { group: t('nav.contests'), requireRole: 'consultant' },
  { label: t('nav.contestList'), icon: <EmojiEvents />, path: '/contests', requireRole: 'consultant' },
  { group: t('nav.help') },
  { label: t('nav.instructions'), icon: <Help />, path: '/help' },
  { label: t('nav.communication'), icon: <Chat />, path: '/communication' },

  { group: t('nav.adminData'), adminOnly: true },
  { label: t('nav.contractManager'), icon: <Description />, path: '/admin/contracts', adminOnly: true },
  { label: t('nav.contractUpload'), icon: <Upload />, path: '/admin/contracts/upload', adminOnly: true },
  { label: t('nav.partners'), icon: <PersonSearch />, path: '/admin/partners', adminOnly: true },
  { label: t('nav.partnerStatuses'), icon: <EventNote />, path: '/admin/partners/statuses', adminOnly: true },
  { label: t('nav.clients'), icon: <People />, path: '/admin/clients', adminOnly: true },
  { label: t('nav.acceptance'), icon: <CheckCircle />, path: '/admin/acceptance', adminOnly: true },
  { label: t('nav.requisites'), icon: <CreditCard />, path: '/admin/requisites', adminOnly: true },
  { label: t('nav.transfers'), icon: <History />, path: '/admin/transfers', adminOnly: true },

  { group: t('nav.transactionsVolumes'), adminOnly: true },
  { label: t('nav.transactionImport'), icon: <Upload />, path: '/admin/transactions/import', adminOnly: true },
  { label: t('nav.transactions'), icon: <SwapHoriz />, path: '/admin/transactions', adminOnly: true },
  { label: t('nav.commissions'), icon: <Receipt />, path: '/admin/commissions', adminOnly: true },
  { label: t('nav.pool'), icon: <Paid />, path: '/admin/pool', adminOnly: true },
  { label: t('nav.qualifications'), icon: <BarChart />, path: '/admin/qualifications', adminOnly: true },

  { group: t('nav.chargesPayments'), adminOnly: true },
  { label: t('nav.charges'), icon: <AccountBalance />, path: '/admin/charges', adminOnly: true },
  { label: t('nav.payments'), icon: <Paid />, path: '/admin/payments', adminOnly: true },

  { group: t('nav.reportsSettings'), adminOnly: true },
  { label: t('nav.reports'), icon: <Assessment />, path: '/admin/reports', adminOnly: true },
  { label: t('nav.reportAvailability'), icon: <Settings />, path: '/admin/reports/availability', adminOnly: true },
  { label: t('nav.currencies'), icon: <CurrencyExchange />, path: '/admin/currencies', adminOnly: true },
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
  const isConsultant = user?.role?.includes('consultant') || isAdmin;
  const menuItems = getMenuItems();
  const visibleItems = menuItems.filter((item) => {
    if ('adminOnly' in item && item.adminOnly) return isAdmin;
    if ('requireRole' in item && item.requireRole === 'consultant') return isConsultant;
    return true;
  });

  const drawerContent = (
    <>
      <Toolbar sx={{ px: 2, py: 1.5 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <Typography variant="h6" sx={{ color: 'primary.main', fontWeight: 800 }}>
            {t('brand.name')}
          </Typography>
          <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 500 }}>
            {t('brand.subtitle')}
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
            : location.pathname === item.path;

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
