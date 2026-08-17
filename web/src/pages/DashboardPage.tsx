import {
  AlertTriangle,
  BookOpen,
  CheckSquare,
  Layers,
  Plug,
  Server,
  Users,
  Zap,
} from 'lucide-react'
import { Badge } from '../components/ui/Badge'
import { roleBadgeVariant, statusBadgeVariant } from '../components/ui/badgeUtils'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { Icon } from '../components/ui/Icon'
import { LoadingState } from '../components/ui/LoadingState'
import type {
  AiHealthSummary,
  AiProposedAction,
  Customer,
  Deployment,
  DeploymentIntegration,
  Incident,
  KnowledgeDocumentLibraryStats,
  Workspace,
  WorkspaceMember,
} from '../types'
import {
  formatAiActionStatus,
  presentAiProposedAction,
} from '../lib/aiActionPresentation'
import { DEPLOYMENT_STAGES, aiActionRequesterLabel } from '../types'

type DashboardPageProps = {
  workspace: Workspace | null
  customer: Customer | null
  deployment: Deployment | null
  members: WorkspaceMember[]
  membersLoading: boolean
  membersError: string | null
  deployments: Deployment[]
  integrations: DeploymentIntegration[]
  integrationsLoading: boolean
  knowledgeStats: KnowledgeDocumentLibraryStats | null
  knowledgeStatsLoading: boolean
  aiHealth: AiHealthSummary | null
  aiHealthLoading: boolean
  pendingActions: AiProposedAction[]
  incidents: Incident[]
  incidentsLoading: boolean
}

function countConnectedIntegrations(integrations: DeploymentIntegration[]): number {
  return integrations.filter((i) => i.status.toLowerCase() === 'connected').length
}

function countReadyDocuments(stats: KnowledgeDocumentLibraryStats | null): number {
  return stats?.ready_count ?? 0
}

function countRevisionTotal(stats: KnowledgeDocumentLibraryStats | null): number {
  return stats?.revision_total ?? 0
}

