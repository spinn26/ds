"use client"

import { useState, useEffect } from "react"
import { cn } from "@/lib/utils"
import type { Notification } from "@/lib/types"
import { initialNotifications } from "@/lib/mock-data"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { ScrollArea } from "@/components/ui/scroll-area"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import {
  Bell,
  MessageSquare,
  Ticket,
  UserPlus,
  RefreshCw,
  AtSign,
  Check,
  CheckCheck,
  Volume2,
  VolumeX,
} from "lucide-react"

interface NotificationsPanelProps {
  onTicketClick?: (ticketId: string) => void
}

const notificationIcons = {
  new_ticket: Ticket,
  new_message: MessageSquare,
  assignment: UserPlus,
  status_change: RefreshCw,
  mention: AtSign,
}

function formatTimeAgo(date: Date): string {
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)

  if (diffMins < 1) return "только что"
  if (diffMins < 60) return `${diffMins} мин. назад`
  if (diffHours < 24) return `${diffHours} ч. назад`
  return `${diffDays} д. назад`
}

export function NotificationsPanel({ onTicketClick }: NotificationsPanelProps) {
  const [notifications, setNotifications] = useState<Notification[]>(initialNotifications)
  const [isOpen, setIsOpen] = useState(false)
  const [soundEnabled, setSoundEnabled] = useState(true)

  const unreadCount = notifications.filter((n) => !n.isRead).length

  // Simulate real-time notifications
  useEffect(() => {
    const interval = setInterval(() => {
      // Random chance to add a notification (for demo)
      if (Math.random() > 0.95) {
        const newNotif: Notification = {
          id: `notif-${Date.now()}`,
          type: "new_message",
          title: "Новое сообщение",
          message: "Демо уведомление для тестирования",
          isRead: false,
          createdAt: new Date(),
        }
        setNotifications((prev) => [newNotif, ...prev])
        
        if (soundEnabled) {
          // Play notification sound
          const audio = new Audio("data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2teleF4AAAB5mX9lVpyAYnR1e5Sjqq+cX0hAV3+z7enWqYR/XYiYkHFYT1t2lLPF1r2vl3lgY2ZwhZOqxNTg0q6Ei3plYGhyh5+5zc/CpZKDb2JfZXSDl6y+y8C7qJWJfHJucXuImaOurrKpo5WJgn14c3N2fYSMlJaXlpOOiYWBfnx6eHh5e36Ch4qMjIuJhoN/fHt6ent9gIOGh4eFg4F+fHt7fH1/gYOEhYWEg4F/fXx8fX5/gYKDg4OCgYB/fn1+fn+AgYGBgYGAgH9/fn5+f4CAgICAgIB/f39/f39/f4CAgICAgICAf39/f39/f3+AgICAgICAgH9/f39/f39/gICAgICAgIB/f39/f39/f4CAgICAgICAf39/f39/f39/gICAgICAgH9/f39/f39/f4CAgICAgIB/f39/f39/f3+AgICAgICAgH9/f39/f39/gICAgICAgIB/f39/f39/f4CAgICAgICAf39/f39/f3+AgICAgICAgH9/f39/f39/gICAgICAgIB/f39/f39/f4CAgICAgICAf39/f39/f3+AgICAgICAgA==")
          audio.volume = 0.3
          audio.play().catch(() => {})
        }
      }
    }, 5000)

    return () => clearInterval(interval)
  }, [soundEnabled])

  const markAsRead = (id: string) => {
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, isRead: true } : n))
    )
  }

  const markAllAsRead = () => {
    setNotifications((prev) => prev.map((n) => ({ ...n, isRead: true })))
  }

  const handleNotificationClick = (notification: Notification) => {
    markAsRead(notification.id)
    if (notification.ticketId && onTicketClick) {
      onTicketClick(notification.ticketId)
      setIsOpen(false)
    }
  }

  return (
    <Popover open={isOpen} onOpenChange={setIsOpen}>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" className="relative">
          <Bell className="h-4 w-4" />
          {unreadCount > 0 && (
            <Badge className="absolute -top-1 -right-1 h-5 w-5 p-0 flex items-center justify-center text-[10px] animate-pulse">
              {unreadCount}
            </Badge>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-96 p-0">
        <div className="flex items-center justify-between p-3 border-b border-border">
          <h3 className="font-semibold text-foreground">Уведомления</h3>
          <div className="flex items-center gap-1">
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              onClick={() => setSoundEnabled(!soundEnabled)}
              title={soundEnabled ? "Выключить звук" : "Включить звук"}
            >
              {soundEnabled ? (
                <Volume2 className="h-3.5 w-3.5" />
              ) : (
                <VolumeX className="h-3.5 w-3.5" />
              )}
            </Button>
            {unreadCount > 0 && (
              <Button
                variant="ghost"
                size="sm"
                className="h-7 text-xs"
                onClick={markAllAsRead}
              >
                <CheckCheck className="h-3.5 w-3.5 mr-1" />
                Прочитать все
              </Button>
            )}
          </div>
        </div>

        <ScrollArea className="h-[400px]">
          {notifications.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-40 text-muted-foreground">
              <Bell className="h-8 w-8 mb-2 opacity-50" />
              <p className="text-sm">Нет уведомлений</p>
            </div>
          ) : (
            <div className="divide-y divide-border">
              {notifications.map((notification) => {
                const Icon = notificationIcons[notification.type]
                return (
                  <button
                    key={notification.id}
                    onClick={() => handleNotificationClick(notification)}
                    className={cn(
                      "w-full flex items-start gap-3 p-3 text-left hover:bg-secondary/50 transition-colors",
                      !notification.isRead && "bg-accent/5"
                    )}
                  >
                    <div
                      className={cn(
                        "flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center",
                        notification.type === "new_ticket" && "bg-[var(--status-new)]/20 text-[var(--status-new)]",
                        notification.type === "new_message" && "bg-accent/20 text-accent",
                        notification.type === "assignment" && "bg-[var(--status-open)]/20 text-[var(--status-open)]",
                        notification.type === "status_change" && "bg-[var(--status-resolved)]/20 text-[var(--status-resolved)]",
                        notification.type === "mention" && "bg-[var(--priority-high)]/20 text-[var(--priority-high)]"
                      )}
                    >
                      <Icon className="h-4 w-4" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <p className="text-sm font-medium text-foreground truncate">
                          {notification.title}
                        </p>
                        {!notification.isRead && (
                          <span className="flex-shrink-0 w-2 h-2 rounded-full bg-accent" />
                        )}
                      </div>
                      <p className="text-sm text-muted-foreground truncate">
                        {notification.message}
                      </p>
                      <p className="text-xs text-muted-foreground mt-1">
                        {formatTimeAgo(notification.createdAt)}
                      </p>
                    </div>
                    {!notification.isRead && (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-6 w-6 flex-shrink-0"
                        onClick={(e) => {
                          e.stopPropagation()
                          markAsRead(notification.id)
                        }}
                      >
                        <Check className="h-3 w-3" />
                      </Button>
                    )}
                  </button>
                )
              })}
            </div>
          )}
        </ScrollArea>
      </PopoverContent>
    </Popover>
  )
}
