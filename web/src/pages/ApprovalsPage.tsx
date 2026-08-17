import { useState } from 'react'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { LoadingState } from '../components/ui/LoadingState'
import { Alert } from '../components/ui/Alert'
import type { AiProposedAction, Deployment } from '../types'

type ApprovalsPageProps = {
  deployment: Deployment | null
  actions: AiProposedAction[]
  loading: boolean
  error: string | null
  message: string | null
  onApprove: (actionId: number) => Promise<void>
  onReject: (actionId: number) => Promise<void>
}

export function ApprovalsPage({
  deployment,
  actions,
  loading,
  error,
  message,
  onApprove,
  onReject,
}: ApprovalsPageProps) {
  const [confirmAction, setConfirmAction] = useState<{
    action: AiProposedAction
    type: 'approve' | 'reject'
  } | null>(null)
  const [processing, setProcessing] = useState(false)

  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="Review AI-proposed actions that require human approval before execution."
      />
    )
  }

  async function handleConfirm() {
    if (!confirmAction) {
      return
    }

    setProcessing(true)

    try {
      if (confirmAction.type === 'approve') {
        await onApprove(confirmAction.action.id)
      } else {
        await onReject(confirmAction.action.id)
      }

      setConfirmAction(null)
    } finally {
      setProcessing(false)
    }
  }

  return (
    <div className="page-stack">
      {message && <Alert variant="info">{message}</Alert>}

      <Card
        title="Pending AI actions"
        description="Human-in-the-loop gate for sensitive copilot tool calls"
      >
        {loading && <LoadingState label="Loading pending actions…" />}
        {error && <ErrorState message={error} />}
        {!loading && !error && actions.length === 0 && (
          <EmptyState
            title="No pending actions"
            description="When the copilot proposes stage changes or other sensitive operations, they appear here."
          />
        )}
        {!loading && actions.length > 0 && (
          <ul className="data-list">
            {actions.map((action) => (
              <li key={action.id} className="data-list__item data-list__item--stacked">
                <div className="data-list__primary">
                  <div className="data-list__title-row">
                    <strong>{action.action_type}</strong>
                    <Badge variant="warning">{action.status}</Badge>
                  </div>
                  <code className="payload-preview">{JSON.stringify(action.payload)}</code>
                  <span className="data-list__meta">Requested by user #{action.requested_by}</span>
                </div>
                <div className="button-row">
                  <Button
                    variant="primary"
                    size="sm"
                    onClick={() => setConfirmAction({ action, type: 'approve' })}
                  >
                    Approve
                  </Button>
                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => setConfirmAction({ action, type: 'reject' })}
                  >
                    Reject
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <ConfirmDialog
        open={confirmAction !== null}
        title={confirmAction?.type === 'approve' ? 'Approve action?' : 'Reject action?'}
        description={
          confirmAction?.type === 'approve'
            ? `This will execute "${confirmAction.action.action_type}" immediately.`
            : `This will reject "${confirmAction?.action.action_type}" and it will not be executed.`
        }
        confirmLabel={confirmAction?.type === 'approve' ? 'Approve & execute' : 'Reject'}
        variant={confirmAction?.type === 'approve' ? 'primary' : 'danger'}
        loading={processing}
        onConfirm={handleConfirm}
        onCancel={() => setConfirmAction(null)}
      />
    </div>
  )
}
