import { Badge } from '../components/ui/Badge'
import { statusBadgeVariant } from '../components/ui/badgeUtils'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { LoadingState } from '../components/ui/LoadingState'
import { Alert } from '../components/ui/Alert'
import type { Deployment, DeploymentIntegration } from '../types'

type IntegrationsPageProps = {
  deployment: Deployment | null
  integrations: DeploymentIntegration[]
  loading: boolean
  error: string | null
  testMessage: string | null
  onTest: (integrationId: number) => Promise<void>
}

export function IntegrationsPage({
  deployment,
  integrations,
  loading,
  error,
  testMessage,
  onTest,
}: IntegrationsPageProps) {
  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="Choose a deployment to manage its integration connections."
      />
    )
  }

  return (
    <div className="page-stack">
      {testMessage && <Alert variant="info">{testMessage}</Alert>}

      <Card
        title="Connected systems"
        description={`Integrations for ${deployment.name}`}
      >
        {loading && <LoadingState label="Loading integrations…" />}
        {error && <ErrorState message={error} />}
        {!loading && !error && integrations.length === 0 && (
          <EmptyState
            title="No integrations configured"
            description="Add REST API or webhook integrations via the API to connect customer systems."
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
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => onTest(integration.id)}
                >
                  Test connection
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
