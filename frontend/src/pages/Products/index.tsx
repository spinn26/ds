import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, Grid, TextField, CircularProgress,
  Chip, Button, InputAdornment, FormControl, InputLabel, Select, MenuItem,
  Alert, Dialog, DialogTitle, DialogContent, DialogActions,
} from '@mui/material';
import {
  Search, Inventory, Lock, CheckCircle, School, Warning,
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { useNavigate } from 'react-router-dom';
import api from '../../api/client';
import { t } from '../../i18n';

interface ProductItem {
  id: number; name: string; description: string | null;
  typeName: string | null; accessible: boolean; testPassed: boolean;
}

interface AccessCheck {
  hasAccess: boolean; testsPassed: boolean;
  requisitesVerified: boolean; documentsAccepted: boolean;
  needsRequisites?: boolean; needsAcceptance?: boolean;
}

const Products: React.FC = () => {
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [categories, setCategories] = useState<{ id: number; name: string }[]>([]);
  const [accessCheck, setAccessCheck] = useState<AccessCheck | null>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [blockingDialog, setBlockingDialog] = useState<'requisites' | 'acceptance' | null>(null);
  const navigate = useNavigate();

  useEffect(() => {
    setLoading(true);
    const params: any = {};
    if (search) params.search = search;
    if (category) params.category = category;

    api.get('/products', { params })
      .then((res) => {
        setProducts(res.data.products);
        setCategories(res.data.categories || []);
        setAccessCheck(res.data.accessCheck);

        // Blocking dialogs
        const ac = res.data.accessCheck;
        if (ac?.needsRequisites) setBlockingDialog('requisites');
        else if (ac?.needsAcceptance) setBlockingDialog('acceptance');
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [search, category]);

  if (loading) {
    return <Box sx={{ display: 'flex', justifyContent: 'center', py: 10 }}><CircularProgress /></Box>;
  }

  // No tests passed at all
  if (accessCheck && !accessCheck.testsPassed) {
    return (
      <Box>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
          <Inventory sx={{ fontSize: 32, color: 'primary.main' }} />
          <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.products')}</Typography>
        </Box>
        <Alert severity="info" icon={<School />} sx={{ mb: 3 }}>
          <Typography variant="body1" sx={{ fontWeight: 600 }}>
            Для доступа к продуктам необходимо пройти обучение
          </Typography>
          <Typography variant="body2" sx={{ mt: 1 }}>
            Пройдите хотя бы один тест в разделе «Обучение». После успешного прохождения теста
            будет открыт доступ к продукту, по которому сдан тест.
          </Typography>
          <Button variant="contained" sx={{ mt: 2 }} onClick={() => navigate('/education')}>
            Перейти к обучению
          </Button>
        </Alert>
      </Box>
    );
  }

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <Inventory sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.products')}</Typography>
        <Chip label={`${products.length}`} color="primary" size="small" />
      </Box>

      {/* Access warnings */}
      {accessCheck && !accessCheck.requisitesVerified && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Реквизиты не верифицированы. Заполните реквизиты ИП в разделе{' '}
          <Button size="small" onClick={() => navigate('/profile')}>Профиль → Реквизиты</Button>
        </Alert>
      )}
      {accessCheck && !accessCheck.documentsAccepted && accessCheck.requisitesVerified && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Необходимо принять условия документов для доступа к продуктам.
        </Alert>
      )}

      {/* Filters */}
      <Card sx={{ mb: 3 }}>
        <CardContent sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', py: 2 }}>
          <TextField
            size="small" placeholder="Поиск по названию..." value={search}
            onChange={(e) => setSearch(e.target.value)}
            sx={{ minWidth: 250 }}
            slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search /></InputAdornment> } }}
          />
          {categories.length > 0 && (
            <FormControl size="small" sx={{ minWidth: 200 }}>
              <InputLabel>Категория</InputLabel>
              <Select value={category} label="Категория"
                onChange={(e) => setCategory(e.target.value)}>
                <MenuItem value="">Все</MenuItem>
                {categories.map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
              </Select>
            </FormControl>
          )}
        </CardContent>
      </Card>

      {/* Product cards */}
      <Grid container spacing={3}>
        {products.map((p, idx) => (
          <Grid size={{ xs: 12, sm: 6, md: 4 }} key={p.id}>
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: idx * 0.05 }}
            >
              <Card sx={{
                height: '100%', display: 'flex', flexDirection: 'column',
                opacity: p.accessible ? 1 : 0.6,
                border: p.accessible ? '1px solid #4CAF50' : '1px solid #e0e0e0',
              }}>
                <CardContent sx={{ flexGrow: 1, p: 3 }}>
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: 2 }}>
                    <Typography variant="h6" sx={{ fontWeight: 600 }}>{p.name}</Typography>
                    {p.accessible
                      ? <CheckCircle sx={{ color: 'success.main' }} />
                      : <Lock sx={{ color: 'text.disabled' }} />
                    }
                  </Box>

                  {p.typeName && (
                    <Chip label={p.typeName} size="small" variant="outlined" sx={{ mb: 1 }} />
                  )}

                  <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                    {p.description || 'Описание продукта будет добавлено позже.'}
                  </Typography>

                  {!p.testPassed && (
                    <Chip icon={<School />} label="Пройдите обучение" size="small" color="info" variant="outlined" />
                  )}
                </CardContent>

                <Box sx={{ p: 2, pt: 0, display: 'flex', gap: 1 }}>
                  <Button size="small" variant="outlined" onClick={() => navigate('/education')}>
                    Обучение
                  </Button>
                  {p.accessible && (
                    <Button size="small" variant="contained" color="success">
                      Открыть продукт
                    </Button>
                  )}
                </Box>
              </Card>
            </motion.div>
          </Grid>
        ))}
      </Grid>

      {products.length === 0 && (
        <Alert severity="info">Продукты не найдены</Alert>
      )}

      {/* Requisites blocking dialog */}
      <Dialog open={blockingDialog === 'requisites'} maxWidth="sm" fullWidth>
        <DialogTitle>Заполните реквизиты ИП</DialogTitle>
        <DialogContent>
          <Alert severity="info" sx={{ mt: 1 }}>
            Для доступа к продуктам необходимо заполнить реквизиты ИП и пройти верификацию.
          </Alert>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setBlockingDialog(null)}>Позже</Button>
          <Button variant="contained" onClick={() => { setBlockingDialog(null); navigate('/profile'); }}>
            Перейти к реквизитам
          </Button>
        </DialogActions>
      </Dialog>

      {/* Acceptance blocking dialog */}
      <Dialog open={blockingDialog === 'acceptance'} maxWidth="sm" fullWidth>
        <DialogTitle>Акцепт документов</DialogTitle>
        <DialogContent>
          <Alert severity="info" sx={{ mt: 1 }}>
            Для доступа к продуктам необходимо принять условия партнёрских документов.
          </Alert>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setBlockingDialog(null)}>Позже</Button>
          <Button variant="contained" onClick={() => { setBlockingDialog(null); navigate('/profile'); }}>
            Перейти в профиль
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default Products;
