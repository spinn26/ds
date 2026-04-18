"use client"

import { cn } from "@/lib/utils"
import type { Ticket, TicketPriority, Department } from "@/lib/types"
import { statusLabels, priorityLabels, departmentLabels } from "@/lib/types"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { ScrollArea } from "@/components/ui/scroll-area"
import {
  AlertCircle,
  ArrowUp,
  ArrowRight,
  ArrowDown,
  Clock,
  MessageSquare,
} from "lucide-react"

interface TicketListProps {
  tickets: Ticket[]
  selectedTicketId?: string
  onTicketClick: (ticket: Ticket) => void
  groupBy?: "priority" | "department" | "none"
}

const priorityOrder: TicketPriority[] = ["critical", "high", "medium", "low"]
const departmentOrder: Department[] = ["technical", "billing", "sales", "general"]

const priorityColors: Record<TicketPriority, string> = {
  critical: "bg-[var(--priority-critical)]",
  high: "bg-[var(--priority-high)]",
  medium: "bg-[var(--priority-medium)]",
  low: "bg-[var(--priority-low)]",
}

const priorityIcons = {
  critical: <AlertCircle className="h-4 w-4 text-[var(--priority-critical)]" />,
  high: <ArrowUp className="h-4 w-4 text-[var(--priority-high)]" />,
  medium: <ArrowRight className="h-4 w-4 text-[var(--priority-medium)]" />,
  low: <ArrowDown className="h-4 w-4 text-[var(--priority-low)]" />,
}

const statusDots: Record<string, string> = {
  new: "bg-[var(--status-new)]",
  open: "bg-[var(--status-open)]",
  pending: "bg-[var(--status-pending)]",
  resolved: "bg-[var(--status-resolved)]",
  closed: "bg-[var(--status-closed)]",
}

function formatDate(date: Date) {
  const now = new Date()
  const diff = now.getTime() - date.getTime()
  const hours = Math.floor(diff / (1000 * 60 * 60))
  const days = Math.floor(hours / 24)

  if (hours < 1) return "только что"
  if (hours < 24) return `${hours}ч назад`
  if (days < 7) return `${days}д назад`
  return date.toLocaleDateString("ru-RU", { day: "numeric", month: "short" })
}

interface TicketItemProps {
  ticket: Ticket
  isSelected: boolean
  onClick: () => void
}

function TicketItem({ ticket, isSelected, onClick }: TicketItemProps) {
  return (
    <div
      onClick={onClick}
      className={cn(
        "p-3 border-b border-border cursor-pointer transition-colors",
        "hover:bg-secondary/50",
        isSelected && "bg-secondary border-l-2 border-l-accent"
      )}
    >
      <div className="flex items-start gap-3">
        {/* Priority Indicator */}
        <div className="pt-0.5">{priorityIcons[ticket.priority]}</div>

        {/* Content */}
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 mb-1">
            <span className="text-xs text-muted-foreground font-mono">{ticket.id}</span>
            <div className={cn("w-2 h-2 rounded-full", statusDots[ticket.status])} />
            <span className="text-xs text-muted-foreground">{statusLabels[ticket.status]}</span>
          </div>

          <h4 className="text-sm font-medium text-foreground mb-1 line-clamp-1">
            {ticket.subject}
          </h4>

          <p className="text-xs text-muted-foreground line-clamp-1 mb-2">
            {ticket.description}
          </p>

          <div className="flex items-center gap-3">
            <div className="flex items-center gap-1.5">
              <Avatar className="h-5 w-5">
                <AvatarImage src={ticket.customerAvatar} />
                <AvatarFallback className="text-[10px] bg-secondary text-secondary-foreground">
                  {ticket.customerName.charAt(0)}
                </AvatarFallback>
              </Avatar>
              <span className="text-xs text-muted-foreground">
                {ticket.customerName.split(" ")[0]}
              </span>
            </div>

            <Badge variant="outline" className="text-[10px] h-5 px-1.5">
              {departmentLabels[ticket.department]}
            </Badge>

            <div className="flex items-center gap-1 text-muted-foreground ml-auto">
              <MessageSquare className="h-3 w-3" />
              <span className="text-xs">{ticket.messagesCount}</span>
            </div>

            <div className="flex items-center gap-1 text-muted-foreground">
              <Clock className="h-3 w-3" />
              <span className="text-xs">{formatDate(ticket.lastMessageAt)}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export function TicketList({
  tickets,
  selectedTicketId,
  onTicketClick,
  groupBy = "none",
}: TicketListProps) {
  const groupTickets = () => {
    if (groupBy === "priority") {
      return priorityOrder.map((priority) => ({
        key: priority,
        label: priorityLabels[priority],
        color: priorityColors[priority],
        tickets: tickets.filter((t) => t.priority === priority),
      }))
    }

    if (groupBy === "department") {
      return departmentOrder.map((dept) => ({
        key: dept,
        label: departmentLabels[dept],
        color: "bg-accent",
        tickets: tickets.filter((t) => t.department === dept),
      }))
    }

    return [{ key: "all", label: "", color: "", tickets }]
  }

  const groups = groupTickets()

  return (
    <ScrollArea className="h-full">
      {groups.map(
        (group) =>
          group.tickets.length > 0 && (
            <div key={group.key}>
              {groupBy !== "none" && (
                <div className="sticky top-0 z-10 bg-background px-3 py-2 border-b border-border flex items-center gap-2">
                  <div className={cn("w-2.5 h-2.5 rounded-full", group.color)} />
                  <span className="text-sm font-medium text-foreground">{group.label}</span>
                  <Badge variant="secondary" className="ml-auto text-xs">
                    {group.tickets.length}
                  </Badge>
                </div>
              )}
              {group.tickets.map((ticket) => (
                <TicketItem
                  key={ticket.id}
                  ticket={ticket}
                  isSelected={ticket.id === selectedTicketId}
                  onClick={() => onTicketClick(ticket)}
                />
              ))}
            </div>
          )
      )}
    </ScrollArea>
  )
}
