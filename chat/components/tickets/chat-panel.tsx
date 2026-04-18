"use client"

import { useState, useEffect, useRef } from "react"
import { cn } from "@/lib/utils"
import type { Ticket, Message, TicketStatus, InternalNote } from "@/lib/types"
import { statusLabels, priorityLabels, departmentLabels } from "@/lib/types"
import { quickReplies } from "@/lib/mock-data"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  AlertCircle,
  ArrowUp,
  ArrowRight,
  ArrowDown,
  X,
  Send,
  Paperclip,
  MoreVertical,
  User,
  Calendar,
  Tag,
} from "lucide-react"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { QuickReplies } from "./quick-replies"
import { KnowledgeBase } from "./knowledge-base"
import { InternalNotes } from "./internal-notes"

interface ChatPanelProps {
  ticket: Ticket | null
  messages: Message[]
  notes: InternalNote[]
  onClose: () => void
  onStatusChange: (ticketId: string, newStatus: TicketStatus) => void
  onSendMessage: (ticketId: string, content: string) => void
  onAddNote: (ticketId: string, content: string) => void
}

const priorityIcons = {
  critical: <AlertCircle className="h-4 w-4 text-[var(--priority-critical)]" />,
  high: <ArrowUp className="h-4 w-4 text-[var(--priority-high)]" />,
  medium: <ArrowRight className="h-4 w-4 text-[var(--priority-medium)]" />,
  low: <ArrowDown className="h-4 w-4 text-[var(--priority-low)]" />,
}

const statusOptions: TicketStatus[] = ["new", "open", "pending", "resolved", "closed"]

function formatMessageDate(date: Date) {
  return date.toLocaleString("ru-RU", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  })
}

