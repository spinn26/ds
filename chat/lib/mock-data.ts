import type { Ticket, User, Message, CustomerThread, InternalNote, QuickReply, KnowledgeArticle, Notification } from "./types"

export const agents: User[] = [
  {
    id: "agent-1",
    name: "Алексей Петров",
    email: "alexey@support.com",
    avatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Alexey",
    role: "agent",
    department: "technical",
  },
  {
    id: "agent-2",
    name: "Мария Иванова",
    email: "maria@support.com",
    avatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Maria",
    role: "agent",
    department: "billing",
  },
  {
    id: "agent-3",
    name: "Дмитрий Сидоров",
    email: "dmitry@support.com",
    avatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Dmitry",
    role: "agent",
    department: "sales",
  },
  {
    id: "agent-4",
    name: "Анна Козлова",
    email: "anna@support.com",
    avatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Anna",
    role: "admin",
    department: "general",
  },
]

export const customers: User[] = [
  {
    id: "cust-1",
    name: "Иван Смирнов",
    email: "ivan@example.com",
    avatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Ivan",
    role: "customer",
  },
  {
    id: "cust-2",
    name: "Елена Новикова",
    email: "elena@example.com",
    avatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Elena",
    role: "customer",
  },
  {
    id: "cust-3",
    name: "Сергей Волков",
    email: "sergey@example.com",
    avatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Sergey",
    role: "customer",
  },
  {
    id: "cust-4",
    name: "Ольга Морозова",
    email: "olga@example.com",
    avatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Olga",
    role: "customer",
  },
  {
    id: "cust-5",
    name: "Андрей Лебедев",
    email: "andrey@example.com",
    avatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Andrey",
    role: "customer",
  },
]

