import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { ThemeProvider, CssBaseline, CircularProgress, Box } from '@mui/material';
import theme from './theme/theme';
import { AuthProvider, useAuth } from './hooks/useAuth';
import MainLayout from './components/Layout/MainLayout';
import Login from './pages/Auth/Login';
import Register from './pages/Auth/Register';

// Education
import Education from './pages/Education';

// Partner pages
import Dashboard from './pages/Dashboard';
import Referrals from './pages/Referrals';
import Report from './pages/Finance/Report';
import Calculator from './pages/Finance/Calculator';
import ClientList from './pages/Clients/ClientList';
import MyContracts from './pages/Contracts/MyContracts';
import TeamContracts from './pages/Contracts/TeamContracts';
import Structure from './pages/Structure';
import Products from './pages/Products';
import Contests from './pages/Contests';
import Communication from './pages/Communication';
import Help from './pages/Help';
import Profile from './pages/Profile';

// Admin pages
import ContractManager from './pages/Admin/ContractManager';
import ContractUpload from './pages/Admin/ContractUpload';
import Partners from './pages/Admin/Partners';
import PartnerStatuses from './pages/Admin/PartnerStatuses';
import AdminClients from './pages/Admin/Clients';
import Acceptance from './pages/Admin/Acceptance';
import Requisites from './pages/Admin/Requisites';
import Transfers from './pages/Admin/Transfers';
import TransactionImport from './pages/Admin/TransactionImport';
import AdminTransactions from './pages/Admin/Transactions';
import Commissions from './pages/Admin/Commissions';
import Pool from './pages/Admin/Pool';
import Qualifications from './pages/Admin/Qualifications';
import Charges from './pages/Admin/Charges';
import Payments from './pages/Admin/Payments';
import Reports from './pages/Admin/Reports';
import ReportAvailability from './pages/Admin/ReportAvailability';
import Currencies from './pages/Admin/Currencies';

const ProtectedRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, loading } = useAuth();
  if (loading) return <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh' }}><CircularProgress /></Box>;
  if (!user) return <Navigate to="/login" replace />;
  // Registered-only users can only access /education, /help, /communication, /profile
  if (user.role === 'registered' && !['/education', '/help', '/communication', '/profile'].some(p => window.location.pathname === p)) {
    return <Navigate to="/education" replace />;
  }
  return <>{children}</>;
};

const GuestRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, loading } = useAuth();
  if (loading) return <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh' }}><CircularProgress /></Box>;
  return user ? <Navigate to="/" replace /> : <>{children}</>;
};

const AppRoutes: React.FC = () => (
  <Routes>
    <Route path="/login" element={<GuestRoute><Login /></GuestRoute>} />
    <Route path="/register" element={<GuestRoute><Register /></GuestRoute>} />

    <Route element={<ProtectedRoute><MainLayout /></ProtectedRoute>}>
      {/* Education (available for all) */}
      <Route path="/education" element={<Education />} />

      {/* Partner */}
      <Route path="/" element={<Dashboard />} />
      <Route path="/referrals" element={<Referrals />} />
      <Route path="/finance/report" element={<Report />} />
      <Route path="/finance/calculator" element={<Calculator />} />
      <Route path="/clients" element={<ClientList />} />
      <Route path="/contracts" element={<MyContracts />} />
      <Route path="/contracts/team" element={<TeamContracts />} />
      <Route path="/structure" element={<Structure />} />
      <Route path="/products" element={<Products />} />
      <Route path="/contests" element={<Contests />} />
      <Route path="/communication" element={<Communication />} />
      <Route path="/help" element={<Help />} />
      <Route path="/profile" element={<Profile />} />

      {/* Admin */}
      <Route path="/admin/contracts" element={<ContractManager />} />
      <Route path="/admin/contracts/upload" element={<ContractUpload />} />
      <Route path="/admin/partners" element={<Partners />} />
      <Route path="/admin/partners/statuses" element={<PartnerStatuses />} />
      <Route path="/admin/clients" element={<AdminClients />} />
      <Route path="/admin/acceptance" element={<Acceptance />} />
      <Route path="/admin/requisites" element={<Requisites />} />
      <Route path="/admin/transfers" element={<Transfers />} />
      <Route path="/admin/transactions/import" element={<TransactionImport />} />
      <Route path="/admin/transactions" element={<AdminTransactions />} />
      <Route path="/admin/commissions" element={<Commissions />} />
      <Route path="/admin/pool" element={<Pool />} />
      <Route path="/admin/qualifications" element={<Qualifications />} />
      <Route path="/admin/charges" element={<Charges />} />
      <Route path="/admin/payments" element={<Payments />} />
      <Route path="/admin/reports" element={<Reports />} />
      <Route path="/admin/reports/availability" element={<ReportAvailability />} />
      <Route path="/admin/currencies" element={<Currencies />} />
    </Route>

    <Route path="*" element={<Navigate to="/" replace />} />
  </Routes>
);

const App: React.FC = () => (
  <ThemeProvider theme={theme}>
    <CssBaseline />
    <BrowserRouter>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </BrowserRouter>
  </ThemeProvider>
);

export default App;
