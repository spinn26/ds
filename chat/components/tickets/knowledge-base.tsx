"use client"

import { useState, useMemo } from "react"
import { cn } from "@/lib/utils"
import type { KnowledgeArticle } from "@/lib/types"
import { knowledgeArticles } from "@/lib/mock-data"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { ScrollArea } from "@/components/ui/scroll-area"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import {
  Book,
  Search,
  ExternalLink,
  ThumbsUp,
  Eye,
  Copy,
  Check,
  ChevronLeft,
  Tag,
} from "lucide-react"

interface KnowledgeBaseProps {
  onInsertLink?: (title: string, content: string) => void
}

const categories = ["Все", "Браузер", "Аккаунт", "Отчёты", "Интеграции", "Биллинг"]

function formatDate(date: Date): string {
  return date.toLocaleDateString("ru-RU", {
    day: "numeric",
    month: "short",
    year: "numeric",
  })
}

export function KnowledgeBase({ onInsertLink }: KnowledgeBaseProps) {
  const [isOpen, setIsOpen] = useState(false)
  const [search, setSearch] = useState("")
  const [activeCategory, setActiveCategory] = useState("Все")
  const [selectedArticle, setSelectedArticle] = useState<KnowledgeArticle | null>(null)
  const [copied, setCopied] = useState(false)

  const filteredArticles = useMemo(() => {
    let filtered = knowledgeArticles

    if (activeCategory !== "Все") {
      filtered = filtered.filter((article) => article.category === activeCategory)
    }

    if (search) {
      const lowerSearch = search.toLowerCase()
      filtered = filtered.filter(
        (article) =>
          article.title.toLowerCase().includes(lowerSearch) ||
          article.content.toLowerCase().includes(lowerSearch) ||
          article.tags.some((tag) => tag.toLowerCase().includes(lowerSearch))
      )
    }

    return filtered.sort((a, b) => b.views - a.views)
  }, [search, activeCategory])

  const handleCopyContent = () => {
    if (selectedArticle) {
      navigator.clipboard.writeText(selectedArticle.content)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    }
  }

  const handleInsertLink = () => {
    if (selectedArticle && onInsertLink) {
      const snippet = `Рекомендуем ознакомиться со статьёй: "${selectedArticle.title}"\n\nКраткое содержание:\n${selectedArticle.content.substring(0, 200)}...`
      onInsertLink(selectedArticle.title, snippet)
      setIsOpen(false)
      setSelectedArticle(null)
    }
  }

  return (
    <Sheet open={isOpen} onOpenChange={setIsOpen}>
      <SheetTrigger asChild>
        <Button variant="ghost" size="icon" className="h-10 w-10 flex-shrink-0" title="База знаний">
          <Book className="h-4 w-4" />
        </Button>
      </SheetTrigger>
      <SheetContent className="w-[600px] sm:w-[700px] p-0 flex flex-col">
        <SheetHeader className="p-4 border-b border-border">
          <div className="flex items-center gap-2">
            {selectedArticle && (
              <Button
                variant="ghost"
                size="icon"
                className="h-8 w-8 mr-1"
                onClick={() => setSelectedArticle(null)}
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
            )}
            <SheetTitle className="flex items-center gap-2">
              <Book className="h-4 w-4 text-accent" />
              {selectedArticle ? selectedArticle.title : "База знаний"}
            </SheetTitle>
          </div>
        </SheetHeader>

        {!selectedArticle ? (
          <>
            <div className="p-4 border-b border-border space-y-3">
              <div className="relative">
                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Поиск статей..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="pl-8 h-9 bg-secondary border-0"
                />
              </div>
              <div className="flex gap-1.5 flex-wrap">
                {categories.map((category) => (
                  <Button
                    key={category}
                    variant={activeCategory === category ? "default" : "outline"}
                    size="sm"
                    className="h-7 text-xs"
                    onClick={() => setActiveCategory(category)}
                  >
                    {category}
                  </Button>
                ))}
              </div>
            </div>

            <ScrollArea className="flex-1">
              <div className="p-4 space-y-2">
                {filteredArticles.length === 0 ? (
                  <div className="flex flex-col items-center justify-center h-40 text-muted-foreground">
                    <Book className="h-10 w-10 mb-3 opacity-50" />
                    <p className="text-sm font-medium">Статьи не найдены</p>
                    <p className="text-xs mt-1">Попробуйте изменить поисковый запрос</p>
                  </div>
                ) : (
                  filteredArticles.map((article) => (
                    <button
                      key={article.id}
                      onClick={() => setSelectedArticle(article)}
                      className="w-full text-left p-4 rounded-lg border border-border hover:border-accent/50 hover:bg-secondary/30 transition-all group"
                    >
                      <div className="flex items-start justify-between mb-2">
                        <h4 className="font-medium text-foreground group-hover:text-accent transition-colors">
                          {article.title}
                        </h4>
                        <ExternalLink className="h-4 w-4 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0 ml-2" />
                      </div>
                      <p className="text-sm text-muted-foreground line-clamp-2 mb-3">
                        {article.content.substring(0, 150)}...
                      </p>
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                          <Badge variant="outline" className="text-[10px] h-5">
                            {article.category}
                          </Badge>
                          {article.tags.slice(0, 2).map((tag) => (
                            <span
                              key={tag}
                              className="text-[10px] text-muted-foreground bg-secondary px-1.5 py-0.5 rounded"
                            >
                              {tag}
                            </span>
                          ))}
                        </div>
                        <div className="flex items-center gap-3 text-[10px] text-muted-foreground">
                          <span className="flex items-center gap-1">
                            <Eye className="h-3 w-3" />
                            {article.views}
                          </span>
                          <span className="flex items-center gap-1">
                            <ThumbsUp className="h-3 w-3" />
                            {article.helpful}
                          </span>
                        </div>
                      </div>
                    </button>
                  ))
                )}
              </div>
            </ScrollArea>
          </>
        ) : (
          <>
            <ScrollArea className="flex-1 p-6">
              <div className="prose prose-sm prose-invert max-w-none">
                <div className="flex items-center gap-2 mb-4">
                  <Badge variant="outline">{selectedArticle.category}</Badge>
                  {selectedArticle.tags.map((tag) => (
                    <span
                      key={tag}
                      className="text-xs text-muted-foreground bg-secondary px-2 py-1 rounded flex items-center gap-1"
                    >
                      <Tag className="h-2.5 w-2.5" />
                      {tag}
                    </span>
                  ))}
                </div>

                <div className="flex items-center gap-4 text-xs text-muted-foreground mb-6">
                  <span className="flex items-center gap-1">
                    <Eye className="h-3.5 w-3.5" />
                    {selectedArticle.views} просмотров
                  </span>
                  <span className="flex items-center gap-1">
                    <ThumbsUp className="h-3.5 w-3.5" />
                    {selectedArticle.helpful} полезно
                  </span>
                  <span>Обновлено: {formatDate(selectedArticle.updatedAt)}</span>
                </div>

                <div className="whitespace-pre-wrap text-foreground leading-relaxed">
                  {selectedArticle.content}
                </div>
              </div>
            </ScrollArea>

            <div className="p-4 border-t border-border bg-card flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                className="h-9"
                onClick={handleCopyContent}
              >
                {copied ? (
                  <Check className="h-3.5 w-3.5 mr-1" />
                ) : (
                  <Copy className="h-3.5 w-3.5 mr-1" />
                )}
                {copied ? "Скопировано" : "Копировать"}
              </Button>
              {onInsertLink && (
                <Button size="sm" className="h-9" onClick={handleInsertLink}>
                  <ExternalLink className="h-3.5 w-3.5 mr-1" />
                  Вставить в ответ
                </Button>
              )}
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  )
}