export const tickets: Ticket[] = [
  {
    id: "TK-001",
    subject: "Не работает авторизация в системе",
    description: "После обновления браузера перестала работать авторизация. Постоянно выбрасывает на страницу логина.",
    status: "new",
    priority: "critical",
    department: "technical",
    customerId: "cust-1",
    customerName: "Иван Смирнов",
    customerEmail: "ivan@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Ivan",
    createdAt: new Date("2024-01-15T10:30:00"),
    updatedAt: new Date("2024-01-15T10:30:00"),
    lastMessageAt: new Date("2024-01-15T10:30:00"),
    messagesCount: 1,
    tags: ["авторизация", "срочно"],
  },
  {
    id: "TK-002",
    subject: "Вопрос по оплате подписки",
    description: "Хочу уточнить возможность оплаты годовой подписки с корпоративного счёта.",
    status: "open",
    priority: "medium",
    department: "billing",
    customerId: "cust-2",
    customerName: "Елена Новикова",
    customerEmail: "elena@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Elena",
    assignedTo: "agent-2",
    assignedName: "Мария Иванова",
    assignedAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Maria",
    createdAt: new Date("2024-01-14T15:20:00"),
    updatedAt: new Date("2024-01-15T09:15:00"),
    lastMessageAt: new Date("2024-01-15T09:15:00"),
    messagesCount: 4,
    tags: ["оплата", "подписка"],
  },
  {
    id: "TK-003",
    subject: "Ошибка при экспорте отчётов",
    description: "При попытке экспортировать отчёт в Excel выдаёт ошибку 500.",
    status: "pending",
    priority: "high",
    department: "technical",
    customerId: "cust-3",
    customerName: "Сергей Волков",
    customerEmail: "sergey@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Sergey",
    assignedTo: "agent-1",
    assignedName: "Алексей Петров",
    assignedAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Alexey",
    createdAt: new Date("2024-01-13T11:45:00"),
    updatedAt: new Date("2024-01-15T08:00:00"),
    lastMessageAt: new Date("2024-01-15T08:00:00"),
    messagesCount: 6,
    tags: ["экспорт", "баг"],
  },
  {
    id: "TK-004",
    subject: "Запрос на демо продукта",
    description: "Наша компания заинтересована в вашем решении. Можем ли мы организовать демонстрацию?",
    status: "open",
    priority: "medium",
    department: "sales",
    customerId: "cust-4",
    customerName: "Ольга Морозова",
    customerEmail: "olga@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Olga",
    assignedTo: "agent-3",
    assignedName: "Дмитрий Сидоров",
    assignedAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Dmitry",
    createdAt: new Date("2024-01-12T14:00:00"),
    updatedAt: new Date("2024-01-14T16:30:00"),
    lastMessageAt: new Date("2024-01-14T16:30:00"),
    messagesCount: 3,
    tags: ["демо", "продажи"],
  },
  {
    id: "TK-005",
    subject: "Проблема с загрузкой файлов",
    description: "Не могу загрузить файлы больше 10MB, хотя лимит должен быть 50MB.",
    status: "resolved",
    priority: "low",
    department: "technical",
    customerId: "cust-5",
    customerName: "Андрей Лебедев",
    customerEmail: "andrey@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Andrey",
    assignedTo: "agent-1",
    assignedName: "Алексей Петров",
    assignedAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Alexey",
    createdAt: new Date("2024-01-10T09:00:00"),
    updatedAt: new Date("2024-01-14T11:00:00"),
    lastMessageAt: new Date("2024-01-14T11:00:00"),
    messagesCount: 8,
    tags: ["загрузка", "файлы"],
  },
  {
    id: "TK-006",
    subject: "Медленная работа интерфейса",
    description: "Последние дни интерфейс работает очень медленно, особенно при работе с таблицами.",
    status: "new",
    priority: "high",
    department: "technical",
    customerId: "cust-1",
    customerName: "Иван Смирнов",
    customerEmail: "ivan@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Ivan",
    createdAt: new Date("2024-01-15T11:00:00"),
    updatedAt: new Date("2024-01-15T11:00:00"),
    lastMessageAt: new Date("2024-01-15T11:00:00"),
    messagesCount: 1,
    tags: ["производительность"],
  },
  {
    id: "TK-007",
    subject: "Возврат средств за январь",
    description: "Прошу оформить возврат средств за неиспользованный период подписки.",
    status: "closed",
    priority: "medium",
    department: "billing",
    customerId: "cust-2",
    customerName: "Елена Новикова",
    customerEmail: "elena@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Elena",
    assignedTo: "agent-2",
    assignedName: "Мария Иванова",
    assignedAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Maria",
    createdAt: new Date("2024-01-05T10:00:00"),
    updatedAt: new Date("2024-01-10T15:00:00"),
    lastMessageAt: new Date("2024-01-10T15:00:00"),
    messagesCount: 5,
    tags: ["возврат"],
  },
  {
    id: "TK-008",
    subject: "Интеграция с CRM системой",
    description: "Возможна ли интеграция вашего продукта с Bitrix24?",
    status: "pending",
    priority: "medium",
    department: "sales",
    customerId: "cust-3",
    customerName: "Сергей Волков",
    customerEmail: "sergey@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Sergey",
    assignedTo: "agent-3",
    assignedName: "Дмитрий Сидоров",
    assignedAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Dmitry",
    createdAt: new Date("2024-01-11T13:30:00"),
    updatedAt: new Date("2024-01-14T10:00:00"),
    lastMessageAt: new Date("2024-01-14T10:00:00"),
    messagesCount: 4,
    tags: ["интеграция", "CRM"],
  },
  {
    id: "TK-009",
    subject: "Ошибка 404 на странице настроек",
    description: "При переходе в раздел настроек профиля появляется ошибка 404.",
    status: "open",
    priority: "high",
    department: "technical",
    customerId: "cust-4",
    customerName: "Ольга Морозова",
    customerEmail: "olga@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Olga",
    assignedTo: "agent-1",
    assignedName: "Алексей Петров",
    assignedAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Alexey",
    createdAt: new Date("2024-01-14T17:00:00"),
    updatedAt: new Date("2024-01-15T10:00:00"),
    lastMessageAt: new Date("2024-01-15T10:00:00"),
    messagesCount: 2,
    tags: ["404", "баг"],
  },
  {
    id: "TK-010",
    subject: "Вопрос по API документации",
    description: "Не могу найти документацию по REST API для интеграции.",
    status: "resolved",
    priority: "low",
    department: "general",
    customerId: "cust-5",
    customerName: "Андрей Лебедев",
    customerEmail: "andrey@example.com",
    customerAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Andrey",
    assignedTo: "agent-4",
    assignedName: "Анна Козлова",
    assignedAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Anna",
    createdAt: new Date("2024-01-08T12:00:00"),
    updatedAt: new Date("2024-01-12T14:00:00"),
    lastMessageAt: new Date("2024-01-12T14:00:00"),
    messagesCount: 3,
    tags: ["API", "документация"],
  },
]

