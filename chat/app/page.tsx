"use client"

import { TicketsDashboard } from "@/components/tickets"

/**
 * Пример использования компонента TicketsDashboard
 * 
 * Этот компонент можно встроить в любой layout с вашим меню и шапкой.
 * Просто оберните его в контейнер нужного размера.
 */
export default function TicketsPage() {
  return (
    <div className="h-screen bg-background">
      {/* 
        Здесь может быть ваша шапка:
        <YourHeader />
      */}
      
      <div className="h-full">
        {/* 
          Если у вас есть своё меню слева:
          <div className="flex h-full">
            <YourSidebar />
            <TicketsDashboard className="flex-1" />
          </div>
        */}
        
        <TicketsDashboard 
          defaultView="kanban"
          showSearch={true}
          showNotifications={true}
          onTicketSelect={(ticket) => {
            // Можно обрабатывать выбор тикета
            console.log("Selected ticket:", ticket?.id)
          }}
          onStatusChange={(ticketId, newStatus) => {
            // Можно обрабатывать смену статуса
            console.log("Status changed:", ticketId, newStatus)
          }}
        />
      </div>
    </div>
  )
}
