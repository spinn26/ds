import React from 'react';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
import { ThemeProvider, CssBaseline } from '@mui/material';
import theme from './theme/theme';
import MainLayout from './components/Layout/MainLayout';
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

const App: React.FC = () => {
  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <BrowserRouter basename="/partner">
        <Routes>
          <Route element={<MainLayout />}>
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
          </Route>
        </Routes>
      </BrowserRouter>
    </ThemeProvider>
  );
};

export default App;