export const messages: Message[] = [
  {
    id: "msg-1",
    ticketId: "TK-001",
    content: "Добрый день! После обновления браузера до последней версии перестала работать авторизация. Каждый раз когда ввожу логин и пароль, меня выбрасывает обратно на страницу входа. Пробовал очистить кэш - не помогло.",
    senderId: "cust-1",
    senderName: "Иван Смирнов",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Ivan",
    isAgent: false,
    createdAt: new Date("2024-01-15T10:30:00"),
  },
  {
    id: "msg-2",
    ticketId: "TK-002",
    content: "Здравствуйте! Мы рассматриваем возможность приобретения годовой подписки для нашей компании. Можем ли мы оплатить её с корпоративного счёта по безналичному расчёту?",
    senderId: "cust-2",
    senderName: "Елена Новикова",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Elena",
    isAgent: false,
    createdAt: new Date("2024-01-14T15:20:00"),
  },
  {
    id: "msg-3",
    ticketId: "TK-002",
    content: "Добрый день, Елена! Да, конечно, мы работаем с юридическими лицами по безналичному расчёту. Для оформления счёта потребуются реквизиты вашей организации.",
    senderId: "agent-2",
    senderName: "Мария Иванова",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Maria",
    isAgent: true,
    createdAt: new Date("2024-01-14T16:00:00"),
  },
  {
    id: "msg-4",
    ticketId: "TK-002",
    content: "Отлично! Высылаю реквизиты: ООО \"Инновации\", ИНН 7701234567, КПП 770101001.",
    senderId: "cust-2",
    senderName: "Елена Новикова",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Elena",
    isAgent: false,
    createdAt: new Date("2024-01-15T09:00:00"),
  },
  {
    id: "msg-5",
    ticketId: "TK-002",
    content: "Спасибо! Счёт подготовлен и отправлен на ваш email. Срок оплаты - 5 рабочих дней.",
    senderId: "agent-2",
    senderName: "Мария Иванова",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Maria",
    isAgent: true,
    createdAt: new Date("2024-01-15T09:15:00"),
  },
  {
    id: "msg-6",
    ticketId: "TK-003",
    content: "При экспорте отчёта за декабрь в формат Excel получаю ошибку 500. Прикрепляю скриншот.",
    senderId: "cust-3",
    senderName: "Сергей Волков",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Sergey",
    isAgent: false,
    createdAt: new Date("2024-01-13T11:45:00"),
  },
  {
    id: "msg-7",
    ticketId: "TK-003",
    content: "Здравствуйте, Сергей! Спасибо за информацию. Мы обнаружили проблему и передали её разработчикам. Ожидаемое время исправления - до конца дня.",
    senderId: "agent-1",
    senderName: "Алексей Петров",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Alexey",
    isAgent: true,
    createdAt: new Date("2024-01-13T14:00:00"),
  },
  {
    id: "msg-8",
    ticketId: "TK-003",
    content: "Обновление: исправление выкатили в production. Попробуйте, пожалуйста, экспортировать отчёт снова.",
    senderId: "agent-1",
    senderName: "Алексей Петров",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Alexey",
    isAgent: true,
    createdAt: new Date("2024-01-14T10:00:00"),
  },
  {
    id: "msg-9",
    ticketId: "TK-003",
    content: "Проверил - та же ошибка. Может быть, проблема в конкретном отчёте?",
    senderId: "cust-3",
    senderName: "Сергей Волков",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Sergey",
    isAgent: false,
    createdAt: new Date("2024-01-14T15:00:00"),
  },
  {
    id: "msg-10",
    ticketId: "TK-003",
    content: "Сергей, вы правы. Обнаружили специфический баг при наличии в отчёте кириллических символов. Работаем над исправлением.",
    senderId: "agent-1",
    senderName: "Алексей Петров",
    senderAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Alexey",
    isAgent: true,
    createdAt: new Date("2024-01-15T08:00:00"),
  },
]

