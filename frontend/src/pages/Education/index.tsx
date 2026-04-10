import React, { useState } from 'react';
import {
  Box, Typography, Card, CardContent, Button,
  LinearProgress, Chip, Accordion, AccordionSummary, AccordionDetails,
  Radio, RadioGroup, FormControlLabel, FormControl, Alert,
  Dialog, DialogTitle, DialogContent, DialogActions,
} from '@mui/material';
import {
  ExpandMore, CheckCircle, School, Quiz,
} from '@mui/icons-material';
import { motion } from 'framer-motion';

interface Module {
  id: number;
  title: string;
  description: string;
  videoUrl?: string;
  content: string;
  test: TestQuestion[];
  completed: boolean;
}

interface TestQuestion {
  question: string;
  options: string[];
  correct: number;
}

const modules: Module[] = [
  {
    id: 1,
    title: 'Введение в DS Consulting',
    description: 'Знакомство с компанией, миссия, ценности, структура партнёрской сети',
    content: `
DS Consulting — финансовая консалтинговая компания, которая помогает клиентам в управлении личными финансами,
инвестициях и страховании. Наша миссия — сделать финансовое планирование доступным каждому.

Партнёрская сеть строится на принципах:
• Профессионализм — все партнёры проходят обучение
• Прозрачность — открытая система начислений
• Поддержка — наставник помогает на каждом этапе
• Развитие — система квалификаций мотивирует расти
    `,
    test: [
      {
        question: 'Что является миссией DS Consulting?',
        options: [
          'Продажа страховых полисов',
          'Сделать финансовое планирование доступным каждому',
          'Управление банковскими вкладами',
          'Торговля на бирже',
        ],
        correct: 1,
      },
      {
        question: 'На каких принципах строится партнёрская сеть?',
        options: [
          'Конкуренция и агрессивные продажи',
          'Профессионализм, прозрачность, поддержка, развитие',
          'Максимальная прибыль любой ценой',
          'Независимость от компании',
        ],
        correct: 1,
      },
    ],
    completed: false,
  },
  {
    id: 2,
    title: 'Продукты и программы',
    description: 'Обзор продуктовой линейки: инвестиции, страхование, образование',
    content: `
Продуктовая линейка DS Consulting включает:

📊 Инвестиционные продукты:
• Внебиржевые активы (Axevil, Private Equity)
• Инвестиции в страховой оболочке (Hansard, Investor Trust)
• Портфельное управление (IB портфель, Exante)

🛡️ Страховые продукты:
• Страхование жизни и здоровья
• ОСАГО, КАСКО через Inssmart

📚 Образовательные продукты:
• Курсы по финансовой грамотности
• Сертификация финансовых консультантов

Каждый продукт имеет свою комиссионную ставку и условия расчёта объёмов.
    `,
    test: [
      {
        question: 'Какие категории продуктов есть в DS Consulting?',
        options: [
          'Только страхование',
          'Инвестиции, страхование, образование',
          'Только образовательные курсы',
          'Банковские кредиты',
        ],
        correct: 1,
      },
      {
        question: 'Через какую систему оформляются страховые полисы?',
        options: ['GetCourse', 'Inssmart', 'Bubble', 'Directual'],
        correct: 1,
      },
    ],
    completed: false,
  },
  {
    id: 3,
    title: 'Система квалификаций и начислений',
    description: 'Как работают уровни, объёмы, комиссии и стартовый период',
    content: `
Система квалификаций определяет ваш уровень и размер комиссии:

🏆 Уровни квалификации:
1. Start — 15%
2. Pro — 20%
3. Expert — 25%
4. FC — 30%
5. Master FC — 35%
6. TOP FC — 40%
7. Silver DS — 45%
8. Gold DS — 49%
9. Platinum DS — 52%
10. Co-founder DS — 55%

📈 Виды объёмов:
• ЛП (Личные продажи) — ваши личные сделки
• ГП (Групповые продажи) — сделки вашей команды
• НГП (Накопленные групповые продажи) — общий итог

⏱️ Стартовый период:
У вас 90 дней с момента регистрации, чтобы набрать 500 баллов личного объёма.
Если не набрали — аккаунт деактивируется.
    `,
    test: [
      {
        question: 'Сколько баллов нужно набрать за стартовый период?',
        options: ['100', '250', '500', '1000'],
        correct: 2,
      },
      {
        question: 'Сколько длится стартовый период?',
        options: ['30 дней', '60 дней', '90 дней', '180 дней'],
        correct: 2,
      },
      {
        question: 'Какой процент комиссии у уровня Expert?',
        options: ['15%', '20%', '25%', '30%'],
        correct: 2,
      },
    ],
    completed: false,
  },
];

