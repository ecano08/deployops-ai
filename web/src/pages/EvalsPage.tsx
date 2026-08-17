import { Activity, Layers, Play } from 'lucide-react'
import { Badge } from '../components/ui/Badge'
import { statusBadgeVariant } from '../components/ui/badgeUtils'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { Icon } from '../components/ui/Icon'
import { LoadingState } from '../components/ui/LoadingState'
import { Alert } from '../components/ui/Alert'
import type { Deployment, EvaluationRun } from '../types'

type EvalsPageProps = {
  deployment: Deployment | null
  runs: EvaluationRun[]
  loading: boolean
  error: string | null
  canRun: boolean
  runMessage: string | null
  onRun: () => Promise<void>
}

export function EvalsPage({
  deployment,
  runs,
  loading,
  error,
  canRun,
  runMessage,
  onRun,
}: EvalsPageProps) {
  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="Run evaluation datasets to measure copilot quality and latency."
        icon={Layers}
      />
    )
  }

  const latestRun = runs[0]
  const passRate = latestRun ? Math.round((latestRun.metrics.pass_rate ?? 0) * 100) : null

  return (
    <div className="page-stack">
      {runMessage && <Alert variant="info">{runMessage}</Alert>}

      {latestRun && (
        <div className="stat-grid" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
          <Card className="stat-card">
            <div className="card__body">
              <div className="stat-card__header">
                <span className="stat-label">Total runs</span>
                <span className="stat-card__icon stat-card__icon--accent">
                  <Icon icon={Activity} size="sm" />
                </span>
              </div>
              <p className="stat-value">{runs.length}</p>
            </div>
          </Card>
          <Card className="stat-card">
            <div className="card__body">
              <div className="stat-card__header">
                <span className="stat-label">Latest pass rate</span>
                <span className="stat-card__icon stat-card__icon--success">
                  <Icon icon={Activity} size="sm" />
                </span>
              </div>
              <p className="stat-value">{passRate !== null ? `${passRate}%` : '—'}</p>
            </div>
          </Card>
          <Card className="stat-card">
            <div className="card__body">
              <div className="stat-card__header">
                <span className="stat-label">Avg latency</span>
                <span className="stat-card__icon">
                  <Icon icon={Activity} size="sm" />
                </span>
              </div>
              <p className="stat-value">{latestRun.metrics.average_latency_ms}ms</p>
            </div>
          </Card>
        </div>
      )}

      <Card
        title="Evaluation runs"
        description="Automated quality checks against expected copilot behavior"
        actions={
          canRun ? (
            <Button variant="primary" size="sm" onClick={onRun}>
              <Icon icon={Play} size="xs" />
              Run dataset
            </Button>
          ) : undefined
        }
      >
        {loading && <LoadingState label="Loading evaluation runs…" />}
        {error && <ErrorState message={error} />}
        {!loading && !error && runs.length === 0 && (
          <EmptyState
            compact
            title="No evaluation runs yet"
            description="Run the evaluation dataset to benchmark copilot responses."
            icon={Activity}
          />
        )}
        {!loading && runs.length > 0 && (
          <ul className="data-list">
            {runs.map((run) => (
              <li key={run.id} className="data-list__item data-list__item--stacked">
                <div className="data-list__primary">
                  <div className="data-list__title-row">
                    <strong>Run #{run.id}</strong>
                    <Badge variant={statusBadgeVariant(run.status)}>{run.status}</Badge>
                  </div>
                  <span className="data-list__meta">
                    Pass rate {Math.round((run.metrics.pass_rate ?? 0) * 100)}% ·{' '}
                    {run.metrics.passed_cases}/{run.metrics.total_cases} passed · avg{' '}
                    {run.metrics.average_latency_ms}ms
                  </span>
                  {run.results && run.results.length > 0 && (
                    <ul className="nested-list">
                      {run.results.map((result) => (
                        <li key={result.id}>
                          Case #{result.evaluation_case_id}:{' '}
                          <Badge variant={result.passed ? 'success' : 'danger'}>
                            {result.passed ? 'passed' : 'failed'}
                          </Badge>{' '}
                          ({result.latency_ms}ms)
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
