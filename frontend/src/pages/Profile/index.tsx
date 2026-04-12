import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, Grid, TextField, Button,
  CircularProgress, Alert, Divider, Avatar, Chip, Tabs, Tab,
  Select, MenuItem, InputLabel, FormControl, IconButton, Tooltip,
  Link as MuiLink,
} from '@mui/material';
import {
  Lock, Save, ContentCopy, CheckCircle, Warning,
  Person, AccountBalance, Share,
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { profileApi, ProfileData, RequisiteData, BankRequisiteData } from '../../api/profile';
import { t } from '../../i18n';

// --- Tab Panel ---
interface TabPanelProps { children?: React.ReactNode; index: number; value: number; }
const TabPanel: React.FC<TabPanelProps> = ({ children, value, index }) => (
  <Box role="tabpanel" hidden={value !== index} sx={{ pt: 3 }}>
    {value === index && children}
  </Box>
);

// --- Requisites Form ---
const RequisitesForm: React.FC<{
  initial: RequisiteData | null;
  onSave: (data: Partial<RequisiteData>) => Promise<void>;
  saving: boolean;
}> = ({ initial, onSave, saving }) => {
  const [form, setForm] = useState({
    individualEntrepreneur: initial?.individualEntrepreneur || '',
    inn: initial?.inn || '',
    ogrn: initial?.ogrn || '',
    address: initial?.address || '',
    email: initial?.email || '',
    phone: initial?.phone || '',
  });

  const set = (field: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm({ ...form, [field]: e.target.value });

  return (
    <Box>
      {initial?.verified === false && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Изменение реквизитов приведёт к сбросу верификации. Вы не сможете подать заявку на выплату комиссионных до повторной верификации.
        </Alert>
      )}
      {!initial && (
        <Alert severity="info" sx={{ mb: 2 }}>
          Без верифицированных реквизитов вы не сможете подать заявку на выплату комиссионных.
        </Alert>
      )}
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, sm: 6 }}>
          <TextField fullWidth label="Наименование ИП" value={form.individualEntrepreneur} onChange={set('individualEntrepreneur')} required />
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <TextField fullWidth label="ИНН" value={form.inn} onChange={set('inn')} required />
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <TextField fullWidth label="ОГРНИП" value={form.ogrn} onChange={set('ogrn')} />
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <TextField fullWidth label="Email" value={form.email} onChange={set('email')} />
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <TextField fullWidth label="Телефон" value={form.phone} onChange={set('phone')} />
        </Grid>
        <Grid size={{ xs: 12 }}>
          <TextField fullWidth label="Адрес регистрации" value={form.address} onChange={set('address')} multiline rows={2} />
        </Grid>
      </Grid>
      <Button
        variant="contained"
        startIcon={<Save />}
        onClick={() => onSave(form)}
        disabled={saving || !form.individualEntrepreneur || !form.inn}
        sx={{ mt: 2, background: 'linear-gradient(135deg, #4CAF50 0%, #66BB6A 100%)' }}
      >
        {saving ? 'Сохранение...' : 'Сохранить реквизиты'}
      </Button>
    </Box>
  );
};

