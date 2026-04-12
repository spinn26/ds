import React, { useState, useEffect } from 'react';
import {
  Box, Typography, Card, CardContent, Chip, CircularProgress,
  Button, TextField, Dialog, DialogTitle, DialogContent, DialogActions,
  FormControl, InputLabel, Select, MenuItem, TablePagination,
  Alert,
} from '@mui/material';
import {
  Chat, Send, MarkEmailRead, Reply, NewReleases,
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { communicationApi, Message, Category } from '../../api/communication';
import { t } from '../../i18n';

const Communication: React.FC = () => {
  const [messages, setMessages] = useState<Message[]>([]);
  const [total, setTotal] = useState(0);
  const [unreadCount, setUnreadCount] = useState(0);
  const [page, setPage] = useState(0);
  const [categoryFilter, setCategoryFilter] = useState('');
  const [loading, setLoading] = useState(true);

  // Send dialog
  const [sendOpen, setSendOpen] = useState(false);
  const [sendCategory, setSendCategory] = useState<number>(0);
  const [sendMessage, setSendMessage] = useState('');
  const [sending, setSending] = useState(false);

  // Categories
  const [categories, setCategories] = useState<Category[]>([]);

  useEffect(() => {
    communicationApi.categories().then((r) => setCategories(r.data)).catch(() => {});
  }, []);

  const loadMessages = () => {
    setLoading(true);
    const params: any = { page: page + 1 };
    if (categoryFilter) params.category = categoryFilter;

    communicationApi.list(params)
      .then((res) => {
        setMessages(res.data.data);
        setTotal(res.data.total);
        setUnreadCount(res.data.unreadCount);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { loadMessages(); }, [page, categoryFilter]);

  const handleMarkRead = async (id: number) => {
    await communicationApi.markRead(id);
    loadMessages();
  };

  const handleSend = async () => {
    if (!sendCategory || !sendMessage.trim()) return;
    setSending(true);
    try {
      await communicationApi.send({ category: sendCategory, message: sendMessage });
      setSendOpen(false);
      setSendMessage('');
      setSendCategory(0);
      loadMessages();
    } catch {}
    setSending(false);
  };

  const openReply = (msg: Message) => {
    setSendCategory(msg.category);
    setSendMessage('');
    setSendOpen(true);
  };

  const formatDate = (iso: string) => {
    const d = new Date(iso);
    return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  };

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <Chat sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{t('nav.communication')}</Typography>
        {unreadCount > 0 && (
          <Chip label={`${unreadCount} новых`} color="error" size="small" />
        )}
      </Box>

      {/* Filters + Send */}
      <Card sx={{ mb: 2 }}>
        <CardContent sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center', py: 2 }}>
          <FormControl size="small" sx={{ minWidth: 200 }}>
            <InputLabel>Категория</InputLabel>
            <Select value={categoryFilter} label="Категория"
              onChange={(e) => { setCategoryFilter(e.target.value); setPage(0); }}>
              <MenuItem value="">Все</MenuItem>
              {categories.map((c) => <MenuItem key={c.id} value={c.id}>{c.title}</MenuItem>)}
            </Select>
          </FormControl>
          <Box sx={{ flexGrow: 1 }} />
          <Button variant="contained" startIcon={<Send />} onClick={() => setSendOpen(true)}>
            Написать
          </Button>
        </CardContent>
      </Card>

      {/* Messages */}
      {loading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}><CircularProgress /></Box>
      ) : messages.length === 0 ? (
        <Alert severity="info">Сообщений пока нет</Alert>
      ) : (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
          {messages.map((msg) => (
            <Card key={msg.id} sx={{ mb: 1.5, borderLeft: msg.isIncoming ? '4px solid #2196F3' : '4px solid #4CAF50' }}>
              <CardContent sx={{ py: 2, '&:last-child': { pb: 2 } }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: 1 }}>
                  <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>
                    <Chip
                      label={msg.isIncoming ? 'От DS' : 'Вы'}
                      size="small"
                      color={msg.isIncoming ? 'primary' : 'success'}
                      variant="outlined"
                    />
                    {msg.categoryTitle && (
                      <Chip label={msg.categoryTitle} size="small" variant="outlined" />
                    )}
                    {msg.isIncoming && !msg.read && (
                      <Chip icon={<NewReleases />} label="Новое" size="small" color="error" />
                    )}
                  </Box>
                  <Typography variant="caption" color="text.secondary">
                    {formatDate(msg.date)}
                  </Typography>
                </Box>

                <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap', mb: 1 }}>
                  {msg.message}
                </Typography>

                <Box sx={{ display: 'flex', gap: 1 }}>
                  {msg.isIncoming && !msg.read && (
                    <Button size="small" startIcon={<MarkEmailRead />}
                      onClick={() => handleMarkRead(msg.id)}>
                      Прочитано
                    </Button>
                  )}
                  {msg.isIncoming && (
                    <Button size="small" startIcon={<Reply />}
                      onClick={() => openReply(msg)}>
                      Ответить
                    </Button>
                  )}
                </Box>
              </CardContent>
            </Card>
          ))}

          <TablePagination
            component="div" count={total} page={page} rowsPerPage={25}
            onPageChange={(_, p) => setPage(p)} rowsPerPageOptions={[25]}
            labelDisplayedRows={({ from, to, count }) => `${from}–${to} из ${count}`}
          />
        </motion.div>
      )}

      {/* Send dialog */}
      <Dialog open={sendOpen} onClose={() => setSendOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Новое сообщение</DialogTitle>
        <DialogContent>
          <FormControl fullWidth sx={{ mt: 1, mb: 2 }}>
            <InputLabel>Категория</InputLabel>
            <Select value={sendCategory || ''} label="Категория"
              onChange={(e) => setSendCategory(Number(e.target.value))}>
              {categories.map((c) => <MenuItem key={c.id} value={c.id}>{c.title}</MenuItem>)}
            </Select>
          </FormControl>
          <TextField
            fullWidth multiline rows={4}
            label="Сообщение"
            value={sendMessage}
            onChange={(e) => setSendMessage(e.target.value)}
            placeholder="Введите текст сообщения..."
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setSendOpen(false)}>{t('common.cancel')}</Button>
          <Button variant="contained" startIcon={<Send />}
            onClick={handleSend}
            disabled={sending || !sendCategory || !sendMessage.trim()}>
            {sending ? 'Отправка...' : 'Отправить'}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default Communication;
