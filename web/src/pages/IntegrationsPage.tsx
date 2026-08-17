import { useState } from 'react'
import { Layers, Plug, Wifi } from 'lucide-react'
import { canManageDeployments } from '../lib/permissions'
import type { Deployment, DeploymentIntegration, Workspace } from '../types'
import { IntegrationFormDialog } from '../components/forms/IntegrationFormDialog'
import { Badge } from '../components/ui/Badge'
import { statusBadgeVariant } from '../components/ui/badgeUtils'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { Icon } from '../components/ui/Icon'
import { LoadingState } from '../components/ui/LoadingState'
import { Alert } from '../components/ui/Alert'

type IntegrationsPageProps = {
  workspace: Workspace | null
  deployment: Deployment | null
  integrations: DeploymentIntegration[]
  loading: boolean
  error: string | null
  testMessage: string | null
  onCreate: (payload: {
    type: 'rest_api' | 'webhook'
    name: string
    base_url?: string | null
    endpoint?: string | null
    api_key?: string
    webhook_secret?: string
  }) => Promise<void>
  onUpdate: (
    integrationId: number,
    payload: {
      name: string
      base_url?: string | null
      endpoint?: string | null
      api_key?: string
      webhook_secret?: string
    },
  ) => Promise<void>
  onDelete: (integrationId: number) => Promise<void>
  onTest: (integrationId: number) => Promise<void>
}