// --- Bank Requisites Form ---
const BankRequisitesForm: React.FC<{
  initial: BankRequisiteData | null;
  hasRequisites: boolean;
  onSave: (data: Partial<BankRequisiteData>) => Promise<void>;
  saving: boolean;
}> = ({ initial, hasRequisites, onSave, saving }) => {
  const [form, setForm] = useState({
    bankName: initial?.bankName || '',
    bankBik: initial?.bankBik || '',
    accountNumber: initial?.accountNumber || '',
    correspondentAccount: initial?.correspondentAccount || '',
    beneficiaryName: initial?.beneficiaryName || '',
  });

  const set = (field: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm({ ...form, [field]: e.target.value });

  if (!hasRequisites) {
    return (
      <Alert severity="info">Сначала заполните и сохраните реквизиты ИП.</Alert>
    );
  }

  return (
    <Box>
      {initial?.verified === false && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Изменение платёжных реквизитов приведёт к сбросу верификации.
        </Alert>
      )}
      {!initial && (
        <Alert severity="info" sx={{ mb: 2 }}>
          Без верифицированных платёжных реквизитов вы не сможете подать заявку на выплату комиссионных.
        </Alert>
      )}
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, sm: 6 }}>
          <TextField fullWidth label="Наименование банка" value={form.bankName} onChange={set('bankName')} required />
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <TextField fullWidth label="БИК банка" value={form.bankBik} onChange={set('bankBik')} required />
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <TextField fullWidth label="Расчётный счёт" value={form.accountNumber} onChange={set('accountNumber')} required />
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <TextField fullWidth label="Корреспондентский счёт" value={form.correspondentAccount} onChange={set('correspondentAccount')} />
        </Grid>
        <Grid size={{ xs: 12 }}>
          <TextField fullWidth label="Получатель" value={form.beneficiaryName} onChange={set('beneficiaryName')} required />
        </Grid>
      </Grid>
      <Button
        variant="contained"
        startIcon={<Save />}
        onClick={() => onSave(form)}
        disabled={saving || !form.bankName || !form.bankBik || !form.accountNumber || !form.beneficiaryName}
        sx={{ mt: 2, background: 'linear-gradient(135deg, #4CAF50 0%, #66BB6A 100%)' }}
      >
        {saving ? 'Сохранение...' : 'Сохранить платёжные реквизиты'}
      </Button>
    </Box>
  );
};

