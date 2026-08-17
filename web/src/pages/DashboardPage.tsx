import { Badge } from '../components/ui/Badge'
import { roleBadgeVariant, statusBadgeVariant } from '../components/ui/badgeUtils'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { LoadingState } from '../components/ui/LoadingState'
import type {
  AiHealthSummary,
  AiProposedAction,
  Customer,
  Deployment,
  Incident,
  Workspace,
  WorkspaceMember,
} from '../types'
import { DEPLOYMENT_STAGES } from '../types'

type DashboardPageProps = {
  workspace: Workspace | null
  customer: Customer | null
  deployment: Deployment | null
  members: WorkspaceMember[]
  membersLoading: boolean
  membersError: string | null
  deployments: Deployment[]
  aiHealth: AiHealthSummary | null
  aiHealthLoading: boolean
  pendingActions: AiProposedAction[]
  incidents: Incident[]
  incidentsLoading: boolean
}

export function DashboardPage({
  workspace,
  customer,
  deployment,
  members,
  membersLoading,
  membersError,
  deployments,
  aiHealth,
  aiHealthLoading,
  pendingActions,
  incidents,
  incidentsLoading,
}: DashboardPageProps) {
  if (!workspace) {
    return (
      <EmptyState
        title="No workspace selected"
        description="Create or select a workspace to view your deployment overview."
      />
    )
  }

  const openIncidents = incidents.filter((incident) => incident.status !== 'resolved')

  return (
    <div className="page-grid">
      <div className="stat-grid">
        <Card title="Customers" className="stat-card">
          <p className="stat-value">{customer ? 1 : 0}</p>
          <p className="stat-label">active context</p>
        </Card>
        <Card title="Deployments" className="stat-card">
          <p className="stat-value">{deployments.length}</p>
          <p className="stat-label">in workspace</p>
        </Card>
        <Card title="Pending approvals" className="stat-card">
          <p className="stat-value">{pendingActions.length}</p>
          <p className="stat-label">awaiting review</p>
        </Card>
        <Card title="Open incidents" className="stat-card">
          <p className="stat-value">{incidentsLoading ? '…' : openIncidents.length}</p>
          <p className="stat-label">needs attention</p>
        </Card>
      </div>

      <div className="page-grid__two-col">
        <Card
          title="Deployment pipeline"
          description={customer ? `${customer.name} deployments by stage` : 'Select a customer'}
        >
          {!customer ? (
            <EmptyState title="Select a customer" description="Choose a customer to see deployment stages." />
          ) : (
            <div className="pipeline">
              {DEPLOYMENT_STAGES.map((stage) => {
                const stageDeployments = deployments.filter((item) => item.stage === stage)

                return (
                  <div key={stage} className="pipeline__stage">
                    <div className="pipeline__stage-header">
                      <Badge variant={statusBadgeVariant(stage)}>{stage}</Badge>
                      <span className="pipeline__count">{stageDeployments.length}</span>
                    </div>
                    {stageDeployments.length > 0 ? (
                      <ul className="pipeline__list">
                        {stageDeployments.map((item) => (
                          <li key={item.id} className={deployment?.id === item.id ? 'is-active' : ''}>
                            <strong>{item.name}</strong>
                            {item.description && <span>{item.description}</span>}
                          </li>
                        ))}
                      </ul>
                    ) : (
                      <p className="pipeline__empty">No deployments</p>
                    )}
                  </div>
                )
              })}
            </div>
          )}
        </Card>

        <Card title="Workspace members" description={workspace.name}>
          {membersLoading && <LoadingState label="Loading members…" />}
          {membersError && <ErrorState message={membersError} />}
          {!membersLoading && !membersError && members.length === 0 && (
            <EmptyState title="No members" />
          )}
          {!membersLoading && members.length > 0 && (
            <ul className="data-list">
              {members.map((member) => (
                <li key={member.id} className="data-list__item">
                  <div>
                    <strong>{member.name}</strong>
                    <span>{member.email}</span>
                  </div>
                  <Badge variant={roleBadgeVariant(member.role)}>{member.role}</Badge>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {deployment && (
        <Card title="AI health snapshot" description={`${deployment.name} — last 7 days`}>
          {aiHealthLoading && <LoadingState label="Loading AI metrics…" />}
          {!aiHealthLoading && aiHealth && (
            <div className="metric-grid">
              <div>
                <span className="metric-label">Requests</span>
                <strong>{aiHealth.request_count}</strong>
              </div>
              <div>
                <span className="metric-label">Failure rate</span>
                <strong>{Math.round(aiHealth.failure_rate * 100)}%</strong>
              </div>
              <div>
                <span className="metric-label">Avg latency</span>
                <strong>{aiHealth.average_latency_ms}ms</strong>
              </div>
              <div>
                <span className="metric-label">Est. cost</span>
                <strong>${aiHealth.estimated_cost_usd.toFixed(4)}</strong>
              </div>
            </div>
          )}
          {!aiHealthLoading && !aiHealth && (
            <EmptyState title="No AI activity yet" description="Ask the copilot to generate traces." />
          )}
        </Card>
      )}
    </div>
  )
}
