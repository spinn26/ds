"use client"

import { useState, useMemo } from "react"
import { cn } from "@/lib/utils"
import type { QuickReply } from "@/lib/types"
import { quickReplies } from "@/lib/mock-data"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { ScrollArea } from "@/components/ui/scroll-area"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Zap, Search, Copy, Plus, Command } from "lucide-react"

interface QuickRepliesProps {
  onInsert: (content: string) => void
}

const categories = ["Все", "Общие", "Техподдержка", "Биллинг", "Эскалация", "Завершение"]

export function QuickReplies({ onInsert }: QuickRepliesProps) {
  const [isOpen, setIsOpen] = useState(false)
  const [search, setSearch] = useState("")
  const [activeCategory, setActiveCategory] = useState("Все")

  const filteredReplies = useMemo(() => {
    let filtered = quickReplies

    if (activeCategory !== "Все") {
      filtered = filtered.filter((qr) => qr.category === activeCategory)
    }

    if (search) {
      const lowerSearch = search.toLowerCase()
      filtered = filtered.filter(
        (qr) =>
          qr.title.toLowerCase().includes(lowerSearch) ||
          qr.content.toLowerCase().includes(lowerSearch) ||
          qr.shortcut?.toLowerCase().includes(lowerSearch)
      )
    }

    return filtered
  }, [search, activeCategory])

  const handleInsert = (reply: QuickReply) => {
    onInsert(reply.content)
    setIsOpen(false)
    setSearch("")
  }

  return (
    <Popover open={isOpen} onOpenChange={setIsOpen}>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" className="h-10 w-10 flex-shrink-0" title="Быстрые ответы">
          <Zap className="h-4 w-4" />
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" side="top" className="w-[500px] p-0">
        <div className="p-3 border-b border-border">
          <div className="flex items-center justify-between mb-3">
            <h3 className="font-semibold text-foreground">Быстрые ответы</h3>
            <Dialog>
              <DialogTrigger asChild>
                <Button variant="ghost" size="sm" className="h-7 text-xs">
                  <Plus className="h-3.5 w-3.5 mr-1" />
                  Создать
                </Button>
              </DialogTrigger>
              <DialogContent>
                <DialogHeader>
                  <DialogTitle>Создать шаблон</DialogTitle>
                </DialogHeader>
                <div className="text-sm text-muted-foreground">
                  Функционал создания шаблонов будет доступен после подключения базы данных.
                </div>
              </DialogContent>
            </Dialog>
          </div>
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Поиск шаблонов или /команда..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="pl-8 h-9 bg-secondary border-0"
            />
          </div>
        </div>

        <Tabs value={activeCategory} onValueChange={setActiveCategory} className="w-full">
          <div className="px-3 pt-2 border-b border-border">
            <TabsList className="h-8 p-0.5 bg-secondary/50 w-full justify-start overflow-x-auto">
              {categories.map((category) => (
                <TabsTrigger
                  key={category}
                  value={category}
                  className="text-xs px-2.5 h-7 data-[state=active]:bg-background"
                >
                  {category}
                </TabsTrigger>
              ))}
            </TabsList>
          </div>

          <ScrollArea className="h-[300px]">
            <div className="p-2 space-y-1">
              {filteredReplies.length === 0 ? (
                <div className="flex flex-col items-center justify-center h-40 text-muted-foreground">
                  <Zap className="h-8 w-8 mb-2 opacity-50" />
                  <p className="text-sm">Шаблоны не найдены</p>
                </div>
              ) : (
                filteredReplies.map((reply) => (
                  <button
                    key={reply.id}
                    onClick={() => handleInsert(reply)}
                    className="w-full text-left p-3 rounded-lg hover:bg-secondary/50 transition-colors group"
                  >
                    <div className="flex items-center justify-between mb-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium text-foreground">
                          {reply.title}
                        </span>
                        <Badge variant="outline" className="text-[10px] h-5">
                          {reply.category}
                        </Badge>
                      </div>
                      <div className="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        {reply.shortcut && (
                          <span className="text-[10px] text-muted-foreground font-mono bg-secondary px-1.5 py-0.5 rounded flex items-center gap-1">
                            <Command className="h-2.5 w-2.5" />
                            {reply.shortcut}
                          </span>
                        )}
                        <Copy className="h-3.5 w-3.5 text-muted-foreground" />
                      </div>
                    </div>
                    <p className="text-xs text-muted-foreground line-clamp-2">
                      {reply.content.substring(0, 150)}...
                    </p>
                  </button>
                ))
              )}
            </div>
          </ScrollArea>
        </Tabs>

        <div className="p-2 border-t border-border bg-secondary/30">
          <p className="text-[10px] text-muted-foreground text-center">
            Введите /команду в поле ввода для быстрой вставки
          </p>
        </div>
      </PopoverContent>
    </Popover>
  )
}