export function getCustomerThreads(): CustomerThread[] {
  const threadMap = new Map<string, CustomerThread>()

  for (const ticket of tickets) {
    const existing = threadMap.get(ticket.customerId)
    if (existing) {
      existing.tickets.push(ticket)
      existing.totalTickets++
      if (["new", "open", "pending"].includes(ticket.status)) {
        existing.openTickets++
      }
      if (ticket.lastMessageAt > existing.lastActivity) {
        existing.lastActivity = ticket.lastMessageAt
      }
    } else {
      threadMap.set(ticket.customerId, {
        customerId: ticket.customerId,
        customerName: ticket.customerName,
        customerEmail: ticket.customerEmail,
        customerAvatar: ticket.customerAvatar,
        tickets: [ticket],
        totalTickets: 1,
        openTickets: ["new", "open", "pending"].includes(ticket.status) ? 1 : 0,
        lastActivity: ticket.lastMessageAt,
      })
    }
  }

  return Array.from(threadMap.values()).sort(
    (a, b) => b.lastActivity.getTime() - a.lastActivity.getTime()
  )
}

export function getTicketMessages(ticketId: string): Message[] {
  return messages
    .filter((m) => m.ticketId === ticketId)
    .sort((a, b) => a.createdAt.getTime() - b.createdAt.getTime())
}

// Quick Replies / Templates
export const quickReplies: QuickReply[] = [
  {
    id: "qr-1",
    title: "Приветствие",
    content: "Добрый день! Благодарим за обращение в службу поддержки. Меня зовут {agent_name}, и я помогу вам решить ваш вопрос.",
    category: "Общие",
    shortcut: "/hi",
  },
  {
    id: "qr-2",
    title: "Уточнение деталей",
    content: "Для того чтобы мы могли помочь вам максимально быстро, пожалуйста, уточните следующие детали:\n\n1. Когда именно возникла проблема?\n2. Какие действия вы выполняли?\n3. Есть ли сообщение об ошибке?",
    category: "Техподдержка",
    shortcut: "/details",
  },
  {
    id: "qr-3",
    title: "Проблема решена",
    content: "Рады сообщить, что ваша проблема успешно решена! Если у вас возникнут дополнительные вопросы, не стесняйтесь обращаться к нам.",
    category: "Завершение",
    shortcut: "/solved",
  },
  {
    id: "qr-4",
    title: "Передача специалисту",
    content: "Благодарю за ожидание. Ваш запрос требует дополнительной экспертизы, поэтому я передаю его нашему специалисту. Он свяжется с вами в ближайшее время.",
    category: "Эскалация",
    shortcut: "/escalate",
  },
  {
    id: "qr-5",
    title: "Запрос скриншота",
    content: "Для более точной диагностики проблемы, пожалуйста, приложите скриншот ошибки или видео, демонстрирующее проблему.",
    category: "Техподдержка",
    shortcut: "/screen",
  },
  {
    id: "qr-6",
    title: "Информация о платеже",
    content: "Для оформления счёта нам потребуются следующие реквизиты:\n- Полное наименование организации\n- ИНН\n- КПП\n- Юридический адрес\n- Банковские реквизиты",
    category: "Биллинг",
    shortcut: "/invoice",
  },
  {
    id: "qr-7",
    title: "Возврат средств",
    content: "Заявка на возврат средств принята. Обработка займёт 3-5 рабочих дней. Средства будут возвращены тем же способом, которым была произведена оплата.",
    category: "Биллинг",
    shortcut: "/refund",
  },
  {
    id: "qr-8",
    title: "Очистка кэша",
    content: "Попробуйте выполнить следующие шаги:\n\n1. Откройте настройки браузера\n2. Перейдите в раздел «Конфиденциальность и безопасность»\n3. Выберите «Очистить данные просмотра»\n4. Отметьте «Файлы cookie» и «Кэшированные изображения и файлы»\n5. Нажмите «Удалить данные»\n6. Перезагрузите страницу",
    category: "Техподдержка",
    shortcut: "/cache",
  },
]

