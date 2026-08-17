import { useState } from 'react'
import { Activity, Layers, Play, Plus, Trash2 } from 'lucide-react'
import { EvaluationCaseFormDialog } from '../components/forms/EvaluationCaseFormDialog'
import { EvaluationDatasetFormDialog } from '../components/forms/EvaluationDatasetFormDialog'
import { canManageDeployments } from '../lib/permissions'
import type { Deployment, EvaluationCase, EvaluationDataset, EvaluationRun, Workspace } from '../types'
import { Badge } from '../components/ui/Badge'
import { statusBadgeVariant } from '../components/ui/badgeUtils'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { FormField, FormSelect } from '../components/ui/FormField'
import { Icon } from '../components/ui/Icon'
import { LoadingState } from '../components/ui/LoadingState'
import { Alert } from '../components/ui/Alert'

type EvalsPageProps = {
  workspace: Workspace | null
  deployment: Deployment | null
  datasets: EvaluationDataset[]
  datasetsLoading: boolean
  datasetsError: string | null
  selectedDatasetId: number | null
  onSelectDataset: (datasetId: number) => void
  runs: EvaluationRun[]
  runsLoading: boolean
  runsError: string | null
  runMessage: string | null
  onCreateDataset: (payload: { name: string; description: string | null }) => Promise<void>
  onUpdateDataset: (
    datasetId: number,
    payload: { name: string; description: string | null },
  ) => Promise<void>
  onDeleteDataset: (datasetId: number) => Promise<void>
  onCreateCase: (
    datasetId: number,
    payload: {
      input: string
      expected_behavior: string
      expected_tools: string[] | null
      expected_sources: string[] | null
    },
  ) => Promise<void>
  onUpdateCase: (
    datasetId: number,
    caseId: number,
    payload: {
      input: string
      expected_behavior: string
      expected_tools: string[] | null
      expected_sources: string[] | null
    },
  ) => Promise<void>
  onDeleteCase: (datasetId: number, caseId: number) => Promise<void>
  onRun: (datasetId: number) => Promise<void>
}