function formatFullDate(date: Date) {
  return date.toLocaleString("ru-RU", {
    day: "numeric",
    month: "long",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
}

export function ChatPanel({
  ticket,
  messages,
  notes,
  onClose,
  onStatusChange,
  onSendMessage,
  onAddNote,
}: ChatPanelProps) {
  const [newMessage, setNewMessage] = useState("")
  const [showShortcuts, setShowShortcuts] = useState(false)
  const [filteredShortcuts, setFilteredShortcuts] = useState(quickReplies)
  const textareaRef = useRef<HTMLTextAreaElement>(null)

  if (!ticket) {
    return (
      <div className="flex-1 flex items-center justify-center text-muted-foreground">
        <div className="text-center">
          <div className="text-4xl mb-4">💬</div>
          <p>Выберите тикет для просмотра переписки</p>
        </div>
      </div>
    )
  }

  const handleSend = () => {
    if (newMessage.trim()) {
      onSendMessage(ticket.id, newMessage.trim())
      setNewMessage("")
    }
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  // Handle shortcut commands
  const handleMessageChange = (value: string) => {
    setNewMessage(value)
    
    // Check for shortcut commands
    if (value.startsWith("/")) {
      const command = value.toLowerCase()
      const matches = quickReplies.filter(
        (qr) => qr.shortcut?.toLowerCase().startsWith(command)
      )
      setFilteredShortcuts(matches)
      setShowShortcuts(matches.length > 0)
    } else {
      setShowShortcuts(false)
    }
  }

  const handleInsertTemplate = (content: string) => {
    // Replace variables in template
    const processed = content
      .replace("{agent_name}", "Анна Козлова")
      .replace("{customer_name}", ticket.customerName)
    setNewMessage(processed)
    setShowShortcuts(false)
    textareaRef.current?.focus()
  }

  return (
    <div className="flex-1 flex flex-col h-full bg-card border-l border-border">
      {/* Header */}
      <div className="p-4 border-b border-border">
        <div className="flex items-start justify-between mb-3">
          <div className="flex items-center gap-3">
            <Avatar className="h-10 w-10">
              <AvatarImage src={ticket.customerAvatar} />
              <AvatarFallback className="bg-secondary text-secondary-foreground">
                {ticket.customerName.charAt(0)}
              </AvatarFallback>
            </Avatar>
            <div>
              <h3 className="font-semibold text-foreground">{ticket.customerName}</h3>
              <p className="text-sm text-muted-foreground">{ticket.customerEmail}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8">
                  <MoreVertical className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem>Назначить агента</DropdownMenuItem>
                <DropdownMenuItem>Изменить приоритет</DropdownMenuItem>
                <DropdownMenuItem>Добавить тег</DropdownMenuItem>
                <DropdownMenuItem className="text-destructive">
                  Удалить тикет
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
            <Button variant="ghost" size="icon" className="h-8 w-8" onClick={onClose}>
              <X className="h-4 w-4" />
            </Button>
          </div>
        </div>

        {/* Ticket Info */}
        <div className="bg-secondary/50 rounded-lg p-3 space-y-2">
          <div className="flex items-center gap-2">
            <span className="text-xs text-muted-foreground font-mono">{ticket.id}</span>
            <div className="flex items-center gap-1">
              {priorityIcons[ticket.priority]}
              <span className="text-xs">{priorityLabels[ticket.priority]}</span>
            </div>
            <Badge variant="outline" className="text-xs ml-auto">
              {departmentLabels[ticket.department]}
            </Badge>
          </div>

          <h4 className="font-medium text-sm text-foreground">{ticket.subject}</h4>

          <div className="flex items-center gap-4 text-xs text-muted-foreground">
            <div className="flex items-center gap-1">
              <Calendar className="h-3 w-3" />
              <span>{formatFullDate(ticket.createdAt)}</span>
            </div>
            {ticket.assignedName && (
              <div className="flex items-center gap-1">
                <User className="h-3 w-3" />
                <span>{ticket.assignedName}</span>
              </div>
            )}
          </div>

          {ticket.tags && ticket.tags.length > 0 && (
            <div className="flex items-center gap-1.5 flex-wrap">
              <Tag className="h-3 w-3 text-muted-foreground" />
              {ticket.tags.map((tag) => (
                <Badge key={tag} variant="outline" className="text-[10px] h-5 px-1.5">
                  {tag}
                </Badge>
              ))}
            </div>
          )}

          {/* Status Selector + Internal Notes */}
          <div className="flex items-center justify-between pt-2 border-t border-border/50">
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted-foreground">Статус:</span>
              <Select
                value={ticket.status}
                onValueChange={(value) => onStatusChange(ticket.id, value as TicketStatus)}
              >
                <SelectTrigger className="h-7 text-xs w-auto">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {statusOptions.map((status) => (
                    <SelectItem key={status} value={status} className="text-xs">
                      {statusLabels[status]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <InternalNotes
              ticketId={ticket.id}
              notes={notes}
              onAddNote={onAddNote}
            />
          </div>
        </div>
      </div>

      {/* Messages */}
      <ScrollArea className="flex-1 p-4">
        <div className="space-y-4">
          {messages.map((message) => (
            <div
              key={message.id}
              className={cn(
                "flex gap-3",
                message.isAgent ? "flex-row-reverse" : "flex-row"
              )}
            >
              <Avatar className="h-8 w-8 flex-shrink-0">
                <AvatarImage src={message.senderAvatar} />
                <AvatarFallback
                  className={cn(
                    "text-xs",
                    message.isAgent
                      ? "bg-accent text-accent-foreground"
                      : "bg-secondary text-secondary-foreground"
                  )}
                >
                  {message.senderName.charAt(0)}
                </AvatarFallback>
              </Avatar>
              <div
                className={cn(
                  "flex flex-col max-w-[75%]",
                  message.isAgent ? "items-end" : "items-start"
                )}
              >
                <div className="flex items-center gap-2 mb-1">
                  <span className="text-xs font-medium text-foreground">
                    {message.senderName}
                  </span>
                  <span className="text-[10px] text-muted-foreground">
                    {formatMessageDate(message.createdAt)}
                  </span>
                </div>
                <div
                  className={cn(
                    "rounded-lg px-3 py-2 text-sm",
                    message.isAgent
                      ? "bg-accent text-accent-foreground"
                      : "bg-secondary text-secondary-foreground"
                  )}
                >
                  {message.content}
                </div>
              </div>
            </div>
          ))}
        </div>
      </ScrollArea>

      {/* Shortcut suggestions */}
      {showShortcuts && filteredShortcuts.length > 0 && (
        <div className="mx-4 mb-2 p-2 bg-secondary rounded-lg border border-border">
          <p className="text-[10px] text-muted-foreground mb-2">Быстрые ответы:</p>
          <div className="space-y-1">
            {filteredShortcuts.slice(0, 5).map((shortcut) => (
              <button
                key={shortcut.id}
                onClick={() => handleInsertTemplate(shortcut.content)}
                className="w-full text-left p-2 rounded hover:bg-background transition-colors"
              >
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium text-foreground">{shortcut.title}</span>
                  <span className="text-[10px] text-muted-foreground font-mono">{shortcut.shortcut}</span>
                </div>
                <p className="text-xs text-muted-foreground truncate">{shortcut.content.substring(0, 60)}...</p>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Input */}
      <div className="p-4 border-t border-border">
        <div className="flex gap-2">
          <QuickReplies onInsert={handleInsertTemplate} />
          <KnowledgeBase onInsertLink={(_, content) => handleInsertTemplate(content)} />
          <Button variant="ghost" size="icon" className="h-10 w-10 flex-shrink-0">
            <Paperclip className="h-4 w-4" />
          </Button>
          <div className="flex-1 relative">
            <Textarea
              ref={textareaRef}
              value={newMessage}
              onChange={(e) => handleMessageChange(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="Введите сообщение или /команду..."
              className="min-h-10 max-h-32 resize-none pr-4"
              rows={1}
            />
          </div>
          <Button
            onClick={handleSend}
            disabled={!newMessage.trim()}
            className="h-10 w-10 flex-shrink-0"
            size="icon"
          >
            <Send className="h-4 w-4" />
          </Button>
        </div>
        <p className="text-[10px] text-muted-foreground mt-2">
          Enter для отправки, Shift+Enter для новой строки, / для быстрых ответов
        </p>
      </div>
    </div>
  )
}
