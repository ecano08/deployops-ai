import { Badge } from '../components/ui/Badge'
import { statusBadgeVariant } from '../components/ui/badgeUtils'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { LoadingState } from '../components/ui/LoadingState'
import type { AiHealthSummary, AiTrace, Deployment, Incident } from '../types'

type ObservabilityPageProps = {
  deployment: Deployment | null
  aiHealth: AiHealthSummary | null
  aiHealthLoading: boolean
  aiHealthError: string | null
  traces: AiTrace[]
  tracesLoading: boolean
  tracesError: string | null
  incidents: Incident[]
  incidentsLoading: boolean
  incidentsError: string | null
}

export function ObservabilityPage({
  deployment,
  aiHealth,
  aiHealthLoading,
  aiHealthError,
  traces,
  tracesLoading,
  tracesError,
  incidents,
  incidentsLoading,
  incidentsError,
}: ObservabilityPageProps) {
  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="View AI traces, health metrics, and operational incidents."
      />
    )
  }

  return (
    <div className="page-stack">
      <Card title="AI health metrics" description="Rolling 7-day summary">
        {aiHealthLoading && <LoadingState label="Loading health metrics…" />}
        {aiHealthError && <ErrorState message={aiHealthError} />}
        {!aiHealthLoading && aiHealth && (
          <div className="metric-grid metric-grid--wide">
            <div>
              <span className="metric-label">Requests</span>
              <strong>{aiHealth.request_count}</strong>
            </div>
            <div>
              <span className="metric-label">Failures</span>
              <strong>
                {aiHealth.failure_count} ({Math.round(aiHealth.failure_rate * 100)}%)
              </strong>
            </div>
            <div>
              <span className="metric-label">Avg latency</span>
              <strong>{aiHealth.average_latency_ms}ms</strong>
            </div>
            <div>
              <span className="metric-label">Input tokens</span>
              <strong>{aiHealth.total_input_tokens}</strong>
            </div>
            <div>
              <span className="metric-label">Output tokens</span>
              <strong>{aiHealth.total_output_tokens}</strong>
            </div>
            <div>
              <span className="metric-label">Est. cost</span>
              <strong>${aiHealth.estimated_cost_usd.toFixed(6)}</strong>
            </div>
            <div>
              <span className="metric-label">Tool failures</span>
              <strong>{aiHealth.tool_failure_count}</strong>
            </div>
            <div>
              <span className="metric-label">RAG requests</span>
              <strong>{aiHealth.rag_request_count}</strong>
            </div>
          </div>
        )}
        {!aiHealthLoading && !aiHealth && !aiHealthError && (
          <EmptyState title="No metrics yet" />
        )}
      </Card>

      <Card title="Recent copilot traces">
        {tracesLoading && <LoadingState label="Loading traces…" />}
        {tracesError && <ErrorState message={tracesError} />}
        {!tracesLoading && traces.length === 0 && !tracesError && (
          <EmptyState title="No traces recorded" />
        )}
        {!tracesLoading && traces.length > 0 && (
          <ul className="data-list">
            {traces.map((trace) => (
              <li key={trace.id} className="data-list__item data-list__item--stacked">
                <div className="data-list__primary">
                  <div className="data-list__title-row">
                    <strong>Trace #{trace.id}</strong>
                    <Badge variant={statusBadgeVariant(trace.status)}>{trace.status}</Badge>
                  </div>
                  <span className="data-list__meta">
                    {trace.model} · {trace.latency_ms}ms
                    {trace.rag_used ? ' · RAG used' : ''}
                  </span>
                  {trace.tool_names.length > 0 && (
                    <div className="tool-tags">
                      {trace.tool_names.map((tool) => (
                        <Badge key={tool} variant="info">
                          {tool}
                        </Badge>
                      ))}
                    </div>
                  )}
                  {trace.error_message && (
                    <span className="data-list__meta data-list__meta--error">{trace.error_message}</span>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="Incidents">
        {incidentsLoading && <LoadingState label="Loading incidents…" />}
        {incidentsError && <ErrorState message={incidentsError} />}
        {!incidentsLoading && incidents.length === 0 && !incidentsError && (
          <EmptyState title="No incidents" description="Operational issues will appear here." />
        )}
        {!incidentsLoading && incidents.length > 0 && (
          <ul className="data-list">
            {incidents.map((incident) => (
              <li key={incident.id} className="data-list__item data-list__item--stacked">
                <div className="data-list__primary">
                  <div className="data-list__title-row">
                    <strong>{incident.title}</strong>
                    <div className="badge-row">
                      <Badge variant={statusBadgeVariant(incident.severity)}>{incident.severity}</Badge>
                      <Badge variant={statusBadgeVariant(incident.status)}>{incident.status}</Badge>
                    </div>
                  </div>
                  <p className="incident-description">{incident.description}</p>
                  <span className="data-list__meta">
                    Source: {incident.source} · {new Date(incident.created_at).toLocaleString()}
                  </span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
