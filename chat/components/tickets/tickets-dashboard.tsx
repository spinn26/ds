"use client"

import { useState, useMemo } from "react"
import type { Ticket, TicketStatus, TicketPriority, Department, CustomerThread, InternalNote } from "@/lib/types"
import { tickets as initialTickets, getCustomerThreads, getTicketMessages, internalNotes as initialNotes } from "@/lib/mock-data"
import { KanbanBoard } from "./kanban-board"
import { TicketList } from "./ticket-list"
import { ChatPanel } from "./chat-panel"
import { CustomerThreads } from "./customer-threads"
import { NotificationsPanel } from "./notifications-panel"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
  DropdownMenuLabel,
} from "@/components/ui/dropdown-menu"
import { 
  LayoutGrid, 
  List, 
  Users, 
  Filter,
  ChevronDown,
  Search,
  AlertCircle,
  ArrowUp,
  ArrowRight,
  ArrowDown,
  Building2,
} from "lucide-react"

export type ViewMode = "kanban" | "list" | "customers"

interface ActiveFilter {
  type: "status" | "priority" | "department"
  value: string
  label: string
}

interface TicketsDashboardProps {
  /** Initial view mode */
  defaultView?: ViewMode
  /** Show search bar */
  showSearch?: boolean
  /** Show notifications */
  showNotifications?: boolean
  /** Custom class for the container */
  className?: string
  /** Callback when ticket is selected */
  onTicketSelect?: (ticket: Ticket | null) => void
  /** Callback when status changes */
  onStatusChange?: (ticketId: string, newStatus: TicketStatus) => void
}

const statusOptions: TicketStatus[] = ["new", "open", "pending", "resolved", "closed"]
const priorityOptions: TicketPriority[] = ["critical", "high", "medium", "low"]
const departmentOptions: Department[] = ["technical", "billing", "sales", "general"]

const statusLabels: Record<TicketStatus, string> = {
  new: "Новый",
  open: "Открыт",
  pending: "Ожидание",
  resolved: "Решён",
  closed: "Закрыт",
}

const priorityLabels: Record<TicketPriority, string> = {
  critical: "Критический",
  high: "Высокий",
  medium: "Средний",
  low: "Низкий",
}

const departmentLabels: Record<Department, string> = {
  technical: "Техподдержка",
  billing: "Биллинг",
  sales: "Продажи",
  general: "Общие",
}

