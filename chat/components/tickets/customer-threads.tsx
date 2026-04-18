"use client"

import { cn } from "@/lib/utils"
import type { CustomerThread, Ticket } from "@/lib/types"
import { statusLabels, priorityLabels } from "@/lib/types"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { ScrollArea } from "@/components/ui/scroll-area"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet"
import {
  AlertCircle,
  ArrowUp,
  ArrowRight,
  ArrowDown,
  Clock,
  MessageSquare,
  Mail,
  ChevronRight,
  X,
} from "lucide-react"

interface CustomerThreadsProps {
  threads: CustomerThread[]
  selectedThread: CustomerThread | null
  onSelectThread: (thread: CustomerThread | null) => void
  onTicketClick: (ticket: Ticket) => void
}

const priorityIcons = {
  critical: <AlertCircle className="h-3.5 w-3.5 text-[var(--priority-critical)]" />,
  high: <ArrowUp className="h-3.5 w-3.5 text-[var(--priority-high)]" />,
  medium: <ArrowRight className="h-3.5 w-3.5 text-[var(--priority-medium)]" />,
  low: <ArrowDown className="h-3.5 w-3.5 text-[var(--priority-low)]" />,
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

export function CustomerThreads({
  threads,
  selectedThread,
  onSelectThread,
  onTicketClick,
}: CustomerThreadsProps) {
  return (
    <>
      <div className="flex flex-col h-full">
        {/* Header */}
        <div className="p-4 border-b border-border">
          <h2 className="text-lg font-semibold text-foreground">Клиенты</h2>
          <p className="text-sm text-muted-foreground">
            {threads.length} клиентов с обращениями
          </p>
        </div>

        {/* Threads List */}
        <ScrollArea className="flex-1">
          <div className="divide-y divide-border">
            {threads.map((thread) => (
              <div
                key={thread.customerId}
                onClick={() => onSelectThread(thread)}
                className={cn(
                  "p-4 cursor-pointer transition-colors hover:bg-secondary/50",
                  selectedThread?.customerId === thread.customerId && "bg-secondary"
                )}
              >
                <div className="flex items-start gap-3">
                  <Avatar className="h-10 w-10">
                    <AvatarImage src={thread.customerAvatar} />
                    <AvatarFallback className="bg-secondary text-secondary-foreground">
                      {thread.customerName.charAt(0)}
                    </AvatarFallback>
                  </Avatar>
                  
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between mb-1">
                      <h4 className="font-medium text-sm text-foreground truncate">
                        {thread.customerName}
                      </h4>
                      <ChevronRight className="h-4 w-4 text-muted-foreground flex-shrink-0" />
                    </div>
                    
                    <p className="text-xs text-muted-foreground mb-2 truncate">
                      {thread.customerEmail}
                    </p>
                    
                    <div className="flex items-center gap-3 text-xs">
                      <div className="flex items-center gap-1">
                        <MessageSquare className="h-3 w-3 text-muted-foreground" />
                        <span className="text-muted-foreground">
                          {thread.totalTickets} обращений
                        </span>
                      </div>
                      
                      {thread.openTickets > 0 && (
                        <Badge variant="secondary" className="text-[10px] h-5 px-1.5">
                          {thread.openTickets} открыто
                        </Badge>
                      )}
                    </div>
                    
                    <div className="flex items-center gap-1 mt-2 text-xs text-muted-foreground">
                      <Clock className="h-3 w-3" />
                      <span>Активность: {formatDate(thread.lastActivity)}</span>
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </ScrollArea>
      </div>

      {/* Thread Detail Sheet */}
      <Sheet open={!!selectedThread} onOpenChange={() => onSelectThread(null)}>
        <SheetContent side="right" className="w-full sm:max-w-lg bg-card border-border p-0">
          {selectedThread && (
            <div className="flex flex-col h-full">
              <SheetHeader className="p-4 border-b border-border">
                <div className="flex items-start gap-3">
                  <Avatar className="h-12 w-12">
                    <AvatarImage src={selectedThread.customerAvatar} />
                    <AvatarFallback className="bg-secondary text-secondary-foreground">
                      {selectedThread.customerName.charAt(0)}
                    </AvatarFallback>
                  </Avatar>
                  <div className="flex-1">
                    <SheetTitle className="text-left text-foreground">
                      {selectedThread.customerName}
                    </SheetTitle>
                    <div className="flex items-center gap-1 text-sm text-muted-foreground mt-1">
                      <Mail className="h-3.5 w-3.5" />
                      <span>{selectedThread.customerEmail}</span>
                    </div>
                  </div>
                </div>
              </SheetHeader>

              {/* Stats */}
              <div className="grid grid-cols-3 gap-2 p-4 border-b border-border">
                <Card className="p-3 bg-secondary/50">
                  <div className="text-2xl font-bold text-foreground">{selectedThread.totalTickets}</div>
                  <div className="text-xs text-muted-foreground">Всего обращений</div>
                </Card>
                <Card className="p-3 bg-secondary/50">
                  <div className="text-2xl font-bold text-accent">{selectedThread.openTickets}</div>
                  <div className="text-xs text-muted-foreground">Открыто</div>
                </Card>
                <Card className="p-3 bg-secondary/50">
                  <div className="text-2xl font-bold text-foreground">
                    {selectedThread.totalTickets - selectedThread.openTickets}
                  </div>
                  <div className="text-xs text-muted-foreground">Закрыто</div>
                </Card>
              </div>

              {/* Tickets List */}
              <div className="flex-1 overflow-hidden">
                <div className="px-4 py-2 border-b border-border">
                  <h3 className="text-sm font-medium text-foreground">История обращений</h3>
                </div>
                <ScrollArea className="h-full">
                  <div className="divide-y divide-border">
                    {selectedThread.tickets
                      .sort((a, b) => b.createdAt.getTime() - a.createdAt.getTime())
                      .map((ticket) => (
                        <div
                          key={ticket.id}
                          onClick={() => {
                            onSelectThread(null)
                            onTicketClick(ticket)
                          }}
                          className="p-4 cursor-pointer hover:bg-secondary/50 transition-colors"
                        >
                          <div className="flex items-start gap-2 mb-2">
                            <span className="text-xs text-muted-foreground font-mono">
                              {ticket.id}
                            </span>
                            <div className={cn("w-2 h-2 rounded-full mt-1", statusDots[ticket.status])} />
                            <span className="text-xs text-muted-foreground">
                              {statusLabels[ticket.status]}
                            </span>
                            <div className="ml-auto">
                              {priorityIcons[ticket.priority]}
                            </div>
                          </div>
                          
                          <h4 className="text-sm font-medium text-foreground mb-1 line-clamp-2">
                            {ticket.subject}
                          </h4>
                          
                          <div className="flex items-center gap-3 text-xs text-muted-foreground">
                            <div className="flex items-center gap-1">
                              <MessageSquare className="h-3 w-3" />
                              <span>{ticket.messagesCount}</span>
                            </div>
                            <div className="flex items-center gap-1">
                              <Clock className="h-3 w-3" />
                              <span>{formatDate(ticket.createdAt)}</span>
                            </div>
                          </div>
                        </div>
                      ))}
                  </div>
                </ScrollArea>
              </div>
            </div>
          )}
        </SheetContent>
      </Sheet>
    </>
  )
}
