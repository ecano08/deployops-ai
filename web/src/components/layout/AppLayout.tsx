import type { AppView } from './Sidebar'
import { ContextBar } from './ContextBar'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import { PageHeader } from '../ui/PageHeader'
import type { Customer, Deployment, DeploymentStage, Workspace } from '../../types'

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
  onCreateCustomer: (payload: { name: string; description: string | null }) => Promise<void>
  onUpdateCustomer: (
    customerId: number,
    payload: { name: string; description: string | null },
  ) => Promise<void>
  onDeleteCustomer: (customerId: number) => Promise<void>
  onCreateDeployment: (payload: {
    name: string
    description: string | null
    stage: DeploymentStage
  }) => Promise<void>
  onUpdateDeployment: (
    deploymentId: number,
    payload: { name: string; description: string | null; stage: DeploymentStage },
  ) => Promise<void>
  onUpdateDeploymentStage: (deploymentId: number, stage: DeploymentStage) => Promise<void>
  onDeleteDeployment: (deploymentId: number) => Promise<void>
  pendingApprovals: number
  children: React.ReactNode
}

const viewMeta: Record<AppView, { title: string; description: string }> = {
  dashboard: {
    title: 'Dashboard',
    description: 'Deployment overview, integrations health, and operational signals for your workspace.',
  },
  team: {
    title: 'Team',
    description: 'Manage workspace members, roles, and access for your organization.',
  },
  integrations: {
    title: 'Integrations',
    description: 'Manage connected systems and test API connections for the active deployment.',
  },
  copilot: {
    title: 'AI Copilot',
    description: 'Ask operational questions grounded in deployment context, knowledge, and live data.',
  },
  knowledge: {
    title: 'Project Documentation',
    description: 'Upload and govern engineering documents indexed for copilot when active and ready.',
  },
  intelligence: {
    title: 'Project Intelligence',
    description: 'Review structured facts extracted from documentation with provenance and human verification.',
  },
  evals: {
    title: 'AI Evaluations',
    description: 'Run quality benchmarks and review pass rates against expected copilot behavior.',
  },
  approvals: {
    title: 'Pending Approvals',
    description: 'Review and approve AI-proposed actions before they execute on customer systems.',
  },
  observability: {
    title: 'Observability',
    description: 'Monitor AI health metrics, copilot traces, and operational incidents.',
  },
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
  onCreateCustomer,
  onUpdateCustomer,
  onDeleteCustomer,
  onCreateDeployment,
  onUpdateDeployment,
  onUpdateDeploymentStage,
  onDeleteDeployment,
  pendingApprovals,
  children,
}: AppLayoutProps) {
  const meta = viewMeta[activeView]

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
          onCreateCustomer={onCreateCustomer}
          onUpdateCustomer={onUpdateCustomer}
          onDeleteCustomer={onDeleteCustomer}
          onCreateDeployment={onCreateDeployment}
          onUpdateDeployment={onUpdateDeployment}
          onUpdateDeploymentStage={onUpdateDeploymentStage}
          onDeleteDeployment={onDeleteDeployment}
        />

        <main className="app-content">
          <PageHeader title={meta.title} description={meta.description} />
          {children}
        </main>
      </div>
    </div>
  )
}