export function TicketsDashboard({
  defaultView = "kanban",
  showSearch = true,
  showNotifications = true,
  className = "",
  onTicketSelect,
  onStatusChange: externalStatusChange,
}: TicketsDashboardProps) {
  const [viewMode, setViewMode] = useState<ViewMode>(defaultView)
  const [tickets, setTickets] = useState<Ticket[]>(initialTickets)
  const [selectedTicket, setSelectedTicket] = useState<Ticket | null>(null)
  const [selectedThread, setSelectedThread] = useState<CustomerThread | null>(null)
  const [notes, setNotes] = useState<InternalNote[]>(initialNotes)
  const [activeFilter, setActiveFilter] = useState<ActiveFilter | null>(null)
  const [searchQuery, setSearchQuery] = useState("")

  // Get customer threads
  const customerThreads = useMemo(() => getCustomerThreads(tickets), [tickets])

  // Filter tickets
  const filteredTickets = useMemo(() => {
    let result = tickets

    // Apply search
    if (searchQuery) {
      const query = searchQuery.toLowerCase()
      result = result.filter(
        (t) =>
          t.subject.toLowerCase().includes(query) ||
          t.customerName.toLowerCase().includes(query) ||
          t.id.toLowerCase().includes(query)
      )
    }

    // Apply filter
    if (activeFilter) {
      switch (activeFilter.type) {
        case "status":
          result = result.filter((t) => t.status === activeFilter.value)
          break
        case "priority":
          result = result.filter((t) => t.priority === activeFilter.value)
          break
        case "department":
          result = result.filter((t) => t.department === activeFilter.value)
          break
      }
    }

    return result
  }, [tickets, activeFilter, searchQuery])

  // Get messages for selected ticket
  const ticketMessages = selectedTicket ? getTicketMessages(selectedTicket.id) : []
  
  // Get notes for selected ticket
  const ticketNotes = selectedTicket
    ? notes.filter((n) => n.ticketId === selectedTicket.id)
    : []

  // Handle ticket selection
  const handleTicketSelect = (ticket: Ticket | null) => {
    setSelectedTicket(ticket)
    onTicketSelect?.(ticket)
  }

  // Handle status change
  const handleStatusChange = (ticketId: string, newStatus: TicketStatus) => {
    setTickets((prev) =>
      prev.map((t) => (t.id === ticketId ? { ...t, status: newStatus } : t))
    )
    if (selectedTicket?.id === ticketId) {
      setSelectedTicket((prev) => (prev ? { ...prev, status: newStatus } : null))
    }
    externalStatusChange?.(ticketId, newStatus)
  }

  // Handle send message
  const handleSendMessage = (ticketId: string, content: string) => {
    console.log("[v0] Sending message to ticket:", ticketId, content)
  }

  // Handle add internal note
  const handleAddNote = (ticketId: string, content: string) => {
    const newNote: InternalNote = {
      id: `note-${Date.now()}`,
      ticketId,
      content,
      authorId: "agent-4",
      authorName: "Текущий пользователь",
      createdAt: new Date(),
    }
    setNotes((prev) => [newNote, ...prev])
  }

  // Handle notification click
  const handleNotificationTicketClick = (ticketId: string) => {
    const ticket = tickets.find((t) => t.id === ticketId)
    if (ticket) {
      handleTicketSelect(ticket)
      setViewMode("list")
    }
  }

  // Clear filter
  const clearFilter = () => setActiveFilter(null)

  // Stats
  const stats = {
    total: tickets.length,
    new: tickets.filter((t) => t.status === "new").length,
    open: tickets.filter((t) => t.status === "open").length,
    pending: tickets.filter((t) => t.status === "pending").length,
  }

  return (
    <div className={`flex flex-col h-full bg-background ${className}`}>
      {/* Toolbar */}
      <div className="flex items-center justify-between p-4 border-b border-border bg-card">
        <div className="flex items-center gap-3">
          {/* View Switcher */}
          <div className="flex items-center bg-secondary rounded-lg p-1">
            <Button
              variant={viewMode === "kanban" ? "default" : "ghost"}
              size="sm"
              onClick={() => setViewMode("kanban")}
              className="h-7 px-3"
            >
              <LayoutGrid className="h-4 w-4 mr-1.5" />
              Канбан
            </Button>
            <Button
              variant={viewMode === "list" ? "default" : "ghost"}
              size="sm"
              onClick={() => setViewMode("list")}
              className="h-7 px-3"
            >
              <List className="h-4 w-4 mr-1.5" />
              Список
            </Button>
            <Button
              variant={viewMode === "customers" ? "default" : "ghost"}
              size="sm"
              onClick={() => setViewMode("customers")}
              className="h-7 px-3"
            >
              <Users className="h-4 w-4 mr-1.5" />
              Клиенты
            </Button>
          </div>

          {/* Filters */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm" className="h-8">
                <Filter className="h-4 w-4 mr-1.5" />
                Фильтры
                <ChevronDown className="h-3 w-3 ml-1.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48">
              <DropdownMenuLabel>По статусу</DropdownMenuLabel>
              {statusOptions.map((status) => (
                <DropdownMenuItem
                  key={status}
                  onClick={() => setActiveFilter({ type: "status", value: status, label: statusLabels[status] })}
                >
                  {statusLabels[status]}
                </DropdownMenuItem>
              ))}
              <DropdownMenuSeparator />
              <DropdownMenuLabel>По приоритету</DropdownMenuLabel>
              {priorityOptions.map((priority) => (
                <DropdownMenuItem
                  key={priority}
                  onClick={() => setActiveFilter({ type: "priority", value: priority, label: priorityLabels[priority] })}
                >
                  <span className="flex items-center gap-2">
                    {priority === "critical" && <AlertCircle className="h-3 w-3 text-red-500" />}
                    {priority === "high" && <ArrowUp className="h-3 w-3 text-orange-500" />}
                    {priority === "medium" && <ArrowRight className="h-3 w-3 text-yellow-500" />}
                    {priority === "low" && <ArrowDown className="h-3 w-3 text-green-500" />}
                    {priorityLabels[priority]}
                  </span>
                </DropdownMenuItem>
              ))}
              <DropdownMenuSeparator />
              <DropdownMenuLabel>По отделу</DropdownMenuLabel>
              {departmentOptions.map((dept) => (
                <DropdownMenuItem
                  key={dept}
                  onClick={() => setActiveFilter({ type: "department", value: dept, label: departmentLabels[dept] })}
                >
                  <Building2 className="h-3 w-3 mr-2" />
                  {departmentLabels[dept]}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>

          {/* Active Filter Badge */}
          {activeFilter && (
            <Badge variant="secondary" className="h-7 gap-1.5">
              {activeFilter.label}
              <button onClick={clearFilter} className="ml-1 hover:text-foreground">
                ×
              </button>
            </Badge>
          )}

          {/* Stats */}
          <div className="flex items-center gap-2 text-xs text-muted-foreground ml-2">
            <span>Всего: {stats.total}</span>
            <span className="text-border">|</span>
            <span className="text-blue-400">Новых: {stats.new}</span>
            <span className="text-border">|</span>
            <span className="text-yellow-400">Открытых: {stats.open}</span>
          </div>
        </div>

        <div className="flex items-center gap-3">
          {/* Search */}
          {showSearch && (
            <div className="relative w-64">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Поиск тикетов..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-8 h-8 bg-secondary border-0"
              />
            </div>
          )}

          {/* Notifications */}
          {showNotifications && (
            <NotificationsPanel onTicketClick={handleNotificationTicketClick} />
          )}
        </div>
      </div>

      {/* Content */}
      <div className="flex-1 flex min-h-0">
        {/* Kanban View */}
        {viewMode === "kanban" && (
          <>
            <div className="flex-1 min-w-0">
              <KanbanBoard
                tickets={filteredTickets}
                onTicketClick={handleTicketSelect}
                onStatusChange={handleStatusChange}
                selectedTicketId={selectedTicket?.id}
              />
            </div>
            {selectedTicket && (
              <div className="w-[400px] flex-shrink-0 border-l border-border">
                <ChatPanel
                  ticket={selectedTicket}
                  messages={ticketMessages}
                  notes={ticketNotes}
                  onClose={() => handleTicketSelect(null)}
                  onStatusChange={handleStatusChange}
                  onSendMessage={handleSendMessage}
                  onAddNote={handleAddNote}
                />
              </div>
            )}
          </>
        )}

        {/* List View */}
        {viewMode === "list" && (
          <>
            <div className="w-96 border-r border-border flex-shrink-0">
              <TicketList
                tickets={filteredTickets}
                selectedTicketId={selectedTicket?.id}
                onTicketClick={handleTicketSelect}
                groupBy={activeFilter?.type === "priority" ? "priority" : activeFilter?.type === "department" ? "department" : "none"}
              />
            </div>
            <div className="flex-1">
              <ChatPanel
                ticket={selectedTicket}
                messages={ticketMessages}
                notes={ticketNotes}
                onClose={() => handleTicketSelect(null)}
                onStatusChange={handleStatusChange}
                onSendMessage={handleSendMessage}
                onAddNote={handleAddNote}
              />
            </div>
          </>
        )}

        {/* Customers View */}
        {viewMode === "customers" && (
          <CustomerThreads
            threads={customerThreads}
            selectedThread={selectedThread}
            onThreadClick={setSelectedThread}
            onTicketClick={(ticket) => {
              handleTicketSelect(ticket)
              setViewMode("list")
            }}
          />
        )}
      </div>
    </div>
  )
}