const Education: React.FC = () => {
  const [moduleStates, setModuleStates] = useState(modules.map(() => ({ completed: false })));
  const [testDialog, setTestDialog] = useState<{ moduleIdx: number } | null>(null);
  const [answers, setAnswers] = useState<Record<number, number>>({});
  const [testResult, setTestResult] = useState<{ passed: boolean; score: number } | null>(null);
  const [allPassed, setAllPassed] = useState(false);
  const [activating, setActivating] = useState(false);

  const completedCount = moduleStates.filter((m) => m.completed).length;
  const progress = (completedCount / modules.length) * 100;

  const handleStartTest = (moduleIdx: number) => {
    setAnswers({});
    setTestResult(null);
    setTestDialog({ moduleIdx });
  };

  const handleSubmitTest = () => {
    if (!testDialog) return;
    const module = modules[testDialog.moduleIdx];
    let correct = 0;
    module.test.forEach((q, i) => {
      if (answers[i] === q.correct) correct++;
    });
    const score = Math.round((correct / module.test.length) * 100);
    const passed = score >= 70;

    setTestResult({ passed, score });

    if (passed) {
      const newStates = [...moduleStates];
      newStates[testDialog.moduleIdx] = { completed: true };
      setModuleStates(newStates);

      if (newStates.every((m) => m.completed)) {
        setAllPassed(true);
      }
    }
  };

  const handleActivate = async () => {
    setActivating(true);
    try {
      const token = localStorage.getItem('auth_token');
      const res = await fetch('/api/v1/auth/activate', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`,
        },
      });
      if (res.ok) {
        window.location.href = '/';
      }
    } catch {
      // ignore
    } finally {
      setActivating(false);
    }
  };

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3 }}>
        <School sx={{ fontSize: 32, color: 'primary.main' }} />
        <Typography variant="h5" sx={{ fontWeight: 600 }}>Обучение</Typography>
      </Box>

      {/* Progress */}
      <Card sx={{ mb: 3 }}>
        <CardContent sx={{ p: 3 }}>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
            <Typography variant="body2" color="text.secondary">
              Прогресс: {completedCount} из {modules.length} модулей
            </Typography>
            <Chip
              label={allPassed ? 'Все тесты сданы!' : `${Math.round(progress)}%`}
              color={allPassed ? 'success' : 'default'}
              size="small"
            />
          </Box>
          <LinearProgress
            variant="determinate"
            value={progress}
            sx={{ height: 10, borderRadius: 5 }}
            color={allPassed ? 'success' : 'primary'}
          />
        </CardContent>
      </Card>

      {allPassed && (
        <motion.div initial={{ opacity: 0, y: -10 }} animate={{ opacity: 1, y: 0 }}>
          <Alert
            severity="success"
            sx={{ mb: 3 }}
            action={
              <Button color="inherit" variant="outlined" size="small" onClick={handleActivate} disabled={activating}>
                {activating ? 'Активация...' : 'Активировать аккаунт'}
              </Button>
            }
          >
            Поздравляем! Вы успешно завершили обучение. Нажмите кнопку чтобы получить доступ ко всем разделам платформы.
          </Alert>
        </motion.div>
      )}

      {/* Modules */}
      {modules.map((module, idx) => (
        <motion.div
          key={module.id}
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: idx * 0.1 }}
        >
          <Accordion sx={{ mb: 1, '&:before': { display: 'none' } }}>
            <AccordionSummary expandIcon={<ExpandMore />}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, width: '100%' }}>
                {moduleStates[idx].completed ? (
                  <CheckCircle color="success" />
                ) : (
                  <Chip label={idx + 1} size="small" color="primary" variant="outlined" />
                )}
                <Box sx={{ flex: 1 }}>
                  <Typography sx={{ fontWeight: 600 }}>{module.title}</Typography>
                  <Typography variant="body2" color="text.secondary">{module.description}</Typography>
                </Box>
                {moduleStates[idx].completed && (
                  <Chip label="Пройден" color="success" size="small" />
                )}
              </Box>
            </AccordionSummary>
            <AccordionDetails>
              <Typography variant="body2" sx={{ whiteSpace: 'pre-line', mb: 2, lineHeight: 1.8 }}>
                {module.content}
              </Typography>

              {!moduleStates[idx].completed && (
                <Button
                  variant="contained"
                  startIcon={<Quiz />}
                  onClick={() => handleStartTest(idx)}
                  sx={{
                    background: 'linear-gradient(135deg, #4CAF50 0%, #66BB6A 100%)',
                  }}
                >
                  Пройти тест
                </Button>
              )}
            </AccordionDetails>
          </Accordion>
        </motion.div>
      ))}

      {/* Test Dialog */}
      <Dialog open={!!testDialog} onClose={() => setTestDialog(null)} maxWidth="sm" fullWidth>
        {testDialog && (
          <>
            <DialogTitle>Тест: {modules[testDialog.moduleIdx].title}</DialogTitle>
            <DialogContent>
              {testResult && (
                <Alert severity={testResult.passed ? 'success' : 'error'} sx={{ mb: 2 }}>
                  {testResult.passed
                    ? `Тест пройден! Результат: ${testResult.score}%`
                    : `Тест не пройден. Результат: ${testResult.score}%. Необходимо 70% для прохождения.`
                  }
                </Alert>
              )}

              {!testResult && modules[testDialog.moduleIdx].test.map((q, qIdx) => (
                <Box key={qIdx} sx={{ mb: 3 }}>
                  <Typography sx={{ fontWeight: 600, mb: 1 }}>
                    {qIdx + 1}. {q.question}
                  </Typography>
                  <FormControl>
                    <RadioGroup
                      value={answers[qIdx] ?? ''}
                      onChange={(e) => setAnswers({ ...answers, [qIdx]: parseInt(e.target.value) })}
                    >
                      {q.options.map((opt, optIdx) => (
                        <FormControlLabel
                          key={optIdx}
                          value={optIdx}
                          control={<Radio />}
                          label={opt}
                        />
                      ))}
                    </RadioGroup>
                  </FormControl>
                </Box>
              ))}
            </DialogContent>
            <DialogActions>
              <Button onClick={() => setTestDialog(null)}>
                {testResult ? 'Закрыть' : 'Отмена'}
              </Button>
              {!testResult && (
                <Button
                  variant="contained"
                  onClick={handleSubmitTest}
                  disabled={Object.keys(answers).length < modules[testDialog.moduleIdx].test.length}
                >
                  Проверить ответы
                </Button>
              )}
            </DialogActions>
          </>
        )}
      </Dialog>
    </Box>
  );
};

export default Education;
