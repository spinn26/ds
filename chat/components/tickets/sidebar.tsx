"use client"

import { cn } from "@/lib/utils"
import type { TicketStatus, TicketPriority, Department } from "@/lib/types"
import { statusLabels, priorityLabels, departmentLabels } from "@/lib/types"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Separator } from "@/components/ui/separator"
import {
  LayoutGrid,
  List,
  Users,
  Inbox,
  AlertCircle,
  ArrowUp,
  ArrowRight,
  ArrowDown,
  Headphones,
  CreditCard,
  ShoppingBag,
  HelpCircle,
  Settings,
  Plus,
} from "lucide-react"

type ViewMode = "kanban" | "list" | "threads"
type FilterType = "status" | "priority" | "department"

interface SidebarProps {
  viewMode: ViewMode
  onViewModeChange: (mode: ViewMode) => void
  activeFilter: { type: FilterType; value: string } | null
  onFilterChange: (filter: { type: FilterType; value: string } | null) => void
  ticketCounts: {
    byStatus: Record<TicketStatus, number>
    byPriority: Record<TicketPriority, number>
    byDepartment: Record<Department, number>
    total: number
  }
}

const statusIcons: Record<TicketStatus, string> = {
  new: "bg-[var(--status-new)]",
  open: "bg-[var(--status-open)]",
  pending: "bg-[var(--status-pending)]",
  resolved: "bg-[var(--status-resolved)]",
  closed: "bg-[var(--status-closed)]",
}

const priorityIcons = {
  critical: <AlertCircle className="h-4 w-4 text-[var(--priority-critical)]" />,
  high: <ArrowUp className="h-4 w-4 text-[var(--priority-high)]" />,
  medium: <ArrowRight className="h-4 w-4 text-[var(--priority-medium)]" />,
  low: <ArrowDown className="h-4 w-4 text-[var(--priority-low)]" />,
}

const departmentIcons: Record<Department, React.ReactNode> = {
  technical: <Headphones className="h-4 w-4" />,
  billing: <CreditCard className="h-4 w-4" />,
  sales: <ShoppingBag className="h-4 w-4" />,
  general: <HelpCircle className="h-4 w-4" />,
}