// --- Main Profile ---
const Profile: React.FC = () => {
  const [data, setData] = useState<ProfileData | null>(null);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState(0);
  const [form, setForm] = useState({ phone: '', nicTG: '', gender: '', birthDate: '' });
  const [pwForm, setPwForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [msg, setMsg] = useState('');
  const [pwMsg, setPwMsg] = useState('');
  const [saving, setSaving] = useState(false);
  const [reqSaving, setReqSaving] = useState(false);
  const [bankSaving, setBankSaving] = useState(false);
  const [reqMsg, setReqMsg] = useState('');
  const [bankMsg, setBankMsg] = useState('');
  const [copied, setCopied] = useState(false);

  const reload = () => {
    profileApi.get().then((res) => {
      setData(res.data);
      const u = res.data.user;
      setForm({
        phone: u.phone || '',
        nicTG: u.nicTG || '',
        gender: u.gender || '',
        birthDate: u.birthDate ? u.birthDate.split('T')[0] : '',
      });
    }).finally(() => setLoading(false));
  };

  useEffect(() => { reload(); }, []);

  const handleSave = async () => {
    setSaving(true); setMsg('');
    try {
      await profileApi.update(form);
      setMsg('Профиль обновлён');
    } catch { setMsg('Ошибка сохранения'); }
    finally { setSaving(false); }
  };

  const handlePassword = async () => {
    setPwMsg('');
    try {
      await profileApi.changePassword(pwForm);
      setPwMsg('Пароль изменён');
      setPwForm({ current_password: '', password: '', password_confirmation: '' });
    } catch (err: any) {
      setPwMsg(err.response?.data?.message || 'Ошибка');
    }
  };

  const handleReqSave = async (reqData: Partial<any>) => {
    setReqSaving(true); setReqMsg('');
    try {
      await profileApi.updateRequisites(reqData);
      setReqMsg('Реквизиты сохранены');
      reload();
    } catch (err: any) {
      setReqMsg(err.response?.data?.message || 'Ошибка сохранения');
    } finally { setReqSaving(false); }
  };

  const handleBankSave = async (bankData: Partial<any>) => {
    setBankSaving(true); setBankMsg('');
    try {
      await profileApi.updateBankRequisites(bankData);
      setBankMsg('Платёжные реквизиты сохранены');
      reload();
    } catch (err: any) {
      setBankMsg(err.response?.data?.message || 'Ошибка сохранения');
    } finally { setBankSaving(false); }
  };

  const copyReferral = () => {
    if (data?.referral?.referralLink) {
      navigator.clipboard.writeText(data.referral.referralLink);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  if (loading) return <Box sx={{ display: 'flex', justifyContent: 'center', py: 10 }}><CircularProgress /></Box>;
  if (!data) return null;

  const { user, location, consultant, statusInfo, signedDocuments, requisites, bankRequisites, referral } = data;
  const initials = `${user.firstName?.[0] || ''}${user.lastName?.[0] || ''}`.toUpperCase();

  return (
    <Box>
      <Typography variant="h5" sx={{ mb: 3, fontWeight: 600 }}>{t('nav.profile')}</Typography>

      {/* Tabs */}
      <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ mb: 0, borderBottom: 1, borderColor: 'divider' }}>
        <Tab icon={<Person />} iconPosition="start" label="Информация" />
        <Tab icon={<AccountBalance />} iconPosition="start" label="Реквизиты" />
        <Tab icon={<Share />} iconPosition="start" label="Реферальные ссылки" />
      </Tabs>

      {/* Tab 1: Partner Info */}
      <TabPanel value={tab} index={0}>
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
          <Card sx={{ mb: 3 }}>
            <CardContent sx={{ p: 3 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 3, mb: 3 }}>
                <Avatar sx={{ width: 72, height: 72, bgcolor: 'primary.main', fontSize: 28 }}>{initials}</Avatar>
                <Box>
                  <Typography variant="h6">{user.lastName} {user.firstName} {user.patronymic}</Typography>
                  <Typography variant="body2" color="text.secondary">{user.email}</Typography>
                  <Box sx={{ display: 'flex', gap: 1, mt: 0.5, flexWrap: 'wrap' }}>
                    {user.role?.split(',').map((r) => (
                      <Chip key={r} label={r.trim()} size="small" variant="outlined" />
                    ))}
                    {statusInfo && (
                      <Chip
                        label={statusInfo.activityName}
                        size="small"
                        color={
                          statusInfo.activityId === 1 ? 'success' :
                          statusInfo.activityId === 4 ? 'info' :
                          statusInfo.activityId === 3 ? 'warning' :
                          statusInfo.activityId === 5 ? 'error' : 'default'
                        }
                      />
                    )}
                  </Box>
                </Box>
              </Box>

              {consultant && (
                <Box sx={{ bgcolor: '#f9f9f9', borderRadius: 2, p: 2, mb: 3 }}>
                  <Grid container spacing={2}>
                    <Grid size={{ xs: 6, sm: 3 }}>
                      <Typography variant="caption" color="text.secondary">ID консультанта</Typography>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>{consultant.id}</Typography>
                    </Grid>
                    <Grid size={{ xs: 6, sm: 3 }}>
                      <Typography variant="caption" color="text.secondary">Код участника</Typography>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>{consultant.participantCode || '—'}</Typography>
                    </Grid>
                    <Grid size={{ xs: 6, sm: 3 }}>
                      <Typography variant="caption" color="text.secondary">Наставник</Typography>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>{consultant.inviterName || '—'}</Typography>
                    </Grid>
                    <Grid size={{ xs: 6, sm: 3 }}>
                      <Typography variant="caption" color="text.secondary">Статус</Typography>
                      <Chip label={consultant.active ? 'Активен' : 'Неактивен'} size="small"
                        color={consultant.active ? 'success' : 'default'} />
                    </Grid>
                  </Grid>
                </Box>
              )}

              {/* Signed documents */}
              {signedDocuments.documents.length > 0 && (
                <Box sx={{ mb: 3 }}>
                  <Typography variant="subtitle2" sx={{ mb: 1, fontWeight: 600 }}>Подписанные документы</Typography>
                  {signedDocuments.documents.map((doc) => (
                    <Box key={doc.id} sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.5 }}>
                      {signedDocuments.accepted
                        ? <CheckCircle sx={{ fontSize: 16, color: 'success.main' }} />
                        : <Warning sx={{ fontSize: 16, color: 'warning.main' }} />
                      }
                      <MuiLink href={doc.link} target="_blank" rel="noopener" variant="body2">
                        {doc.name}
                      </MuiLink>
                    </Box>
                  ))}
                  {signedDocuments.acceptedAt && (
                    <Typography variant="caption" color="text.secondary" sx={{ mt: 1, display: 'block' }}>
                      Дата подписания: {new Date(signedDocuments.acceptedAt).toLocaleDateString('ru-RU')}
                    </Typography>
                  )}
                </Box>
              )}

              <Divider sx={{ my: 2 }} />
              <Typography variant="subtitle1" sx={{ mb: 2, fontWeight: 600 }}>Персональные данные</Typography>
              <Alert severity="info" sx={{ mb: 2 }}>
                ФИО заблокировано для самостоятельного изменения. Для смены обратитесь в техподдержку.
              </Alert>

              {msg && <Alert severity={msg.includes('Ошибка') ? 'error' : 'success'} sx={{ mb: 2 }}>{msg}</Alert>}

              <Grid container spacing={2}>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <TextField fullWidth label={t('auth.lastName')} value={user.lastName || ''} disabled />
                </Grid>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <TextField fullWidth label={t('auth.firstName')} value={user.firstName || ''} disabled />
                </Grid>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <TextField fullWidth label={t('auth.patronymic')} value={user.patronymic || ''} disabled />
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField fullWidth label={t('auth.phone')} value={form.phone}
                    onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField fullWidth label={t('auth.telegram')} value={form.nicTG} placeholder="@username"
                    onChange={(e) => setForm({ ...form, nicTG: e.target.value })} />
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <FormControl fullWidth>
                    <InputLabel>Пол</InputLabel>
                    <Select value={form.gender} label="Пол"
                      onChange={(e) => setForm({ ...form, gender: e.target.value })}>
                      <MenuItem value="">—</MenuItem>
                      <MenuItem value="Мужской">Мужской</MenuItem>
                      <MenuItem value="Женский">Женский</MenuItem>
                    </Select>
                  </FormControl>
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField fullWidth label={t('auth.birthDate')} type="date" value={form.birthDate}
                    onChange={(e) => setForm({ ...form, birthDate: e.target.value })}
                    slotProps={{ inputLabel: { shrink: true } }} />
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField fullWidth label="Налоговое резидентство" value={location.taxResidency || '—'} disabled />
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField fullWidth label={t('auth.city')} value={location.city || '—'} disabled />
                </Grid>
              </Grid>

              <Button variant="contained" startIcon={<Save />} onClick={handleSave} disabled={saving}
                sx={{ mt: 2, background: 'linear-gradient(135deg, #4CAF50 0%, #66BB6A 100%)' }}>
                {saving ? 'Сохранение...' : t('common.save')}
              </Button>
            </CardContent>
          </Card>

          {/* Password change */}
          <Card>
            <CardContent sx={{ p: 3 }}>
              <Typography variant="subtitle1" sx={{ mb: 2, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 1 }}>
                <Lock fontSize="small" /> Изменение пароля
              </Typography>

              {pwMsg && <Alert severity={pwMsg.includes('Ошибка') || pwMsg.includes('неверен') ? 'error' : 'success'} sx={{ mb: 2 }}>{pwMsg}</Alert>}

              <Grid container spacing={2}>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <TextField fullWidth label="Текущий пароль" type="password" value={pwForm.current_password}
                    onChange={(e) => setPwForm({ ...pwForm, current_password: e.target.value })} />
                </Grid>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <TextField fullWidth label="Новый пароль" type="password" value={pwForm.password}
                    onChange={(e) => setPwForm({ ...pwForm, password: e.target.value })} />
                </Grid>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <TextField fullWidth label="Подтверждение" type="password" value={pwForm.password_confirmation}
                    onChange={(e) => setPwForm({ ...pwForm, password_confirmation: e.target.value })} />
                </Grid>
              </Grid>

              <Button variant="outlined" startIcon={<Lock />} onClick={handlePassword} sx={{ mt: 2 }}
                disabled={!pwForm.current_password || !pwForm.password}>
                Сменить пароль
              </Button>
            </CardContent>
          </Card>
        </motion.div>
      </TabPanel>

      {/* Tab 2: Requisites */}
      <TabPanel value={tab} index={1}>
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
          {/* IP Requisites */}
          <Card sx={{ mb: 3 }}>
            <CardContent sx={{ p: 3 }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
                <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>Реквизиты ИП</Typography>
                {requisites && (
                  <Chip
                    icon={requisites.verified ? <CheckCircle /> : <Warning />}
                    label={requisites.verified ? 'Верифицировано' : 'Ожидает верификации'}
                    size="small"
                    color={requisites.verified ? 'success' : 'warning'}
                    variant="outlined"
                  />
                )}
              </Box>
              {reqMsg && <Alert severity={reqMsg.includes('Ошибка') ? 'error' : 'success'} sx={{ mb: 2 }}>{reqMsg}</Alert>}
              <RequisitesForm initial={requisites} onSave={handleReqSave} saving={reqSaving} />
            </CardContent>
          </Card>

          {/* Bank Requisites */}
          <Card>
            <CardContent sx={{ p: 3 }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
                <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>Платёжные реквизиты</Typography>
                {bankRequisites && (
                  <Chip
                    icon={bankRequisites.verified ? <CheckCircle /> : <Warning />}
                    label={bankRequisites.verified ? 'Верифицировано' : 'Ожидает верификации'}
                    size="small"
                    color={bankRequisites.verified ? 'success' : 'warning'}
                    variant="outlined"
                  />
                )}
              </Box>
              {bankMsg && <Alert severity={bankMsg.includes('Ошибка') ? 'error' : 'success'} sx={{ mb: 2 }}>{bankMsg}</Alert>}
              <BankRequisitesForm
                initial={bankRequisites}
                hasRequisites={!!requisites}
                onSave={handleBankSave}
                saving={bankSaving}
              />
            </CardContent>
          </Card>
        </motion.div>
      </TabPanel>

      {/* Tab 3: Referral Links */}
      <TabPanel value={tab} index={2}>
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>
          <Card>
            <CardContent sx={{ p: 3 }}>
              <Typography variant="subtitle1" sx={{ mb: 2, fontWeight: 600 }}>Реферальные ссылки</Typography>

              {referral?.canInvite ? (
                <Box>
                  <Alert severity="success" sx={{ mb: 3 }}>
                    Ваш статус «Активен» — вы можете приглашать партнёров по реферальной ссылке.
                  </Alert>

                  <Typography variant="body2" color="text.secondary" gutterBottom>Ваш реферальный код</Typography>
                  <Typography variant="h6" sx={{ mb: 2, fontWeight: 600 }}>{referral.referralCode}</Typography>

                  <Typography variant="body2" color="text.secondary" gutterBottom>Реферальная ссылка</Typography>
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, bgcolor: '#f5f5f5', borderRadius: 2, p: 2 }}>
                    <Typography variant="body2" sx={{ flexGrow: 1, wordBreak: 'break-all', fontFamily: 'monospace' }}>
                      {referral.referralLink}
                    </Typography>
                    <Tooltip title={copied ? 'Скопировано!' : 'Копировать'}>
                      <IconButton onClick={copyReferral} color={copied ? 'success' : 'default'}>
                        {copied ? <CheckCircle /> : <ContentCopy />}
                      </IconButton>
                    </Tooltip>
                  </Box>
                </Box>
              ) : (
                <Alert severity="info">
                  Реферальные ссылки доступны только в статусе «Активен».
                  {statusInfo && statusInfo.activityId === 4 && (
                    <Typography variant="body2" sx={{ mt: 1 }}>
                      Для активации необходимо набрать {statusInfo.requiredPoints} баллов ЛП в течение {statusInfo.daysRemaining} дней.
                    </Typography>
                  )}
                </Alert>
              )}
            </CardContent>
          </Card>
        </motion.div>
      </TabPanel>
    </Box>
  );
};

export default Profile;
