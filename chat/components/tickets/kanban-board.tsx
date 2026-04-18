"use client"

import { useState } from "react"
import { cn } from "@/lib/utils"
import type { Ticket, TicketStatus } from "@/lib/types"
import { statusLabels, priorityLabels } from "@/lib/types"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
import { ScrollArea } from "@/components/ui/scroll-area"
import {
  AlertCircle,
  ArrowUp,
  ArrowRight,
  ArrowDown,
  Clock,
  MessageSquare,
} from "lucide-react"

interface KanbanBoardProps {
  tickets: Ticket[]
  onTicketClick: (ticket: Ticket) => void
  onStatusChange: (ticketId: string, newStatus: TicketStatus) => void
}

const statusColumns: TicketStatus[] = ["new", "open", "pending", "resolved", "closed"]

const statusColors: Record<TicketStatus, string> = {
  new: "bg-[var(--status-new)]",
  open: "bg-[var(--status-open)]",
  pending: "bg-[var(--status-pending)]",
  resolved: "bg-[var(--status-resolved)]",
  closed: "bg-[var(--status-closed)]",
}

const priorityIcons = {
  critical: <AlertCircle className="h-3.5 w-3.5 text-[var(--priority-critical)]" />,
  high: <ArrowUp className="h-3.5 w-3.5 text-[var(--priority-high)]" />,
  medium: <ArrowRight className="h-3.5 w-3.5 text-[var(--priority-medium)]" />,
  low: <ArrowDown className="h-3.5 w-3.5 text-[var(--priority-low)]" />,
}

export function KanbanBoard({ tickets, onTicketClick, onStatusChange }: KanbanBoardProps) {
  const [draggedTicket, setDraggedTicket] = useState<Ticket | null>(null)
  const [dragOverColumn, setDragOverColumn] = useState<TicketStatus | null>(null)

  const getTicketsByStatus = (status: TicketStatus) =>
    tickets.filter((t) => t.status === status)

  const handleDragStart = (ticket: Ticket) => {
    setDraggedTicket(ticket)
  }

  const handleDragOver = (e: React.DragEvent, status: TicketStatus) => {
    e.preventDefault()
    setDragOverColumn(status)
  }

  const handleDragLeave = () => {
    setDragOverColumn(null)
  }

  const handleDrop = (status: TicketStatus) => {
    if (draggedTicket && draggedTicket.status !== status) {
      onStatusChange(draggedTicket.id, status)
    }
    setDraggedTicket(null)
    setDragOverColumn(null)
  }

  const formatDate = (date: Date) => {
    const now = new Date()
    const diff = now.getTime() - date.getTime()
    const hours = Math.floor(diff / (1000 * 60 * 60))
    const days = Math.floor(hours / 24)

    if (hours < 1) return "только что"
    if (hours < 24) return `${hours}ч назад`
    if (days < 7) return `${days}д назад`
    return date.toLocaleDateString("ru-RU", { day: "numeric", month: "short" })
  }

  return (
    <div className="flex gap-4 h-full overflow-x-auto pb-4">
      {statusColumns.map((status) => {
        const columnTickets = getTicketsByStatus(status)
        const isDropTarget = dragOverColumn === status

        return (
          <div
            key={status}
            className={cn(
              "flex-shrink-0 w-72 flex flex-col rounded-lg transition-colors",
              isDropTarget && "bg-secondary/50"
            )}
            onDragOver={(e) => handleDragOver(e, status)}
            onDragLeave={handleDragLeave}
            onDrop={() => handleDrop(status)}
          >
            {/* Column Header */}
            <div className="flex items-center gap-2 px-3 py-2 mb-2">
              <div className={cn("w-2.5 h-2.5 rounded-full", statusColors[status])} />
              <span className="font-medium text-sm">{statusLabels[status]}</span>
              <Badge variant="secondary" className="ml-auto text-xs">
                {columnTickets.length}
              </Badge>
            </div>

            {/* Column Content */}
            <ScrollArea className="flex-1">
              <div className="flex flex-col gap-2 px-1">
                {columnTickets.map((ticket) => (
                  <Card
                    key={ticket.id}
                    draggable
                    onDragStart={() => handleDragStart(ticket)}
                    onClick={() => onTicketClick(ticket)}
                    className={cn(
                      "p-3 cursor-pointer transition-all hover:border-accent/50",
                      "bg-card hover:bg-secondary/30",
                      draggedTicket?.id === ticket.id && "opacity-50"
                    )}
                  >
                    {/* Ticket Header */}
                    <div className="flex items-start justify-between gap-2 mb-2">
                      <span className="text-xs text-muted-foreground font-mono">
                        {ticket.id}
                      </span>
                      <div className="flex items-center gap-1">
                        {priorityIcons[ticket.priority]}
                      </div>
                    </div>

                    {/* Ticket Subject */}
                    <h4 className="text-sm font-medium leading-tight mb-2 line-clamp-2 text-foreground">
                      {ticket.subject}
                    </h4>

                    {/* Tags */}
                    {ticket.tags && ticket.tags.length > 0 && (
                      <div className="flex flex-wrap gap-1 mb-3">
                        {ticket.tags.slice(0, 2).map((tag) => (
                          <Badge
                            key={tag}
                            variant="outline"
                            className="text-[10px] px-1.5 py-0 h-5"
                          >
                            {tag}
                          </Badge>
                        ))}
                      </div>
                    )}

                    {/* Ticket Footer */}
                    <div className="flex items-center justify-between mt-auto pt-2 border-t border-border/50">
                      <div className="flex items-center gap-2">
                        <Avatar className="h-5 w-5">
                          <AvatarImage src={ticket.customerAvatar} />
                          <AvatarFallback className="text-[10px] bg-secondary text-secondary-foreground">
                            {ticket.customerName.charAt(0)}
                          </AvatarFallback>
                        </Avatar>
                        <span className="text-xs text-muted-foreground truncate max-w-[80px]">
                          {ticket.customerName.split(" ")[0]}
                        </span>
                      </div>
                      <div className="flex items-center gap-2 text-muted-foreground">
                        <div className="flex items-center gap-1">
                          <MessageSquare className="h-3 w-3" />
                          <span className="text-xs">{ticket.messagesCount}</span>
                        </div>
                        <div className="flex items-center gap-1">
                          <Clock className="h-3 w-3" />
                          <span className="text-xs">{formatDate(ticket.lastMessageAt)}</span>
                        </div>
                      </div>
                    </div>

                    {/* Assigned Agent */}
                    {ticket.assignedName && (
                      <div className="flex items-center gap-1.5 mt-2 pt-2 border-t border-border/50">
                        <Avatar className="h-4 w-4">
                          <AvatarImage src={ticket.assignedAvatar} />
                          <AvatarFallback className="text-[8px] bg-accent text-accent-foreground">
                            {ticket.assignedName.charAt(0)}
                          </AvatarFallback>
                        </Avatar>
                        <span className="text-[10px] text-muted-foreground">
                          {ticket.assignedName}
                        </span>
                      </div>
                    )}
                  </Card>
                ))}

                {columnTickets.length === 0 && (
                  <div className="text-center py-8 text-muted-foreground text-sm">
                    Нет тикетов
                  </div>
                )}
              </div>
            </ScrollArea>
          </div>
        )
      })}
    </div>
  )
}