export function Sidebar({
  viewMode,
  onViewModeChange,
  activeFilter,
  onFilterChange,
  ticketCounts,
}: SidebarProps) {
  const isFilterActive = (type: FilterType, value: string) =>
    activeFilter?.type === type && activeFilter?.value === value

  return (
    <div className="w-64 flex-shrink-0 bg-sidebar border-r border-sidebar-border flex flex-col h-full">
      {/* Logo */}
      <div className="p-4 border-b border-sidebar-border">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-accent flex items-center justify-center">
            <Inbox className="h-4 w-4 text-accent-foreground" />
          </div>
          <div>
            <h1 className="font-semibold text-sidebar-foreground">Support Desk</h1>
            <p className="text-xs text-muted-foreground">Система тикетов</p>
          </div>
        </div>
      </div>

      <ScrollArea className="flex-1">
        <div className="p-3">
          {/* New Ticket Button */}
          <Button className="w-full mb-4" size="sm">
            <Plus className="h-4 w-4 mr-2" />
            Новый тикет
          </Button>

          {/* View Mode */}
          <div className="mb-4">
            <p className="text-xs font-medium text-muted-foreground mb-2 px-2">
              ОТОБРАЖЕНИЕ
            </p>
            <div className="space-y-0.5">
              <Button
                variant={viewMode === "kanban" ? "secondary" : "ghost"}
                className={cn(
                  "w-full justify-start h-9",
                  viewMode === "kanban" && "bg-sidebar-accent text-sidebar-accent-foreground"
                )}
                onClick={() => onViewModeChange("kanban")}
              >
                <LayoutGrid className="h-4 w-4 mr-2" />
                Канбан
              </Button>
              <Button
                variant={viewMode === "list" ? "secondary" : "ghost"}
                className={cn(
                  "w-full justify-start h-9",
                  viewMode === "list" && "bg-sidebar-accent text-sidebar-accent-foreground"
                )}
                onClick={() => onViewModeChange("list")}
              >
                <List className="h-4 w-4 mr-2" />
                Список
              </Button>
              <Button
                variant={viewMode === "threads" ? "secondary" : "ghost"}
                className={cn(
                  "w-full justify-start h-9",
                  viewMode === "threads" && "bg-sidebar-accent text-sidebar-accent-foreground"
                )}
                onClick={() => onViewModeChange("threads")}
              >
                <Users className="h-4 w-4 mr-2" />
                По клиентам
              </Button>
            </div>
          </div>

          <Separator className="my-3" />

          {/* All Tickets */}
          <Button
            variant={activeFilter === null ? "secondary" : "ghost"}
            className={cn(
              "w-full justify-between h-9 mb-4",
              activeFilter === null && "bg-sidebar-accent text-sidebar-accent-foreground"
            )}
            onClick={() => onFilterChange(null)}
          >
            <div className="flex items-center">
              <Inbox className="h-4 w-4 mr-2" />
              Все тикеты
            </div>
            <Badge variant="secondary" className="h-5 px-1.5 text-xs">
              {ticketCounts.total}
            </Badge>
          </Button>

          {/* Status Filters */}
          <div className="mb-4">
            <p className="text-xs font-medium text-muted-foreground mb-2 px-2">
              ПО СТАТУСУ
            </p>
            <div className="space-y-0.5">
              {(Object.keys(statusLabels) as TicketStatus[]).map((status) => (
                <Button
                  key={status}
                  variant={isFilterActive("status", status) ? "secondary" : "ghost"}
                  className={cn(
                    "w-full justify-between h-9",
                    isFilterActive("status", status) && "bg-sidebar-accent text-sidebar-accent-foreground"
                  )}
                  onClick={() =>
                    onFilterChange(
                      isFilterActive("status", status)
                        ? null
                        : { type: "status", value: status }
                    )
                  }
                >
                  <div className="flex items-center">
                    <div className={cn("w-2.5 h-2.5 rounded-full mr-2", statusIcons[status])} />
                    {statusLabels[status]}
                  </div>
                  <Badge variant="secondary" className="h-5 px-1.5 text-xs">
                    {ticketCounts.byStatus[status]}
                  </Badge>
                </Button>
              ))}
            </div>
          </div>

          <Separator className="my-3" />

          {/* Priority Filters */}
          <div className="mb-4">
            <p className="text-xs font-medium text-muted-foreground mb-2 px-2">
              ПО ПРИОРИТЕТУ
            </p>
            <div className="space-y-0.5">
              {(Object.keys(priorityLabels) as TicketPriority[]).map((priority) => (
                <Button
                  key={priority}
                  variant={isFilterActive("priority", priority) ? "secondary" : "ghost"}
                  className={cn(
                    "w-full justify-between h-9",
                    isFilterActive("priority", priority) && "bg-sidebar-accent text-sidebar-accent-foreground"
                  )}
                  onClick={() =>
                    onFilterChange(
                      isFilterActive("priority", priority)
                        ? null
                        : { type: "priority", value: priority }
                    )
                  }
                >
                  <div className="flex items-center">
                    <span className="mr-2">{priorityIcons[priority]}</span>
                    {priorityLabels[priority]}
                  </div>
                  <Badge variant="secondary" className="h-5 px-1.5 text-xs">
                    {ticketCounts.byPriority[priority]}
                  </Badge>
                </Button>
              ))}
            </div>
          </div>

          <Separator className="my-3" />

          {/* Department Filters */}
          <div className="mb-4">
            <p className="text-xs font-medium text-muted-foreground mb-2 px-2">
              ПО ОТДЕЛУ
            </p>
            <div className="space-y-0.5">
              {(Object.keys(departmentLabels) as Department[]).map((dept) => (
                <Button
                  key={dept}
                  variant={isFilterActive("department", dept) ? "secondary" : "ghost"}
                  className={cn(
                    "w-full justify-between h-9",
                    isFilterActive("department", dept) && "bg-sidebar-accent text-sidebar-accent-foreground"
                  )}
                  onClick={() =>
                    onFilterChange(
                      isFilterActive("department", dept)
                        ? null
                        : { type: "department", value: dept }
                    )
                  }
                >
                  <div className="flex items-center">
                    <span className="mr-2 text-muted-foreground">{departmentIcons[dept]}</span>
                    {departmentLabels[dept]}
                  </div>
                  <Badge variant="secondary" className="h-5 px-1.5 text-xs">
                    {ticketCounts.byDepartment[dept]}
                  </Badge>
                </Button>
              ))}
            </div>
          </div>
        </div>
      </ScrollArea>

      {/* Footer */}
      <div className="p-3 border-t border-sidebar-border">
        <Button variant="ghost" className="w-full justify-start h-9">
          <Settings className="h-4 w-4 mr-2" />
          Настройки
        </Button>
      </div>
    </div>
  )
}