// Internal Notes
export const internalNotes: InternalNote[] = [
  {
    id: "note-1",
    ticketId: "TK-001",
    content: "Клиент использует Firefox 120. Возможно, проблема связана с новыми security policies. Нужно проверить с разработчиками.",
    authorId: "agent-1",
    authorName: "Алексей Петров",
    authorAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Alexey",
    createdAt: new Date("2024-01-15T11:00:00"),
  },
  {
    id: "note-2",
    ticketId: "TK-003",
    content: "Баг подтверждён. Проблема в библиотеке экспорта при обработке UTF-8. Задача создана в Jira: TECH-1234",
    authorId: "agent-1",
    authorName: "Алексей Петров",
    authorAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Alexey",
    createdAt: new Date("2024-01-14T09:30:00"),
  },
  {
    id: "note-3",
    ticketId: "TK-002",
    content: "VIP клиент, крупный заказ. Предложить скидку 15% на годовую подписку.",
    authorId: "agent-4",
    authorName: "Анна Козлова",
    authorAvatar: "https://api.dicebear.com/7.x/avataaars/svg?seed=Anna",
    createdAt: new Date("2024-01-14T16:30:00"),
  },
]

// Knowledge Base Articles
export const knowledgeArticles: KnowledgeArticle[] = [
  {
    id: "kb-1",
    title: "Как очистить кэш браузера",
    content: `# Очистка кэша браузера

Кэш браузера может вызывать различные проблемы с отображением и работой сайта. Вот как его очистить в разных браузерах:

## Google Chrome
1. Нажмите Ctrl+Shift+Delete (Windows) или Cmd+Shift+Delete (Mac)
2. Выберите временной диапазон "Все время"
3. Отметьте "Файлы cookie" и "Кэшированные изображения"
4. Нажмите "Удалить данные"

## Firefox
1. Нажмите Ctrl+Shift+Delete
2. Выберите "Всё" в выпадающем списке
3. Отметьте нужные пункты
4. Нажмите "Удалить сейчас"

## Safari
1. Откройте Safari > Настройки > Конфиденциальность
2. Нажмите "Управление данными сайтов"
3. Нажмите "Удалить всё"`,
    category: "Браузер",
    tags: ["кэш", "браузер", "очистка", "проблемы"],
    views: 1523,
    helpful: 342,
    createdAt: new Date("2023-06-01"),
    updatedAt: new Date("2024-01-10"),
  },
  {
    id: "kb-2",
    title: "Решение проблем с авторизацией",
    content: `# Проблемы с авторизацией

## Частые причины

### 1. Неверный пароль
- Проверьте раскладку клавиатуры
- Убедитесь, что Caps Lock выключен
- Воспользуйтесь функцией "Забыли пароль?"

### 2. Проблемы с cookies
- Убедитесь, что cookies включены в браузере
- Очистите cookies для нашего сайта
- Попробуйте режим инкогнито

### 3. Двухфакторная аутентификация
- Проверьте синхронизацию времени на устройстве
- Используйте резервные коды при необходимости

## Если ничего не помогает
Обратитесь в поддержку с указанием:
- Email аккаунта
- Скриншот ошибки
- Используемый браузер`,
    category: "Аккаунт",
    tags: ["авторизация", "вход", "пароль", "2FA"],
    views: 2891,
    helpful: 567,
    createdAt: new Date("2023-05-15"),
    updatedAt: new Date("2024-01-05"),
  },
  {
    id: "kb-3",
    title: "Экспорт данных в Excel",
    content: `# Экспорт данных в Excel

## Поддерживаемые форматы
- XLSX (рекомендуется)
- CSV
- PDF

## Как экспортировать
1. Откройте раздел "Отчёты"
2. Выберите нужный отчёт
3. Нажмите кнопку "Экспорт"
4. Выберите формат

## Ограничения
- Максимум 100,000 строк за раз
- Файлы больше 50MB разбиваются на части

## Возможные проблемы
### Ошибка при экспорте
- Уменьшите диапазон дат
- Попробуйте формат CSV
- Проверьте наличие специальных символов в данных`,
    category: "Отчёты",
    tags: ["экспорт", "Excel", "CSV", "отчёты"],
    views: 987,
    helpful: 234,
    createdAt: new Date("2023-08-20"),
    updatedAt: new Date("2024-01-12"),
  },
  {
    id: "kb-4",
    title: "Настройка интеграции с CRM",
    content: `# Интеграция с CRM системами

## Поддерживаемые системы
- Bitrix24
- amoCRM
- Salesforce
- HubSpot

## Настройка Bitrix24
1. Получите webhook URL в настройках Bitrix24
2. Перейдите в Интеграции > Bitrix24
3. Вставьте webhook URL
4. Настройте маппинг полей
5. Активируйте интеграцию

## Синхронизация данных
- Контакты синхронизируются каждые 15 минут
- Сделки - в реальном времени
- Задачи - каждый час`,
    category: "Интеграции",
    tags: ["CRM", "интеграция", "Bitrix24", "API"],
    views: 654,
    helpful: 156,
    createdAt: new Date("2023-09-10"),
    updatedAt: new Date("2024-01-08"),
  },
  {
    id: "kb-5",
    title: "Политика возврата средств",
    content: `# Политика возврата

## Условия возврата
- Возврат возможен в течение 14 дней с момента оплаты
- Для годовой подписки - пропорциональный возврат за неиспользованный период

## Как оформить возврат
1. Создайте тикет в категории "Биллинг"
2. Укажите причину возврата
3. Приложите данные платежа

## Сроки обработки
- Рассмотрение заявки: 1-2 рабочих дня
- Возврат на карту: 3-5 рабочих дней
- Возврат по безналу: 5-7 рабочих дней`,
    category: "Биллинг",
    tags: ["возврат", "оплата", "подписка"],
    views: 432,
    helpful: 98,
    createdAt: new Date("2023-07-01"),
    updatedAt: new Date("2023-12-15"),
  },
]

