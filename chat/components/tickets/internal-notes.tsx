"use client"

import { useState } from "react"
import { cn } from "@/lib/utils"
import type { InternalNote } from "@/lib/types"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { ScrollArea } from "@/components/ui/scroll-area"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import { StickyNote, Send, Lock, Trash2 } from "lucide-react"

interface InternalNotesProps {
  ticketId: string
  notes: InternalNote[]
  onAddNote: (ticketId: string, content: string) => void
  onDeleteNote?: (noteId: string) => void
}

function formatNoteDate(date: Date): string {
  return date.toLocaleString("ru-RU", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  })
}

export function InternalNotes({ ticketId, notes, onAddNote, onDeleteNote }: InternalNotesProps) {
  const [isOpen, setIsOpen] = useState(false)
  const [newNote, setNewNote] = useState("")

  const handleSubmit = () => {
    if (newNote.trim()) {
      onAddNote(ticketId, newNote.trim())
      setNewNote("")
    }
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) {
      e.preventDefault()
      handleSubmit()
    }
  }

  return (
    <Sheet open={isOpen} onOpenChange={setIsOpen}>
      <SheetTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className="h-7 text-xs relative"
        >
          <StickyNote className="h-3.5 w-3.5 mr-1" />
          Заметки
          {notes.length > 0 && (
            <span className="ml-1 bg-accent text-accent-foreground text-[10px] px-1.5 py-0.5 rounded-full">
              {notes.length}
            </span>
          )}
        </Button>
      </SheetTrigger>
      <SheetContent className="w-[400px] sm:w-[450px] p-0 flex flex-col">
        <SheetHeader className="p-4 border-b border-border">
          <SheetTitle className="flex items-center gap-2">
            <Lock className="h-4 w-4 text-muted-foreground" />
            Внутренние заметки
          </SheetTitle>
          <p className="text-xs text-muted-foreground">
            Заметки видны только агентам поддержки
          </p>
        </SheetHeader>

        <ScrollArea className="flex-1 p-4">
          {notes.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-40 text-muted-foreground">
              <StickyNote className="h-10 w-10 mb-3 opacity-50" />
              <p className="text-sm font-medium">Нет заметок</p>
              <p className="text-xs mt-1">
                Добавьте внутреннюю заметку для коллег
              </p>
            </div>
          ) : (
            <div className="space-y-4">
              {notes.map((note) => (
                <div
                  key={note.id}
                  className="bg-secondary/50 rounded-lg p-3 border border-border/50 group"
                >
                  <div className="flex items-start justify-between mb-2">
                    <div className="flex items-center gap-2">
                      <Avatar className="h-6 w-6">
                        <AvatarImage src={note.authorAvatar} />
                        <AvatarFallback className="text-[10px] bg-accent text-accent-foreground">
                          {note.authorName.charAt(0)}
                        </AvatarFallback>
                      </Avatar>
                      <div>
                        <p className="text-xs font-medium text-foreground">
                          {note.authorName}
                        </p>
                        <p className="text-[10px] text-muted-foreground">
                          {formatNoteDate(note.createdAt)}
                        </p>
                      </div>
                    </div>
                    {onDeleteNote && (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-6 w-6 opacity-0 group-hover:opacity-100 transition-opacity text-muted-foreground hover:text-destructive"
                        onClick={() => onDeleteNote(note.id)}
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    )}
                  </div>
                  <p className="text-sm text-foreground whitespace-pre-wrap">
                    {note.content}
                  </p>
                </div>
              ))}
            </div>
          )}
        </ScrollArea>

        <div className="p-4 border-t border-border bg-card">
          <Textarea
            value={newNote}
            onChange={(e) => setNewNote(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Добавить внутреннюю заметку..."
            className="min-h-[80px] resize-none mb-2"
          />
          <div className="flex items-center justify-between">
            <p className="text-[10px] text-muted-foreground">
              Cmd/Ctrl + Enter для отправки
            </p>
            <Button
              onClick={handleSubmit}
              disabled={!newNote.trim()}
              size="sm"
              className="h-8"
            >
              <Send className="h-3.5 w-3.5 mr-1" />
              Добавить
            </Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  )
}
