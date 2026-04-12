import React from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import {
  Drawer, List, ListItemButton, ListItemIcon, ListItemText,
  Toolbar, Typography, Box, Divider, useMediaQuery, useTheme, Chip,
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
import { AdminSection, getAvailableSections, cabinetNames } from '../../config/cabinetRoles';

const DRAWER_WIDTH = 260;

type MenuItem = {
  label: string;
  icon: React.ReactNode;
  path?: string;
  partner?: boolean;       // only for partners (consultant role)
  adminSection?: AdminSection; // requires this admin section
} | {
  group: string;
  partner?: boolean;
  adminSection?: AdminSection;
};

const getMenuItems = (): MenuItem[] => [
  // Partner menu
  { label: t('nav.education'), icon: <School />, path: '/education' },
  { label: t('nav.dashboard'), icon: <Dashboard />, path: '/', partner: true },
  { label: t('nav.referrals'), icon: <Share />, path: '/referrals', partner: true },
  { group: t('nav.finance'), partner: true },
  { label: t('nav.report'), icon: <AccountBalance />, path: '/finance/report', partner: true },
  { label: t('nav.calculator'), icon: <Calculate />, path: '/finance/calculator', partner: true },
  { group: t('nav.clients'), partner: true },
  { label: t('nav.clientList'), icon: <People />, path: '/clients', partner: true },
  { group: t('nav.contracts'), partner: true },
  { label: t('nav.myContracts'), icon: <Description />, path: '/contracts', partner: true },
  { label: t('nav.teamContracts'), icon: <FolderShared />, path: '/contracts/team', partner: true },
  { group: t('nav.structure'), partner: true },
  { label: t('nav.teamStructure'), icon: <AccountTree />, path: '/structure', partner: true },
  { label: t('nav.products'), icon: <Inventory />, path: '/products', partner: true },
  { label: t('nav.inssmart'), icon: <Security />, path: '/inssmart', partner: true },
  { group: t('nav.contests'), partner: true },
  { label: t('nav.contestList'), icon: <EmojiEvents />, path: '/contests', partner: true },
  { group: t('nav.help') },
  { label: t('nav.instructions'), icon: <Help />, path: '/help' },
  { label: t('nav.communication'), icon: <Chat />, path: '/communication' },

  // Admin sections (filtered by role)
  { group: t('nav.adminData'), adminSection: 'partners' },
  { label: t('nav.contractManager'), icon: <Description />, path: '/admin/contracts', adminSection: 'contractManager' },
  { label: t('nav.contractUpload'), icon: <Upload />, path: '/admin/contracts/upload', adminSection: 'contractUpload' },
  { label: t('nav.partners'), icon: <PersonSearch />, path: '/admin/partners', adminSection: 'partners' },
  { label: t('nav.partnerStatuses'), icon: <EventNote />, path: '/admin/partners/statuses', adminSection: 'partnerStatuses' },
  { label: t('nav.clients'), icon: <People />, path: '/admin/clients', adminSection: 'clients' },
  { label: t('nav.acceptance'), icon: <CheckCircle />, path: '/admin/acceptance', adminSection: 'acceptance' },
  { label: t('nav.requisites'), icon: <CreditCard />, path: '/admin/requisites', adminSection: 'requisites' },
  { label: t('nav.transfers'), icon: <History />, path: '/admin/transfers', adminSection: 'transfers' },

  { group: t('nav.transactionsVolumes'), adminSection: 'transactions' },
  { label: t('nav.transactionImport'), icon: <Upload />, path: '/admin/transactions/import', adminSection: 'transactionImport' },
  { label: t('nav.transactions'), icon: <SwapHoriz />, path: '/admin/transactions', adminSection: 'transactions' },
  { label: t('nav.commissions'), icon: <Receipt />, path: '/admin/commissions', adminSection: 'commissions' },
  { label: t('nav.pool'), icon: <Paid />, path: '/admin/pool', adminSection: 'pool' },
  { label: t('nav.qualifications'), icon: <BarChart />, path: '/admin/qualifications', adminSection: 'qualifications' },

  { group: t('nav.chargesPayments'), adminSection: 'charges' },
  { label: t('nav.charges'), icon: <AccountBalance />, path: '/admin/charges', adminSection: 'charges' },
  { label: t('nav.payments'), icon: <Paid />, path: '/admin/payments', adminSection: 'payments' },

  { group: t('nav.reportsSettings'), adminSection: 'reports' },
  { label: t('nav.reports'), icon: <Assessment />, path: '/admin/reports', adminSection: 'reports' },
  { label: t('nav.reportAvailability'), icon: <Settings />, path: '/admin/reports/availability', adminSection: 'reportAvailability' },
  { label: t('nav.currencies'), icon: <CurrencyExchange />, path: '/admin/currencies', adminSection: 'currencies' },
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

  const userRoles = (user?.role ?? '').split(',').map((r) => r.trim()).filter(Boolean);
  const isAdmin = userRoles.includes('admin');
  const isStaff = isAdmin || userRoles.some((r) => ['backoffice', 'support', 'finance', 'head', 'calculations', 'corrections'].includes(r));
  const isPartner = userRoles.includes('consultant') || isAdmin;

  // Get available admin sections for this user's roles
  const availableSections = getAvailableSections(isAdmin ? ['admin'] : userRoles);

  // Determine cabinet name for display
  const staffRole = userRoles.find((r) => cabinetNames[r]);
  const cabinetLabel = staffRole ? cabinetNames[staffRole] : (isAdmin ? cabinetNames.admin : null);

  const menuItems = getMenuItems();
  const visibleItems = menuItems.filter((item) => {
    if ('partner' in item && item.partner) return isPartner;
    if ('adminSection' in item && item.adminSection) {
      return isStaff && availableSections.has(item.adminSection);
    }
    return true;
  });

  const drawerContent = (
    <>
      <Toolbar sx={{ px: 2, py: 1.5 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
          <Typography variant="h6" sx={{ color: 'primary.main', fontWeight: 800 }}>
            {t('brand.name')}
          </Typography>
          <Typography variant="body2" sx={{ color: 'text.secondary', fontWeight: 500 }}>
            {t('brand.subtitle')}
          </Typography>
          {isStaff && (
            <AdminPanelSettings sx={{ color: 'secondary.main', fontSize: 18, ml: 0.5 }} />
          )}
        </Box>
      </Toolbar>
      {cabinetLabel && (
        <Box sx={{ px: 2, pb: 1 }}>
          <Chip label={cabinetLabel} size="small" color="secondary" variant="outlined" sx={{ fontSize: 11 }} />
        </Box>
      )}
      <Divider />
      <List sx={{ px: 1, overflow: 'auto' }}>
        {visibleItems.map((item, index) => {
          if ('group' in item) {
            const isAdminGroup = 'adminSection' in item && item.adminSection;
            return (
              <Typography
                key={`group-${index}`}
                variant="overline"
                sx={{
                  px: 2, pt: 2, pb: 0.5, display: 'block', fontSize: 11,
                  color: isAdminGroup ? 'secondary.main' : 'text.secondary',
                  fontWeight: isAdminGroup ? 700 : 400,
                }}
              >
                {item.group}
              </Typography>
            );
          }

          const isAdminItem = 'adminSection' in item && item.adminSection;
          const isActive = item.path === '/'
            ? location.pathname === '/'
            : item.path ? location.pathname.startsWith(item.path) : false;

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
                  bgcolor: isAdminItem ? 'secondary.main' : 'primary.main',
                  color: '#fff',
                  '&:hover': { bgcolor: isAdminItem ? 'secondary.dark' : 'primary.dark' },
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
