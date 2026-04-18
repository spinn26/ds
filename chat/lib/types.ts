export type TicketStatus = "new" | "open" | "pending" | "resolved" | "closed"
export type TicketPriority = "critical" | "high" | "medium" | "low"
export type Department = "technical" | "billing" | "sales" | "general"

export interface User {
  id: string
  name: string
  email: string
  avatar?: string
  role: "admin" | "agent" | "customer"
  department?: Department
}

export interface Message {
  id: string
  ticketId: string
  content: string
  senderId: string
  senderName: string
  senderAvatar?: string
  isAgent: boolean
  createdAt: Date
  attachments?: string[]
}

export interface Ticket {
  id: string
  subject: string
  description: string
  status: TicketStatus
  priority: TicketPriority
  department: Department
  customerId: string
  customerName: string
  customerEmail: string
  customerAvatar?: string
  assignedTo?: string
  assignedName?: string
  assignedAvatar?: string
  createdAt: Date
  updatedAt: Date
  lastMessageAt: Date
  messagesCount: number
  tags?: string[]
}

export interface CustomerThread {
  customerId: string
  customerName: string
  customerEmail: string
  customerAvatar?: string
  tickets: Ticket[]
  totalTickets: number
  openTickets: number
  lastActivity: Date
}

export interface InternalNote {
  id: string
  ticketId: string
  content: string
  authorId: string
  authorName: string
  authorAvatar?: string
  createdAt: Date
}

export interface QuickReply {
  id: string
  title: string
  content: string
  category: string
  shortcut?: string
}

export interface KnowledgeArticle {
  id: string
  title: string
  content: string
  category: string
  tags: string[]
  views: number
  helpful: number
  createdAt: Date
  updatedAt: Date
}

export interface Notification {
  id: string
  type: "new_ticket" | "new_message" | "status_change" | "assignment" | "mention"
  title: string
  message: string
  ticketId?: string
  isRead: boolean
  createdAt: Date
}

export const statusLabels: Record<TicketStatus, string> = {
  new: "Новый",
  open: "Открыт",
  pending: "Ожидание",
  resolved: "Решён",
  closed: "Закрыт",
}

export const priorityLabels: Record<TicketPriority, string> = {
  critical: "Критический",
  high: "Высокий",
  medium: "Средний",
  low: "Низкий",
}

export const departmentLabels: Record<Department, string> = {
  technical: "Техподдержка",
  billing: "Биллинг",
  sales: "Продажи",
  general: "Общие вопросы",
}