export function DashboardPage({
  workspace,
  customer,
  deployment,
  members,
  membersLoading,
  membersError,
  deployments,
  integrations,
  integrationsLoading,
  knowledgeStats,
  knowledgeStatsLoading,
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
        icon={Layers}
      />
    )
  }

  const openIncidents = incidents.filter((incident) => incident.status !== 'resolved')
  const connectedIntegrations = countConnectedIntegrations(integrations)
  const readyDocuments = countReadyDocuments(knowledgeStats)
  const revisionTotal = countRevisionTotal(knowledgeStats)
  const recentIncidents = [...incidents]
    .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
    .slice(0, 4)
  const recentApprovals = pendingActions.slice(0, 3)

  return (
    <div className="page-grid">
      <div className="stat-grid">
        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Deployments</span>
              <span className="stat-card__icon stat-card__icon--accent">
                <Icon icon={Layers} size="sm" />
              </span>
            </div>
            <p className="stat-value">{deployments.length}</p>
            <p className="stat-label">in workspace</p>
          </div>
        </Card>

        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Integrations</span>
              <span className="stat-card__icon stat-card__icon--success">
                <Icon icon={Plug} size="sm" />
              </span>
            </div>
            <p className="stat-value">
              {integrationsLoading ? '…' : `${connectedIntegrations}/${integrations.length}`}
            </p>
            <p className="stat-label">connected</p>
          </div>
        </Card>

        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Knowledge docs</span>
              <span className="stat-card__icon">
                <Icon icon={BookOpen} size="sm" />
              </span>
            </div>
            <p className="stat-value">
              {knowledgeStatsLoading ? '…' : `${readyDocuments}/${revisionTotal}`}
            </p>
            <p className="stat-label">indexed & ready</p>
          </div>
        </Card>

        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Open incidents</span>
              <span
                className={`stat-card__icon ${openIncidents.length > 0 ? 'stat-card__icon--danger' : 'stat-card__icon--success'}`}
              >
                <Icon icon={AlertTriangle} size="sm" />
              </span>
            </div>
            <p className="stat-value">{incidentsLoading ? '…' : openIncidents.length}</p>
            <p className="stat-label">needs attention</p>
          </div>
        </Card>
      </div>

      <div className="page-grid__two-col">
        <Card
          title="Deployment pipeline"
          description={customer ? `${customer.name} deployments by stage` : 'Select a customer'}
        >
          {!customer ? (
            <EmptyState
              compact
              title="Select a customer"
              description="Choose a customer in the context bar to see deployment stages."
              icon={Users}
            />
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

        <div className="page-stack">
          <Card title="Pending approvals" description="Actions awaiting human review">
            {pendingActions.length === 0 ? (
              <EmptyState
                compact
                title="No pending actions"
                description="AI-proposed sensitive operations will appear here."
                icon={CheckSquare}
              />
            ) : (
              <ul className="activity-list">
                {recentApprovals.map((action) => {
                  const presentation = presentAiProposedAction(action, {
                    currentDeploymentStage: deployment?.stage ?? null,
                  })

                  return (
                  <li key={action.id} className="activity-list__item">
                    <span className="activity-list__icon">
                      <Icon icon={CheckSquare} size="xs" />
                    </span>
                    <div className="activity-list__content">
                      <p className="activity-list__title">{presentation.title}</p>
                      <p className="activity-list__meta">
                        {presentation.subtitle && (
                          <>
                            {presentation.subtitle}
                            {' · '}
                          </>
                        )}
                        <Badge variant="warning">{formatAiActionStatus(action.status)}</Badge>
                        {' · '}
                        {aiActionRequesterLabel(action.requested_by)}
                      </p>
                    </div>
                  </li>
                  )
                })}
                {pendingActions.length > 3 && (
                  <p className="data-list__meta">+{pendingActions.length - 3} more pending</p>
                )}
              </ul>
            )}
          </Card>

          <Card title="Recent incidents" description="Latest operational events">
            {incidentsLoading && <LoadingState label="Loading incidents…" />}
            {!incidentsLoading && recentIncidents.length === 0 && (
              <EmptyState
                compact
                title="No incidents"
                description="Operational issues will appear here."
                icon={AlertTriangle}
              />
            )}
            {!incidentsLoading && recentIncidents.length > 0 && (
              <ul className="activity-list">
                {recentIncidents.map((incident) => (
                  <li key={incident.id} className="activity-list__item">
                    <span className="activity-list__icon">
                      <Icon icon={AlertTriangle} size="xs" />
                    </span>
                    <div className="activity-list__content">
                      <p className="activity-list__title">{incident.title}</p>
                      <p className="activity-list__meta">
                        <Badge variant={statusBadgeVariant(incident.severity)}>{incident.severity}</Badge>
                        {' · '}
                        <Badge variant={statusBadgeVariant(incident.status)}>{incident.status}</Badge>
                        {' · '}
                        {new Date(incident.created_at).toLocaleDateString()}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>

      <div className="page-grid__two-col">
        {deployment && (
          <Card title="AI health snapshot" description={`${deployment.name} — last 7 days`}>
            {aiHealthLoading && <LoadingState label="Loading AI metrics…" />}
            {!aiHealthLoading && aiHealth && (
              <div className="metric-grid">
                <div className="metric-item">
                  <span className="metric-label">Requests</span>
                  <strong>{aiHealth.request_count}</strong>
                </div>
                <div className="metric-item">
                  <span className="metric-label">Failure rate</span>
                  <strong>{Math.round(aiHealth.failure_rate * 100)}%</strong>
                </div>
                <div className="metric-item">
                  <span className="metric-label">Avg latency</span>
                  <strong>{aiHealth.average_latency_ms}ms</strong>
                </div>
                <div className="metric-item">
                  <span className="metric-label">Est. cost</span>
                  <strong>${aiHealth.estimated_cost_usd.toFixed(4)}</strong>
                </div>
              </div>
            )}
            {!aiHealthLoading && !aiHealth && (
              <EmptyState
                compact
                title="No AI activity yet"
                description="Ask the copilot to generate traces and metrics."
                icon={Zap}
              />
            )}
          </Card>
        )}

        <Card title="Workspace members" description={workspace.name}>
          {membersLoading && <LoadingState label="Loading members…" />}
          {membersError && <ErrorState message={membersError} />}
          {!membersLoading && !membersError && members.length === 0 && (
            <EmptyState compact title="No members" icon={Users} />
          )}
          {!membersLoading && members.length > 0 && (
            <ul className="data-list">
              {members.map((member) => (
                <li key={member.id} className="data-list__item data-list__item--member">
                  <div className="data-list__member-info">
                    <span className="data-list__member-name">{member.name}</span>
                    <span className="data-list__member-email" title={member.email}>
                      {member.email}
                    </span>
                  </div>
                  <Badge className="data-list__member-role" variant={roleBadgeVariant(member.role)}>
                    {member.role}
                  </Badge>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {deployment && !integrationsLoading && integrations.length > 0 && (
        <Card title="Integration status" description={`Connected systems for ${deployment.name}`}>
          <ul className="data-list">
            {integrations.map((integration) => (
              <li key={integration.id} className="data-list__item">
                <div className="data-list__primary">
                  <div className="data-list__title-row">
                    <strong>{integration.name}</strong>
                    <Badge variant={statusBadgeVariant(integration.status)}>{integration.status}</Badge>
                  </div>
                  <span className="data-list__meta">{integration.type}</span>
                </div>
                <Icon icon={Server} size="sm" />
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}