export function IntegrationsPage({
  workspace,
  deployment,
  integrations,
  loading,
  error,
  testMessage,
  onCreate,
  onUpdate,
  onDelete,
  onTest,
}: IntegrationsPageProps) {
  const [dialogMode, setDialogMode] = useState<'create' | 'edit' | null>(null)
  const [editingIntegration, setEditingIntegration] = useState<DeploymentIntegration | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<DeploymentIntegration | null>(null)
  const [saving, setSaving] = useState(false)
  const [testingId, setTestingId] = useState<number | null>(null)

  const canManage = canManageDeployments(workspace?.current_user_role)

  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="Choose a deployment to manage its integration connections."
        icon={Layers}
      />
    )
  }

  const connectedCount = integrations.filter((i) => i.status.toLowerCase() === 'connected').length

  async function handleSubmit(payload: {
    type: 'rest_api' | 'webhook'
    name: string
    base_url: string | null
    endpoint: string | null
    api_key: string
    webhook_secret: string
  }) {
    setSaving(true)

    try {
      if (dialogMode === 'edit' && editingIntegration) {
        const updatePayload: {
          name: string
          base_url?: string | null
          endpoint?: string | null
          api_key?: string
          webhook_secret?: string
        } = {
          name: payload.name,
          endpoint: payload.endpoint,
        }

        if (payload.type === 'rest_api') {
          updatePayload.base_url = payload.base_url

          if (payload.api_key.trim() !== '') {
            updatePayload.api_key = payload.api_key
          }
        }

        if (payload.type === 'webhook' && payload.webhook_secret.trim() !== '') {
          updatePayload.webhook_secret = payload.webhook_secret
        }

        await onUpdate(editingIntegration.id, updatePayload)
      } else {
        const createPayload: {
          type: 'rest_api' | 'webhook'
          name: string
          base_url?: string | null
          endpoint?: string | null
          api_key?: string
          webhook_secret?: string
        } = {
          type: payload.type,
          name: payload.name,
          endpoint: payload.endpoint,
        }

        if (payload.type === 'rest_api') {
          createPayload.base_url = payload.base_url

          if (payload.api_key.trim() !== '') {
            createPayload.api_key = payload.api_key
          }
        }

        if (payload.type === 'webhook' && payload.webhook_secret.trim() !== '') {
          createPayload.webhook_secret = payload.webhook_secret
        }

        await onCreate(createPayload)
      }

      setDialogMode(null)
      setEditingIntegration(null)
    } finally {
      setSaving(false)
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) {
      return
    }

    await onDelete(deleteTarget.id)
  }

  async function handleTest(integrationId: number) {
    setTestingId(integrationId)

    try {
      await onTest(integrationId)
    } finally {
      setTestingId(null)
    }
  }

  return (
    <div className="page-stack">
      {testMessage && <Alert variant="info">{testMessage}</Alert>}

      <div className="stat-grid" style={{ gridTemplateColumns: 'repeat(2, 1fr)' }}>
        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Total integrations</span>
              <span className="stat-card__icon stat-card__icon--accent">
                <Icon icon={Plug} size="sm" />
              </span>
            </div>
            <p className="stat-value">{loading ? '…' : integrations.length}</p>
          </div>
        </Card>
        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Connected</span>
              <span className="stat-card__icon stat-card__icon--success">
                <Icon icon={Wifi} size="sm" />
              </span>
            </div>
            <p className="stat-value">{loading ? '…' : connectedCount}</p>
          </div>
        </Card>
      </div>

      <Card
        title="Connected systems"
        description={`Integrations for ${deployment.name}`}
        actions={
          canManage ? (
            <Button
              variant="primary"
              size="sm"
              onClick={() => {
                setEditingIntegration(null)
                setDialogMode('create')
              }}
            >
              Add integration
            </Button>
          ) : undefined
        }
      >
        {loading && <LoadingState label="Loading integrations…" />}
        {error && <ErrorState message={error} />}
        {!loading && !error && integrations.length === 0 && (
          <EmptyState
            compact
            title="No integrations configured"
            description="Connect REST APIs or webhooks to link customer systems."
            icon={Plug}
            action={
              canManage ? (
                <Button
                  variant="primary"
                  size="sm"
                  onClick={() => {
                    setEditingIntegration(null)
                    setDialogMode('create')
                  }}
                >
                  Add integration
                </Button>
              ) : undefined
            }
          />
        )}
        {!loading && integrations.length > 0 && (
          <ul className="data-list">
            {integrations.map((integration) => (
              <li key={integration.id} className="data-list__item data-list__item--stacked">
                <div className="data-list__primary">
                  <div className="data-list__title-row">
                    <strong>{integration.name}</strong>
                    <Badge variant={statusBadgeVariant(integration.status)}>{integration.status}</Badge>
                  </div>
                  <span className="data-list__meta">
                    {integration.type}
                    {integration.base_url ? ` · ${integration.base_url}` : ''}
                    {integration.endpoint ? integration.endpoint : ''}
                  </span>
                  <span className="data-list__meta">
                    {integration.has_api_key ? 'API key configured' : 'No API key'}
                    {' · '}
                    {integration.has_webhook_secret ? 'Webhook secret set' : 'No webhook secret'}
                  </span>
                </div>
                <div className="data-list__actions">
                  <Button
                    variant="secondary"
                    size="sm"
                    loading={testingId === integration.id}
                    onClick={() => handleTest(integration.id)}
                  >
                    <Icon icon={Wifi} size="xs" />
                    Test connection
                  </Button>
                  {canManage && (
                    <>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          setEditingIntegration(integration)
                          setDialogMode('edit')
                        }}
                      >
                        Edit
                      </Button>
                      <Button
                        variant="danger"
                        size="sm"
                        onClick={() => setDeleteTarget(integration)}
                      >
                        Delete
                      </Button>
                    </>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {dialogMode !== null && (
        <IntegrationFormDialog
          key={dialogMode === 'edit' ? editingIntegration?.id : 'create'}
          integration={dialogMode === 'edit' ? editingIntegration : null}
          loading={saving}
          onSubmit={handleSubmit}
          onCancel={() => {
            setDialogMode(null)
            setEditingIntegration(null)
          }}
        />
      )}

      <ConfirmDialog
        open={deleteTarget !== null}
        title="Delete integration?"
        description={`This will remove "${deleteTarget?.name}" and stop inbound or outbound traffic for this connection.`}
        confirmLabel="Delete integration"
        onConfirm={confirmDelete}
        onCancel={() => setDeleteTarget(null)}
      />
    </div>
  )
}