// Notifications
export const initialNotifications: Notification[] = [
  {
    id: "notif-1",
    type: "new_ticket",
    title: "Новый тикет",
    message: "Иван Смирнов создал тикет TK-001",
    ticketId: "TK-001",
    isRead: false,
    createdAt: new Date("2024-01-15T10:30:00"),
  },
  {
    id: "notif-2",
    type: "new_message",
    title: "Новое сообщение",
    message: "Елена Новикова ответила в TK-002",
    ticketId: "TK-002",
    isRead: false,
    createdAt: new Date("2024-01-15T09:15:00"),
  },
  {
    id: "notif-3",
    type: "assignment",
    title: "Назначение",
    message: "Вам назначен тикет TK-009",
    ticketId: "TK-009",
    isRead: false,
    createdAt: new Date("2024-01-14T17:30:00"),
  },
  {
    id: "notif-4",
    type: "status_change",
    title: "Смена статуса",
    message: "TK-005 изменён на 'Решён'",
    ticketId: "TK-005",
    isRead: true,
    createdAt: new Date("2024-01-14T11:00:00"),
  },
  {
    id: "notif-5",
    type: "mention",
    title: "Упоминание",
    message: "Алексей упомянул вас в заметке к TK-003",
    ticketId: "TK-003",
    isRead: true,
    createdAt: new Date("2024-01-14T09:30:00"),
  },
]

export function getTicketNotes(ticketId: string): InternalNote[] {
  return internalNotes
    .filter((n) => n.ticketId === ticketId)
    .sort((a, b) => b.createdAt.getTime() - a.createdAt.getTime())
}

export function searchKnowledgeBase(query: string): KnowledgeArticle[] {
  const lowerQuery = query.toLowerCase()
  return knowledgeArticles.filter(
    (article) =>
      article.title.toLowerCase().includes(lowerQuery) ||
      article.content.toLowerCase().includes(lowerQuery) ||
      article.tags.some((tag) => tag.toLowerCase().includes(lowerQuery))
  )
}

export function getQuickRepliesByCategory(category?: string): QuickReply[] {
  if (!category) return quickReplies
  return quickReplies.filter((qr) => qr.category === category)
}
