# Frontend Structure Plan

```
frontend/src/
  api/              # axios client + API endpoints
    client.ts
    auth.ts
    consultants.ts
    clients.ts
    contracts.ts
    transactions.ts
  components/       # Shared reusable components
    Layout/
      MainLayout.tsx
      Sidebar.tsx
      TopBar.tsx
    Dashboard/
      StatCard.tsx
      QualificationBar.tsx
      VolumeIndicators.tsx
    Tables/
      DataTable.tsx
    Forms/
      StepForm.tsx
  pages/            # Route pages
    Dashboard/
    Referrals/
    Finance/
      Report.tsx
      VolumeCalculator.tsx
    Clients/
      AddClient.tsx
      ClientList.tsx
    Contracts/
      MyContracts.tsx
      TeamContracts.tsx
    Structure/
      TeamStructure.tsx
    Products/
      ProductList.tsx
    Contests/
      ContestList.tsx
    Communication/
    Profile/
  hooks/            # Custom React hooks
  store/            # State management (if needed)
  theme/            # MUI theme customization
    theme.ts
  types/            # TypeScript interfaces
    models.ts
  utils/            # Helpers
  App.tsx
  index.tsx
```
