import { useState } from 'react'
import { Check, CheckSquare, Layers, X } from 'lucide-react'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { Icon } from '../components/ui/Icon'
import { LoadingState } from '../components/ui/LoadingState'
import { Alert } from '../components/ui/Alert'
import {
  formatAiActionStatus,
  presentAiActionConfirmMessage,
  presentAiProposedAction,
} from '../lib/aiActionPresentation'
import { canApproveAiActions } from '../lib/permissions'
import type { AiProposedAction, Deployment, WorkspaceRole } from '../types'
import { aiActionRequesterLabel } from '../types'

const SELF_APPROVAL_HINT = 'Another owner or admin must approve your proposal.'

type ApprovalsPageProps = {
  deployment: Deployment | null
  actions: AiProposedAction[]
  loading: boolean
  error: string | null
  message: string | null
  currentUserId: number | null
  workspaceRole: WorkspaceRole | null | undefined
  onApprove: (actionId: number) => Promise<void>
  onReject: (actionId: number) => Promise<void>
}

export function ApprovalsPage({
  deployment,
  actions,
  loading,
  error,
  message,
  currentUserId,
  workspaceRole,
  onApprove,
  onReject,
}: ApprovalsPageProps) {
  const canReviewActions = canApproveAiActions(workspaceRole)
  const [confirmAction, setConfirmAction] = useState<{
    action: AiProposedAction
    type: 'approve' | 'reject'
  } | null>(null)

  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="Review AI-proposed actions that require human approval before execution."
        icon={Layers}
      />
    )
  }

  async function handleConfirm() {
    if (!confirmAction) {
      return
    }

    if (confirmAction.type === 'approve') {
      await onApprove(confirmAction.action.id)
      return
    }

    await onReject(confirmAction.action.id)
  }

  return (
    <div className="page-stack">
      {message && <Alert variant="success">{message}</Alert>}

      {actions.length > 0 && (
        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Pending review</span>
              <span className="stat-card__icon stat-card__icon--warning">
                <Icon icon={CheckSquare} size="sm" />
              </span>
            </div>
            <p className="stat-value">{actions.length}</p>
            <p className="stat-label">actions awaiting approval</p>
          </div>
        </Card>
      )}

      <Card
        title="Pending AI actions"
        description="Human-in-the-loop gate for sensitive copilot tool calls"
      >
        {loading && <LoadingState label="Loading pending actions…" />}
        {error && <ErrorState message={error} />}
        {!loading && !error && actions.length === 0 && (
          <EmptyState
            compact
            title="No pending actions"
            description="When the copilot proposes stage changes or other sensitive operations, they appear here."
            icon={CheckSquare}
          />
        )}
        {!loading && actions.length > 0 && (
          <ul className="data-list">
            {actions.map((action) => {
              const presentation = presentAiProposedAction(action, {
                currentDeploymentStage: deployment?.stage ?? null,
              })

              return (
              <li key={action.id} className="data-list__item data-list__item--stacked">
                <div className="data-list__primary">
                  <div className="data-list__title-row">
                    <strong>{presentation.title}</strong>
                    <Badge variant="warning">{formatAiActionStatus(action.status)}</Badge>
                  </div>
                  {presentation.subtitle && (
                    <p className="data-list__subtitle">{presentation.subtitle}</p>
                  )}
                  <span className="data-list__meta">
                    Requested by {aiActionRequesterLabel(action.requested_by)}
                    {action.requested_by.email ? ` (${action.requested_by.email})` : null}
                  </span>
                </div>
                {canReviewActions && (
                  <div className="button-row">
                    {(() => {
                      const isOwnProposal =
                        currentUserId !== null && action.requested_by.id === currentUserId

                      return (
                        <>
                          <Button
                            variant="primary"
                            size="sm"
                            disabled={isOwnProposal}
                            title={isOwnProposal ? SELF_APPROVAL_HINT : undefined}
                            onClick={() => setConfirmAction({ action, type: 'approve' })}
                          >
                            <Icon icon={Check} size="xs" />
                            Approve
                          </Button>
                          <Button
                            variant="danger"
                            size="sm"
                            onClick={() => setConfirmAction({ action, type: 'reject' })}
                          >
                            <Icon icon={X} size="xs" />
                            Reject
                          </Button>
                        </>
                      )
                    })()}
                  </div>
                )}
              </li>
              )
            })}
          </ul>
        )}
      </Card>

      <ConfirmDialog
        open={confirmAction !== null}
        title={confirmAction?.type === 'approve' ? 'Approve action?' : 'Reject action?'}
        description={
          confirmAction
            ? presentAiActionConfirmMessage(confirmAction.action, confirmAction.type, {
                currentDeploymentStage: deployment?.stage ?? null,
              })
            : ''
        }
        confirmLabel={confirmAction?.type === 'approve' ? 'Approve & execute' : 'Reject'}
        variant={confirmAction?.type === 'approve' ? 'primary' : 'danger'}
        onConfirm={handleConfirm}
        onCancel={() => setConfirmAction(null)}
      />
    </div>
  )
}
