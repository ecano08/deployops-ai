import type { AppView } from './Sidebar'
import { ContextBar } from './ContextBar'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import type { Customer, Deployment, Workspace } from '../../types'

type AppLayoutProps = {
  activeView: AppView
  onNavigate: (view: AppView) => void
  sidebarOpen: boolean
  onSidebarToggle: () => void
  onSidebarClose: () => void
  userName: string
  userEmail: string
  apiStatus: string | null
  aiStatus: string | null
  onLogout: () => void
  workspaces: Workspace[]
  customers: Customer[]
  deployments: Deployment[]
  selectedWorkspaceId: number | null
  selectedCustomerId: number | null
  selectedDeploymentId: number | null
  onWorkspaceChange: (id: number) => void
  onCustomerChange: (id: number) => void
  onDeploymentChange: (id: number) => void
  onCreateWorkspace: (name: string) => Promise<void>
  pendingApprovals: number
  children: React.ReactNode
}

const viewTitles: Record<AppView, string> = {
  dashboard: 'Dashboard',
  integrations: 'Integrations',
  copilot: 'AI Copilot',
  knowledge: 'Knowledge Base',
  evals: 'AI Evaluations',
  approvals: 'Pending Approvals',
  observability: 'Observability & Incidents',
}

export function AppLayout({
  activeView,
  onNavigate,
  sidebarOpen,
  onSidebarToggle,
  onSidebarClose,
  userName,
  userEmail,
  apiStatus,
  aiStatus,
  onLogout,
  workspaces,
  customers,
  deployments,
  selectedWorkspaceId,
  selectedCustomerId,
  selectedDeploymentId,
  onWorkspaceChange,
  onCustomerChange,
  onDeploymentChange,
  onCreateWorkspace,
  pendingApprovals,
  children,
}: AppLayoutProps) {
  return (
    <div className="app-shell">
      <Sidebar
        activeView={activeView}
        onNavigate={onNavigate}
        pendingApprovals={pendingApprovals}
        open={sidebarOpen}
        onClose={onSidebarClose}
      />

      <div className="app-shell__main">
        <TopBar
          userName={userName}
          userEmail={userEmail}
          apiStatus={apiStatus}
          aiStatus={aiStatus}
          onMenuToggle={onSidebarToggle}
          onLogout={onLogout}
        />

        <ContextBar
          workspaces={workspaces}
          customers={customers}
          deployments={deployments}
          selectedWorkspaceId={selectedWorkspaceId}
          selectedCustomerId={selectedCustomerId}
          selectedDeploymentId={selectedDeploymentId}
          onWorkspaceChange={onWorkspaceChange}
          onCustomerChange={onCustomerChange}
          onDeploymentChange={onDeploymentChange}
          onCreateWorkspace={onCreateWorkspace}
        />

        <main className="app-content">
          <header className="page-header">
            <h1>{viewTitles[activeView]}</h1>
          </header>
          {children}
        </main>
      </div>
    </div>
  )
}
