// Main Dashboard Component - use this to embed the full ticket system
export { TicketsDashboard } from "./tickets-dashboard"
export type { ViewMode } from "./tickets-dashboard"

// Individual Components - use these if you need more control
export { KanbanBoard } from "./kanban-board"
export { TicketList } from "./ticket-list"
export { ChatPanel } from "./chat-panel"
export { CustomerThreads } from "./customer-threads"
export { NotificationsPanel } from "./notifications-panel"
export { QuickReplies } from "./quick-replies"
export { InternalNotes } from "./internal-notes"
export { KnowledgeBase } from "./knowledge-base"

// Types
export type {
  Ticket,
  TicketStatus,
  TicketPriority,
  Department,
  Message,
  User,
  CustomerThread,
  InternalNote,
  QuickReply,
  KnowledgeArticle,
  Notification,
} from "@/lib/types"

// Mock Data (for demo purposes)
export {
  tickets,
  users,
  messages,
  quickReplies,
  knowledgeArticles,
  internalNotes,
  initialNotifications,
  getTicketMessages,
  getCustomerThreads,
  getTicketNotes,
  searchKnowledgeBase,
  getQuickRepliesByCategory,
} from "@/lib/mock-data"