export function EvalsPage({
  workspace,
  deployment,
  datasets,
  datasetsLoading,
  datasetsError,
  selectedDatasetId,
  onSelectDataset,
  runs,
  runsLoading,
  runsError,
  runMessage,
  onCreateDataset,
  onUpdateDataset,
  onDeleteDataset,
  onCreateCase,
  onUpdateCase,
  onDeleteCase,
  onRun,
}: EvalsPageProps) {
  const [datasetDialogMode, setDatasetDialogMode] = useState<'create' | 'edit' | null>(null)
  const [editingDataset, setEditingDataset] = useState<EvaluationDataset | null>(null)
  const [deleteDatasetTarget, setDeleteDatasetTarget] = useState<EvaluationDataset | null>(null)
  const [caseDialogMode, setCaseDialogMode] = useState<'create' | 'edit' | null>(null)
  const [editingCase, setEditingCase] = useState<EvaluationCase | null>(null)
  const [deleteCaseTarget, setDeleteCaseTarget] = useState<EvaluationCase | null>(null)
  const [savingDataset, setSavingDataset] = useState(false)
  const [savingCase, setSavingCase] = useState(false)
  const [running, setRunning] = useState(false)

  const canManage = canManageDeployments(workspace?.current_user_role)
  const selectedDataset =
    datasets.find((dataset) => dataset.id === selectedDatasetId) ?? datasets[0] ?? null
  const cases = selectedDataset?.cases ?? []
  const datasetRuns = selectedDataset
    ? runs.filter((run) => run.evaluation_dataset_id === selectedDataset.id)
    : []
  const latestRun = datasetRuns[0]
  const passRate = latestRun ? Math.round((latestRun.metrics.pass_rate ?? 0) * 100) : null
  const loading = datasetsLoading || runsLoading
  const error = datasetsError ?? runsError

  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="Run evaluation datasets to measure copilot quality and latency."
        icon={Layers}
      />
    )
  }

  async function handleDatasetSubmit(payload: { name: string; description: string | null }) {
    setSavingDataset(true)

    try {
      if (datasetDialogMode === 'edit' && editingDataset) {
        await onUpdateDataset(editingDataset.id, payload)
      } else {
        await onCreateDataset(payload)
      }

      setDatasetDialogMode(null)
      setEditingDataset(null)
    } finally {
      setSavingDataset(false)
    }
  }

  async function confirmDeleteDataset() {
    if (!deleteDatasetTarget) {
      return
    }

    await onDeleteDataset(deleteDatasetTarget.id)
  }

  async function handleCaseSubmit(payload: {
    input: string
    expected_behavior: string
    expected_tools: string[] | null
    expected_sources: string[] | null
  }) {
    if (!selectedDataset) {
      return
    }

    setSavingCase(true)

    try {
      if (caseDialogMode === 'edit' && editingCase) {
        await onUpdateCase(selectedDataset.id, editingCase.id, payload)
      } else {
        await onCreateCase(selectedDataset.id, payload)
      }

      setCaseDialogMode(null)
      setEditingCase(null)
    } finally {
      setSavingCase(false)
    }
  }

  async function confirmDeleteCase() {
    if (!deleteCaseTarget || !selectedDataset) {
      return
    }

    await onDeleteCase(selectedDataset.id, deleteCaseTarget.id)
  }

  async function handleRun() {
    if (!selectedDataset) {
      return
    }

    setRunning(true)

    try {
      await onRun(selectedDataset.id)
    } finally {
      setRunning(false)
    }
  }

  return (
    <div className="page-stack">
      {runMessage && (
        <Alert variant={runMessage.toLowerCase().includes('failed') ? 'error' : 'info'}>
          {runMessage}
        </Alert>
      )}

      {latestRun && (
        <div className="stat-grid" style={{ gridTemplateColumns: 'repeat(4, 1fr)' }}>
          <Card className="stat-card">
            <div className="card__body">
              <div className="stat-card__header">
                <span className="stat-label">Total cases</span>
                <span className="stat-card__icon stat-card__icon--accent">
                  <Icon icon={Activity} size="sm" />
                </span>
              </div>
              <p className="stat-value">{latestRun.metrics.total_cases}</p>
            </div>
          </Card>
          <Card className="stat-card">
            <div className="card__body">
              <div className="stat-card__header">
                <span className="stat-label">Passed cases</span>
                <span className="stat-card__icon stat-card__icon--success">
                  <Icon icon={Activity} size="sm" />
                </span>
              </div>
              <p className="stat-value">{latestRun.metrics.passed_cases}</p>
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
        title="Evaluation datasets"
        description="Benchmark copilot responses against expected behavior for this deployment"
        actions={
          canManage ? (
            <Button
              variant="primary"
              size="sm"
              onClick={() => {
                setEditingDataset(null)
                setDatasetDialogMode('create')
              }}
            >
              <Icon icon={Plus} size="xs" />
              Create dataset
            </Button>
          ) : undefined
        }
      >
        {datasetsLoading && <LoadingState label="Loading evaluation datasets…" />}
        {datasetsError && <ErrorState message={datasetsError} />}
        {!datasetsLoading && !datasetsError && datasets.length === 0 && (
          <EmptyState
            compact
            title="No evaluation dataset yet"
            description="An evaluation dataset groups test prompts and expected copilot behavior. Create one to start measuring quality."
            icon={Layers}
            action={
              canManage ? (
                <Button
                  variant="primary"
                  size="sm"
                  onClick={() => {
                    setEditingDataset(null)
                    setDatasetDialogMode('create')
                  }}
                >
                  Create evaluation dataset
                </Button>
              ) : undefined
            }
          />
        )}
        {!datasetsLoading && datasets.length > 0 && (
          <div className="page-stack">
            <div className="data-list__actions">
              <FormField label="Selected dataset" hideLabel={datasets.length === 1}>
                <FormSelect
                  id="evaluation-dataset-select"
                  value={selectedDataset?.id ?? ''}
                  onChange={(event) => onSelectDataset(Number(event.target.value))}
                >
                  {datasets.map((dataset) => (
                    <option key={dataset.id} value={dataset.id}>
                      {dataset.name}
                    </option>
                  ))}
                </FormSelect>
              </FormField>
              {canManage && selectedDataset && (
                <>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setEditingDataset(selectedDataset)
                      setDatasetDialogMode('edit')
                    }}
                  >
                    Edit dataset
                  </Button>
                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => setDeleteDatasetTarget(selectedDataset)}
                  >
                    Delete dataset
                  </Button>
                </>
              )}
            </div>
            {selectedDataset?.description && (
              <p className="data-list__meta">{selectedDataset.description}</p>
            )}
          </div>
        )}
      </Card>

      {selectedDataset && (
        <Card
          title="Evaluation cases"
          description={`Test prompts for ${selectedDataset.name}`}
          actions={
            canManage ? (
              <Button
                variant="primary"
                size="sm"
                onClick={() => {
                  setEditingCase(null)
                  setCaseDialogMode('create')
                }}
              >
                <Icon icon={Plus} size="xs" />
                Add case
              </Button>
            ) : undefined
          }
        >
          {loading && <LoadingState label="Loading evaluation cases…" />}
          {error && <ErrorState message={error} />}
          {!loading && !error && cases.length === 0 && (
            <EmptyState
              compact
              title="No evaluation cases yet"
              description="Add prompts and expected behavior so the copilot can be scored automatically."
              icon={Activity}
              action={
                canManage ? (
                  <Button
                    variant="primary"
                    size="sm"
                    onClick={() => {
                      setEditingCase(null)
                      setCaseDialogMode('create')
                    }}
                  >
                    Add evaluation case
                  </Button>
                ) : undefined
              }
            />
          )}
          {!loading && cases.length > 0 && (
            <ul className="data-list">
              {cases.map((evaluationCase) => (
                <li key={evaluationCase.id} className="data-list__item data-list__item--stacked">
                  <div className="data-list__primary">
                    <div className="data-list__title-row">
                      <strong>Case #{evaluationCase.id}</strong>
                    </div>
                    <span className="data-list__meta">
                      <strong>Input:</strong> {evaluationCase.input}
                    </span>
                    <span className="data-list__meta">
                      <strong>Expected behavior:</strong> {evaluationCase.expected_behavior}
                    </span>
                    {evaluationCase.expected_tools && evaluationCase.expected_tools.length > 0 && (
                      <span className="data-list__meta">
                        <strong>Expected tools:</strong> {evaluationCase.expected_tools.join(', ')}
                      </span>
                    )}
                    {evaluationCase.expected_sources &&
                      evaluationCase.expected_sources.length > 0 && (
                        <span className="data-list__meta">
                          <strong>Expected sources:</strong>{' '}
                          {evaluationCase.expected_sources.join(', ')}
                        </span>
                      )}
                  </div>
                  {canManage && (
                    <div className="data-list__actions">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          setEditingCase(evaluationCase)
                          setCaseDialogMode('edit')
                        }}
                      >
                        Edit
                      </Button>
                      <Button
                        variant="danger"
                        size="sm"
                        onClick={() => setDeleteCaseTarget(evaluationCase)}
                      >
                        <Icon icon={Trash2} size="xs" />
                        Delete
                      </Button>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {selectedDataset && cases.length > 0 && (
        <Card
          title="Evaluation runs"
          description="Automated quality checks against expected copilot behavior"
          actions={
            canManage ? (
              <Button
                variant="primary"
                size="sm"
                loading={running}
                disabled={running}
                onClick={handleRun}
              >
                <Icon icon={Play} size="xs" />
                Run evaluation
              </Button>
            ) : undefined
          }
        >
          {runsLoading && <LoadingState label="Loading evaluation runs…" />}
          {runsError && <ErrorState message={runsError} />}
          {!runsLoading && !runsError && datasetRuns.length === 0 && (
            <EmptyState
              compact
              title="No evaluation runs yet"
              description="Runs appear here after you execute the dataset. Each run scores every case and records pass rate and latency."
              icon={Activity}
              action={
                canManage ? (
                  <Button
                    variant="primary"
                    size="sm"
                    loading={running}
                    disabled={running}
                    onClick={handleRun}
                  >
                    Run evaluation
                  </Button>
                ) : undefined
              }
            />
          )}
          {!runsLoading && datasetRuns.length > 0 && (
            <ul className="data-list">
              {datasetRuns.map((run) => (
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
                            {result.error_message && (
                              <span className="data-list__meta data-list__meta--error">
                                {' '}
                                {result.error_message}
                              </span>
                            )}
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
      )}

      {datasetDialogMode !== null && (
        <EvaluationDatasetFormDialog
          key={datasetDialogMode === 'edit' ? editingDataset?.id : 'create'}
          dataset={datasetDialogMode === 'edit' ? editingDataset : null}
          loading={savingDataset}
          onSubmit={handleDatasetSubmit}
          onCancel={() => {
            setDatasetDialogMode(null)
            setEditingDataset(null)
          }}
        />
      )}

      {caseDialogMode !== null && (
        <EvaluationCaseFormDialog
          key={caseDialogMode === 'edit' ? editingCase?.id : 'create'}
          evaluationCase={caseDialogMode === 'edit' ? editingCase : null}
          loading={savingCase}
          onSubmit={handleCaseSubmit}
          onCancel={() => {
            setCaseDialogMode(null)
            setEditingCase(null)
          }}
        />
      )}

      <ConfirmDialog
        open={deleteDatasetTarget !== null}
        title="Delete evaluation dataset?"
        description={`This will remove "${deleteDatasetTarget?.name}" and all of its cases. This cannot be undone.`}
        confirmLabel="Delete dataset"
        onConfirm={confirmDeleteDataset}
        onCancel={() => setDeleteDatasetTarget(null)}
      />

      <ConfirmDialog
        open={deleteCaseTarget !== null}
        title="Delete evaluation case?"
        description="This case will be removed from the dataset. This cannot be undone."
        confirmLabel="Delete case"
        onConfirm={confirmDeleteCase}
        onCancel={() => setDeleteCaseTarget(null)}
      />
    </div>
  )
}
