import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { Brain, Check, Eye, Layers, Plus, Search, Sparkles, X } from 'lucide-react'
import {
  buildGroundedContext,
  bulkRejectProjectFacts,
  bulkVerifyProjectFacts,
  createProjectFact,
  extractProjectFacts,
  fetchKnowledgeDocuments,
  fetchProjectFactExtraction,
  fetchProjectFacts,
  rejectProjectFact,
  verifyProjectFact,
} from '../api'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { FormField, FormInput, FormSelect, FormTextarea } from '../components/ui/FormField'
import { Icon } from '../components/ui/Icon'
import { LoadingState } from '../components/ui/LoadingState'
import { Alert } from '../components/ui/Alert'
import { canManageDeployments } from '../lib/permissions'
import type {
  Customer,
  Deployment,
  GroundedContextKind,
  GroundedContextPackage,
  KnowledgeDocumentLibraryEntry,
  ProjectFact,
  ProjectFactExtraction,
  ProjectFactExtractionStatus,
  ProjectFactFilterOptions,
  ProjectFactListQuery,
  ProjectFactStats,
  ProjectFactStatus,
  Workspace,
} from '../types'

type ProjectIntelligencePageProps = {
  workspace: Workspace | null
  customer: Customer | null
  deployment: Deployment | null
}

type StatusView = ProjectFactStatus | 'all'

type FactGroup = {
  key: string
  title: string
  sourceDocumentId: number | null
  sourceRevision: number | null
  facts: ProjectFact[]
}

type ConfirmAction =
  | { kind: 'verify'; fact: ProjectFact }
  | { kind: 'reject'; fact: ProjectFact }
  | { kind: 'bulk-verify'; ids: number[] }
  | { kind: 'bulk-reject'; ids: number[] }
  | {
      kind: 'reject-source'
      sourceDocumentId: number
      sourceRevision: number
      title: string
    }

const EXTRACTION_POLL_INTERVAL_MS = 2500
const FACTS_PAGE_SIZE = 100
const SEARCH_DEBOUNCE_MS = 300

const STATUS_VIEWS: { value: StatusView; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'proposed', label: 'Proposed' },
  { value: 'verified', label: 'Verified' },
  { value: 'rejected', label: 'Rejected' },
]

function factStatusVariant(status: ProjectFactStatus): 'default' | 'success' | 'warning' | 'danger' | 'neutral' {
  switch (status) {
    case 'verified':
      return 'success'
    case 'proposed':
      return 'warning'
    case 'rejected':
      return 'danger'
    case 'superseded':
      return 'neutral'
    default:
      return 'default'
  }
}

function formatFactStatus(status: ProjectFactStatus): string {
  return status.charAt(0).toUpperCase() + status.slice(1)
}

function groundingVariant(
  grounding: GroundedContextKind,
): 'default' | 'success' | 'warning' | 'danger' | 'neutral' | 'info' {
  switch (grounding) {
    case 'verified_fact':
      return 'success'
    case 'documented':
      return 'info'
    case 'inferred':
      return 'warning'
    case 'conflicting':
      return 'danger'
    case 'unknown':
      return 'neutral'
    default:
      return 'default'
  }
}

function formatGrounding(grounding: GroundedContextKind): string {
  switch (grounding) {
    case 'verified_fact':
      return 'Verified fact'
    case 'documented':
      return 'Documented'
    case 'inferred':
      return 'Inferred'
    case 'conflicting':
      return 'Conflicting'
    case 'unknown':
      return 'Unknown'
    default:
      return grounding
  }
}

function isExtractionInFlight(status: ProjectFactExtractionStatus): boolean {
  return status === 'pending' || status === 'processing'
}

function factLabel(fact: ProjectFact): string {
  return `${fact.category}.${fact.key}`
}

function sourceGroupKey(fact: ProjectFact): string {
  if (fact.source_document_id === null || fact.source_revision === null) {
    return 'manual'
  }

  return `${fact.source_document_id}:${fact.source_revision}`
}

function groupFacts(facts: ProjectFact[]): FactGroup[] {
  const groups: FactGroup[] = []
  const indexByKey = new Map<string, number>()

  for (const fact of facts) {
    const key = sourceGroupKey(fact)
    const existingIndex = indexByKey.get(key)

    if (existingIndex !== undefined) {
      groups[existingIndex].facts.push(fact)
      continue
    }

    indexByKey.set(key, groups.length)
    groups.push({
      key,
      title:
        fact.source_document !== null
          ? `${fact.source_document.title} (rev ${fact.source_revision})`
          : fact.source_document_id !== null
            ? `Document #${fact.source_document_id} (rev ${fact.source_revision})`
            : 'Manual proposals',
      sourceDocumentId: fact.source_document_id,
      sourceRevision: fact.source_revision,
      facts: [fact],
    })
  }

  return groups
}

export function ProjectIntelligencePage({
  workspace,
  customer,
  deployment,
}: ProjectIntelligencePageProps) {
  const searchId = useId()
  const categoryFilterId = useId()
  const sourceDocumentFilterId = useId()
  const selectAllId = useId()
  const [facts, setFacts] = useState<ProjectFact[]>([])
  const [stats, setStats] = useState<ProjectFactStats | null>(null)
  const [filterOptions, setFilterOptions] = useState<ProjectFactFilterOptions>({
    categories: [],
    source_documents: [],
  })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [statusView, setStatusView] = useState<StatusView>('proposed')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [sourceDocumentFilter, setSourceDocumentFilter] = useState<number | ''>('')
  const [actionId, setActionId] = useState<number | null>(null)
  const [bulkPending, setBulkPending] = useState(false)
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set())
  const [confirmAction, setConfirmAction] = useState<ConfirmAction | null>(null)
  const [sourceFact, setSourceFact] = useState<ProjectFact | null>(null)
  const [showCreateForm, setShowCreateForm] = useState(false)
  const [documents, setDocuments] = useState<KnowledgeDocumentLibraryEntry[]>([])
  const [extractDocumentId, setExtractDocumentId] = useState<number | ''>('')
  const [extraction, setExtraction] = useState<ProjectFactExtraction | null>(null)
  const extractionPollInFlight = useRef(false)
  const handledExtractionId = useRef<number | null>(null)
  const [createForm, setCreateForm] = useState({
    category: '',
    key: '',
    value: '',
    source_reference: '',
    confidence: '',
  })
  const contextQueryId = useId()
  const [contextQuery, setContextQuery] = useState('How should cart reservation expiration work?')
  const [contextPackage, setContextPackage] = useState<GroundedContextPackage | null>(null)
  const [contextLoading, setContextLoading] = useState(false)
  const [contextError, setContextError] = useState<string | null>(null)

  const canManage = canManageDeployments(workspace?.current_user_role)
  const hasActiveFilters =
    search.trim() !== '' || categoryFilter !== '' || sourceDocumentFilter !== ''
  const visibleProposed = useMemo(
    () => facts.filter((fact) => fact.status === 'proposed'),
    [facts],
  )
  const factGroups = useMemo(() => groupFacts(facts), [facts])
  const selectedCount = selectedIds.size
  const allVisibleSelected =
    visibleProposed.length > 0 && visibleProposed.every((fact) => selectedIds.has(fact.id))
  const someVisibleSelected = visibleProposed.some((fact) => selectedIds.has(fact.id))

  const buildFactsQuery = useCallback((): ProjectFactListQuery => {
    return {
      status: statusView === 'all' ? undefined : statusView,
      search: search.trim() || undefined,
      category: categoryFilter || undefined,
      source_document_id: sourceDocumentFilter === '' ? undefined : sourceDocumentFilter,
      per_page: FACTS_PAGE_SIZE,
    }
  }, [statusView, search, categoryFilter, sourceDocumentFilter])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      setSearch(searchInput.trim())
    }, SEARCH_DEBOUNCE_MS)

    return () => window.clearTimeout(timeoutId)
  }, [searchInput])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- drop prior deployment context when scope changes
    setContextPackage(null)
    setContextError(null)
  }, [workspace?.id, customer?.id, deployment?.id])

  useEffect(() => {
    if (!workspace || !customer || !deployment) {
      return
    }

    let cancelled = false
    // eslint-disable-next-line react-hooks/set-state-in-effect -- show loading state when fact query changes
    setLoading(true)
    setSelectedIds(new Set())

    fetchProjectFacts(workspace.id, customer.id, deployment.id, buildFactsQuery())
      .then((response) => {
        if (!cancelled) {
          setFacts(response.data)
          setStats(response.stats)
          setFilterOptions(response.filter_options ?? { categories: [], source_documents: [] })
          setError(null)
        }
      })
      .catch((refreshError) => {
        if (!cancelled) {
          setFacts([])
          setStats(null)
          setFilterOptions({ categories: [], source_documents: [] })
          setError(refreshError instanceof Error ? refreshError.message : 'Failed to load project facts.')
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [workspace, customer, deployment, buildFactsQuery])

  useEffect(() => {
    if (!canManage || !workspace || !customer || !deployment) {
      return
    }

    let cancelled = false

    fetchKnowledgeDocuments(workspace.id, customer.id, deployment.id, { view: 'current' })
      .then((response) => {
        if (!cancelled) {
          const authoritative = response.data.filter(
            (entry) =>
              entry.active_revision?.lifecycle_status === 'active' &&
              entry.active_revision?.status === 'ready',
          )
          setDocuments(authoritative)
        }
      })
      .catch(() => {
        if (!cancelled) {
          setDocuments([])
        }
      })

    return () => {
      cancelled = true
    }
  }, [canManage, workspace, customer, deployment])

  const analyzing = extraction !== null && isExtractionInFlight(extraction.status)
  const extractionId = extraction?.id
  const extractionStatus = extraction?.status

  useEffect(() => {
    if (
      !workspace ||
      !customer ||
      !deployment ||
      extractionId === undefined ||
      extractionStatus === undefined ||
      !isExtractionInFlight(extractionStatus)
    ) {
      return
    }

    let cancelled = false

    const pollExtraction = () => {
      if (extractionPollInFlight.current || cancelled) {
        return
      }

      extractionPollInFlight.current = true

      fetchProjectFactExtraction(workspace.id, customer.id, deployment.id, extractionId)
        .then((response) => {
          if (!cancelled) {
            setExtraction(response.data)
          }
        })
        .catch((pollError) => {
          if (!cancelled) {
            setError(pollError instanceof Error ? pollError.message : 'Failed to check fact extraction status.')
          }
        })
        .finally(() => {
          extractionPollInFlight.current = false
        })
    }

    const intervalId = window.setInterval(pollExtraction, EXTRACTION_POLL_INTERVAL_MS)
    pollExtraction()

    return () => {
      cancelled = true
      window.clearInterval(intervalId)
    }
  }, [workspace, customer, deployment, extractionId, extractionStatus])

  useEffect(() => {
    if (!workspace || !customer || !deployment || extraction === null) {
      return
    }

    if (isExtractionInFlight(extraction.status) || handledExtractionId.current === extraction.id) {
      return
    }

    handledExtractionId.current = extraction.id

    if (extraction.status === 'failed') {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- surface the terminal extraction error once
      setError(extraction.error_message || 'Fact extraction failed. Please try again.')
      return
    }

    if (extraction.status !== 'completed') {
      return
    }

    let cancelled = false
    setSelectedIds(new Set())
    setMessage(`Proposed ${extraction.proposed_count} facts from documentation.`)
    setError(null)

    fetchProjectFacts(workspace.id, customer.id, deployment.id, buildFactsQuery())
      .then((response) => {
        if (!cancelled) {
          setFacts(response.data)
          setStats(response.stats)
          setFilterOptions(response.filter_options ?? { categories: [], source_documents: [] })
        }
      })
      .catch((refreshError) => {
        if (!cancelled) {
          setError(refreshError instanceof Error ? refreshError.message : 'Failed to load project facts.')
        }
      })

    return () => {
      cancelled = true
    }
  }, [workspace, customer, deployment, extraction, buildFactsQuery])

  if (!deployment || !workspace || !customer) {
    return (
      <EmptyState
        title="Select a deployment"
        description="Review structured project facts extracted from governed documentation."
        icon={Layers}
      />
    )
  }

  const activeDeployment = deployment
  const activeWorkspace = workspace
  const activeCustomer = customer
  const actionsDisabled = bulkPending || actionId !== null

  async function refreshFacts(silent = false) {
    if (!silent) {
      setLoading(true)
    }

    setError(null)

    try {
      const response = await fetchProjectFacts(activeWorkspace.id, activeCustomer.id, activeDeployment.id, buildFactsQuery())
      setFacts(response.data)
      setStats(response.stats)
      setFilterOptions(response.filter_options ?? { categories: [], source_documents: [] })
    } catch (refreshError) {
      setFacts([])
      setStats(null)
      setFilterOptions({ categories: [], source_documents: [] })
      setError(refreshError instanceof Error ? refreshError.message : 'Failed to load project facts.')
    } finally {
      if (!silent) {
        setLoading(false)
      }
    }
  }

  function clearAffectedSelections(ids: number[]) {
    setSelectedIds((current) => {
      const next = new Set(current)
      ids.forEach((id) => next.delete(id))
      return next
    })
  }

  function clearFilters() {
    setSearchInput('')
    setSearch('')
    setCategoryFilter('')
    setSourceDocumentFilter('')
  }

  function toggleFactSelection(factId: number) {
    setSelectedIds((current) => {
      const next = new Set(current)

      if (next.has(factId)) {
        next.delete(factId)
      } else {
        next.add(factId)
      }

      return next
    })
  }

  function toggleSelectAllVisible() {
    if (allVisibleSelected) {
      setSelectedIds(new Set())
      return
    }

    setSelectedIds(new Set(visibleProposed.map((fact) => fact.id)))
  }

  async function handleVerify(fact: ProjectFact) {
    setActionId(fact.id)
    setMessage(null)

    try {
      await verifyProjectFact(activeWorkspace.id, activeCustomer.id, activeDeployment.id, fact.id)
      setMessage(`Verified ${factLabel(fact)}.`)
      clearAffectedSelections([fact.id])
      await refreshFacts(true)
    } catch (verifyError) {
      setError(verifyError instanceof Error ? verifyError.message : 'Failed to verify fact.')
    } finally {
      setActionId(null)
      setConfirmAction(null)
    }
  }

  async function handleReject(fact: ProjectFact) {
    setActionId(fact.id)
    setMessage(null)

    try {
      await rejectProjectFact(activeWorkspace.id, activeCustomer.id, activeDeployment.id, fact.id)
      setMessage(`Rejected ${factLabel(fact)}.`)
      clearAffectedSelections([fact.id])
      await refreshFacts(true)
    } catch (rejectError) {
      setError(rejectError instanceof Error ? rejectError.message : 'Failed to reject fact.')
    } finally {
      setActionId(null)
      setConfirmAction(null)
    }
  }

  async function handleBulkVerify(ids: number[]) {
    if (ids.length === 0) {
      return
    }

    setBulkPending(true)
    setMessage(null)
    setError(null)

    try {
      const response = await bulkVerifyProjectFacts(
        activeWorkspace.id,
        activeCustomer.id,
        activeDeployment.id,
        ids,
      )
      setStats(response.stats)
      clearAffectedSelections(ids)
      setMessage(`Approved ${response.processed_count} selected facts.`)
      await refreshFacts(true)
    } catch (verifyError) {
      throw verifyError instanceof Error ? verifyError : new Error('Failed to approve selected facts.')
    } finally {
      setBulkPending(false)
      setConfirmAction(null)
    }
  }

  async function handleBulkReject(ids: number[]) {
    if (ids.length === 0) {
      return
    }

    setBulkPending(true)
    setMessage(null)
    setError(null)

    try {
      const response = await bulkRejectProjectFacts(
        activeWorkspace.id,
        activeCustomer.id,
        activeDeployment.id,
        { ids },
      )
      setStats(response.stats)
      clearAffectedSelections(ids)
      setMessage(`Rejected ${response.processed_count} selected facts.`)
      await refreshFacts(true)
    } catch (rejectError) {
      throw rejectError instanceof Error ? rejectError : new Error('Failed to reject selected facts.')
    } finally {
      setBulkPending(false)
      setConfirmAction(null)
    }
  }

  async function handleRejectSource(sourceDocumentId: number, sourceRevision: number, title: string) {
    setBulkPending(true)
    setMessage(null)
    setError(null)

    try {
      const response = await bulkRejectProjectFacts(
        activeWorkspace.id,
        activeCustomer.id,
        activeDeployment.id,
        { source_document_id: sourceDocumentId, source_revision: sourceRevision },
      )
      const rejectedIds = response.data.map((fact) => fact.id)
      setStats(response.stats)
      clearAffectedSelections(rejectedIds)
      setMessage(`Rejected ${response.processed_count} proposed facts from ${title}.`)
      await refreshFacts(true)
    } catch (rejectError) {
      throw rejectError instanceof Error ? rejectError : new Error('Failed to reject facts from this document.')
    } finally {
      setBulkPending(false)
      setConfirmAction(null)
    }
  }

  async function handleCreateFact() {
    setMessage(null)
    setError(null)

    try {
      await createProjectFact(activeWorkspace.id, activeCustomer.id, activeDeployment.id, {
        category: createForm.category.trim(),
        key: createForm.key.trim(),
        value: createForm.value.trim(),
        source_reference: createForm.source_reference.trim() || null,
        confidence: createForm.confidence ? Number(createForm.confidence) : null,
      })
      setShowCreateForm(false)
      setCreateForm({ category: '', key: '', value: '', source_reference: '', confidence: '' })
      setMessage('Proposed fact created.')
      await refreshFacts(true)
    } catch (createError) {
      setError(createError instanceof Error ? createError.message : 'Failed to create fact.')
    }
  }

  async function handleBuildContext() {
    const query = contextQuery.trim()

    if (query === '') {
      setContextError('Enter a question to build grounded context.')
      return
    }

    setContextLoading(true)
    setContextError(null)

    try {
      const response = await buildGroundedContext(
        activeWorkspace.id,
        activeCustomer.id,
        activeDeployment.id,
        query,
      )
      setContextPackage(response.data)
    } catch (buildError) {
      setContextPackage(null)
      setContextError(buildError instanceof Error ? buildError.message : 'Failed to build grounded context.')
    } finally {
      setContextLoading(false)
    }
  }

  async function handleExtractFacts() {
    if (extractDocumentId === '') {
      return
    }

    setMessage(null)
    setError(null)

    try {
      const response = await extractProjectFacts(
        activeWorkspace.id,
        activeCustomer.id,
        activeDeployment.id,
        Number(extractDocumentId),
      )
      setExtraction(response.data)
    } catch (extractError) {
      setExtraction(null)
      setError(extractError instanceof Error ? extractError.message : 'Failed to extract facts.')
    }
  }

  const confirmTitle =
    confirmAction?.kind === 'verify'
      ? 'Verify fact?'
      : confirmAction?.kind === 'reject'
        ? 'Reject fact?'
        : confirmAction?.kind === 'bulk-verify'
          ? 'Approve selected facts?'
          : confirmAction?.kind === 'bulk-reject'
            ? 'Reject selected facts?'
            : confirmAction?.kind === 'reject-source'
              ? 'Reject all proposed from this document?'
              : ''

  const confirmDescription = !confirmAction
    ? ''
    : confirmAction.kind === 'verify'
      ? `Approve ${factLabel(confirmAction.fact)} = ${confirmAction.fact.value}. Existing verified facts with the same key will be superseded.`
      : confirmAction.kind === 'reject'
        ? `Reject ${factLabel(confirmAction.fact)}. The proposal will remain in history as rejected.`
        : confirmAction.kind === 'bulk-verify'
          ? `Approve ${confirmAction.ids.length} selected proposed facts. Matching verified facts with the same category and key will be superseded.`
          : confirmAction.kind === 'bulk-reject'
            ? `Reject ${confirmAction.ids.length} selected proposed facts. They will remain in history as rejected.`
            : `Reject all proposed facts from ${confirmAction.title}. They will remain in history as rejected. Verified facts from this document will not change.`

  const confirmLabel =
    confirmAction?.kind === 'verify' || confirmAction?.kind === 'bulk-verify'
      ? confirmAction.kind === 'verify'
        ? 'Verify'
        : 'Approve selected'
      : confirmAction?.kind === 'reject-source'
        ? 'Reject all proposed'
        : 'Reject'

  return (
    <div className="page-stack">
      {message && <Alert variant="success">{message}</Alert>}

      {stats && (
        <div className="stat-grid">
          <Card className="stat-card">
            <div className="card__body">
              <div className="stat-card__header">
                <span className="stat-label">Proposed</span>
                <span className="stat-card__icon stat-card__icon--warning">
                  <Icon icon={Brain} size="sm" />
                </span>
              </div>
              <p className="stat-value">{stats.proposed_count}</p>
            </div>
          </Card>
          <Card className="stat-card">
            <div className="card__body">
              <div className="stat-card__header">
                <span className="stat-label">Verified</span>
                <span className="stat-card__icon stat-card__icon--success">
                  <Icon icon={Check} size="sm" />
                </span>
              </div>
              <p className="stat-value">{stats.verified_count}</p>
            </div>
          </Card>
          <Card className="stat-card">
            <div className="card__body">
              <div className="stat-card__header">
                <span className="stat-label">Rejected</span>
                <span className="stat-card__icon stat-card__icon--danger">
                  <Icon icon={X} size="sm" />
                </span>
              </div>
              <p className="stat-value">{stats.rejected_count}</p>
            </div>
          </Card>
        </div>
      )}

      <Card
        title="Build context"
        description="Combine verified project facts with active, ready documentation. This debug view does not generate prompts or start engineering workflows."
      >
        <div className="form-panel">
          <FormField
            label="Question"
            htmlFor={contextQueryId}
            hint="Ask how a behavior should work. Only verified facts and active + ready documents are used."
          >
            <FormTextarea
              id={contextQueryId}
              value={contextQuery}
              onChange={(event) => setContextQuery(event.target.value)}
              placeholder="How should cart reservation expiration work?"
              rows={3}
            />
          </FormField>
          <div className="button-row">
            <Button
              variant="primary"
              size="sm"
              loading={contextLoading}
              disabled={contextLoading}
              onClick={() => void handleBuildContext()}
            >
              <Icon icon={Search} size="xs" />
              Build context
            </Button>
          </div>
        </div>

        {contextError && <ErrorState message={contextError} />}
        {contextLoading && <LoadingState label="Building grounded context…" />}

        {!contextLoading && !contextError && contextPackage === null && (
          <EmptyState
            compact
            title="No context package yet"
            description="Enter a question and build context to inspect relevant facts, document evidence, conflicts, unknowns, and sources."
            icon={Search}
          />
        )}

        {!contextLoading && contextPackage !== null && (
          <div className="context-package">
            <section className="context-package__section">
              <h3 className="context-package__title">Relevant verified facts</h3>
              {contextPackage.facts.length === 0 ? (
                <p className="context-package__empty">No verified facts matched this question.</p>
              ) : (
                <ul className="data-list">
                  {contextPackage.facts.map((fact) => (
                    <li key={fact.id} className="data-list__item data-list__item--stacked">
                      <div className="data-list__primary">
                        <div className="data-list__title-row">
                          <strong>
                            {fact.category}.{fact.key}
                          </strong>
                          <Badge variant={groundingVariant(fact.grounding)}>
                            {formatGrounding(fact.grounding)}
                          </Badge>
                        </div>
                        <p className="data-list__subtitle">{fact.value}</p>
                        <span className="data-list__meta">
                          Relevance {Math.round(fact.relevance * 100)}%
                          {fact.provenance.source_document
                            ? ` · ${fact.provenance.source_document.title} (rev ${fact.provenance.source_document.revision_number})`
                            : ''}
                        </span>
                        {fact.provenance.source_reference && (
                          <span className="data-list__meta data-list__meta--quote">
                            “{fact.provenance.source_reference}”
                          </span>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="context-package__section">
              <h3 className="context-package__title">Relevant document evidence</h3>
              {contextPackage.documents.length === 0 ? (
                <p className="context-package__empty">No active, ready document chunks matched this question.</p>
              ) : (
                <ul className="data-list">
                  {contextPackage.documents.map((document) => (
                    <li
                      key={`${document.document_id}:${document.chunk_index}`}
                      className="data-list__item data-list__item--stacked"
                    >
                      <div className="data-list__primary">
                        <div className="data-list__title-row">
                          <strong>
                            {document.title} · chunk {document.chunk_index}
                          </strong>
                          <Badge variant={groundingVariant(document.grounding)}>
                            {formatGrounding(document.grounding)}
                          </Badge>
                        </div>
                        <p className="data-list__subtitle">{document.content}</p>
                        <span className="data-list__meta">
                          Score {document.score.toFixed(2)} · rev {document.revision_number} ·{' '}
                          {document.source_filename}
                        </span>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="context-package__section">
              <h3 className="context-package__title">Conflicts</h3>
              {contextPackage.conflicts.length === 0 ? (
                <p className="context-package__empty">No conflicts detected.</p>
              ) : (
                <ul className="data-list">
                  {contextPackage.conflicts.map((conflict, index) => (
                    <li key={`${conflict.topic}:${index}`} className="data-list__item data-list__item--stacked">
                      <div className="data-list__primary">
                        <div className="data-list__title-row">
                          <strong>{conflict.topic}</strong>
                          <Badge variant={groundingVariant(conflict.grounding)}>
                            {formatGrounding(conflict.grounding)}
                          </Badge>
                        </div>
                        <p className="data-list__subtitle">{conflict.summary}</p>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="context-package__section">
              <h3 className="context-package__title">Unknowns</h3>
              {contextPackage.unknowns.length === 0 ? (
                <p className="context-package__empty">No uncovered topics.</p>
              ) : (
                <ul className="data-list">
                  {contextPackage.unknowns.map((unknown) => (
                    <li key={unknown.topic} className="data-list__item data-list__item--stacked">
                      <div className="data-list__primary">
                        <div className="data-list__title-row">
                          <strong>{unknown.topic}</strong>
                          <Badge variant={groundingVariant(unknown.grounding)}>
                            {formatGrounding(unknown.grounding)}
                          </Badge>
                        </div>
                        <p className="data-list__subtitle">{unknown.reason}</p>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="context-package__section">
              <h3 className="context-package__title">Sources used</h3>
              {contextPackage.sources.length === 0 ? (
                <p className="context-package__empty">No sources were used.</p>
              ) : (
                <ul className="data-list">
                  {contextPackage.sources.map((source) => (
                    <li key={`${source.type}:${source.id}`} className="data-list__item data-list__item--stacked">
                      <div className="data-list__primary">
                        <div className="data-list__title-row">
                          <strong>
                            {source.type === 'project_fact' ? source.label : source.title}
                          </strong>
                          <Badge variant={source.type === 'project_fact' ? 'success' : 'info'}>
                            {source.type === 'project_fact' ? 'Verified fact' : 'Document'}
                          </Badge>
                        </div>
                        <span className="data-list__meta">
                          {source.type === 'project_fact'
                            ? `Fact #${source.id}${source.source_revision !== null ? ` · rev ${source.source_revision}` : ''}`
                            : `${source.original_filename} · rev ${source.revision_number} · ${source.lifecycle_status}/${source.status}`}
                        </span>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>
        )}
      </Card>

      <Card
        title="Project facts"
        description="Structured facts with provenance from governed documentation. AI proposals require human verification."
        actions={
          canManage ? (
            <div className="button-row">
              <Button variant="secondary" size="sm" onClick={() => setShowCreateForm((current) => !current)}>
                <Icon icon={Plus} size="xs" />
                Propose fact
              </Button>
            </div>
          ) : undefined
        }
      >
        <div className="filter-row">
          <div className="button-row">
            {STATUS_VIEWS.map((view) => (
              <Button
                key={view.value}
                variant={statusView === view.value ? 'primary' : 'secondary'}
                size="sm"
                onClick={() => setStatusView(view.value)}
              >
                {view.label}
              </Button>
            ))}
          </div>
          <div className="filter-row__controls">
            <FormField label="Search" htmlFor={searchId}>
              <FormInput
                id={searchId}
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                placeholder="Search category, key, value, evidence, or document"
              />
            </FormField>
            <FormField label="Category" htmlFor={categoryFilterId}>
              <FormSelect
                id={categoryFilterId}
                value={categoryFilter}
                onChange={(event) => setCategoryFilter(event.target.value)}
              >
                <option value="">All categories</option>
                {filterOptions.categories.map((category) => (
                  <option key={category} value={category}>
                    {category}
                  </option>
                ))}
              </FormSelect>
            </FormField>
            <FormField label="Source document" htmlFor={sourceDocumentFilterId}>
              <FormSelect
                id={sourceDocumentFilterId}
                value={sourceDocumentFilter}
                onChange={(event) =>
                  setSourceDocumentFilter(event.target.value ? Number(event.target.value) : '')
                }
              >
                <option value="">All source documents</option>
                {filterOptions.source_documents.map((document) => (
                  <option key={document.id} value={document.id}>
                    {document.title} (rev {document.revision_number})
                  </option>
                ))}
              </FormSelect>
            </FormField>
            {hasActiveFilters && (
              <Button variant="secondary" size="sm" onClick={clearFilters}>
                Clear filters
              </Button>
            )}
          </div>
        </div>

        {canManage && showCreateForm && (
          <div className="form-panel">
            <div className="form-grid">
              <FormField label="Category" htmlFor="fact-category">
                <FormInput
                  id="fact-category"
                  value={createForm.category}
                  onChange={(event) =>
                    setCreateForm((current) => ({ ...current, category: event.target.value }))
                  }
                  placeholder="framework"
                />
              </FormField>
              <FormField label="Key" htmlFor="fact-key">
                <FormInput
                  id="fact-key"
                  value={createForm.key}
                  onChange={(event) => setCreateForm((current) => ({ ...current, key: event.target.value }))}
                  placeholder="backend"
                />
              </FormField>
              <FormField label="Value" htmlFor="fact-value">
                <FormInput
                  id="fact-value"
                  value={createForm.value}
                  onChange={(event) => setCreateForm((current) => ({ ...current, value: event.target.value }))}
                  placeholder="Laravel 13"
                />
              </FormField>
              <FormField label="Confidence (0-1)" htmlFor="fact-confidence">
                <FormInput
                  id="fact-confidence"
                  type="number"
                  min="0"
                  max="1"
                  step="0.01"
                  value={createForm.confidence}
                  onChange={(event) =>
                    setCreateForm((current) => ({ ...current, confidence: event.target.value }))
                  }
                />
              </FormField>
            </div>
            <FormField label="Source reference" htmlFor="fact-source-reference">
              <FormTextarea
                id="fact-source-reference"
                value={createForm.source_reference}
                onChange={(event) =>
                  setCreateForm((current) => ({ ...current, source_reference: event.target.value }))
                }
                placeholder="Evidence excerpt supporting this fact"
              />
            </FormField>
            <div className="button-row">
              <Button variant="primary" size="sm" onClick={() => void handleCreateFact()}>
                Save proposed fact
              </Button>
              <Button variant="secondary" size="sm" onClick={() => setShowCreateForm(false)}>
                Cancel
              </Button>
            </div>
          </div>
        )}

        {canManage && documents.length > 0 && (
          <div className="form-panel">
            <p className="form-panel__title">Extract facts from documentation</p>
            <div className="form-grid">
              <FormField label="Active + ready document" htmlFor="extract-document">
                <FormSelect
                  id="extract-document"
                  value={extractDocumentId}
                  onChange={(event) =>
                    setExtractDocumentId(event.target.value ? Number(event.target.value) : '')
                  }
                >
                  <option value="">Select a document</option>
                  {documents.map((entry) => {
                    const documentId = entry.active_revision?.id ?? entry.chain_head.id

                    return (
                      <option key={documentId} value={documentId}>
                        {entry.title} (rev {entry.active_revision?.revision_number ?? entry.chain_head.revision_number})
                      </option>
                    )
                  })}
                </FormSelect>
              </FormField>
            </div>
            <Button
              variant="secondary"
              size="sm"
              disabled={extractDocumentId === '' || analyzing}
              onClick={() => void handleExtractFacts()}
            >
              <Icon icon={Sparkles} size="xs" />
              {analyzing ? 'Analyzing...' : 'Analyze & propose facts'}
            </Button>
            {analyzing && <p className="form-panel__title">Analyzing...</p>}
          </div>
        )}

        {canManage && visibleProposed.length > 0 && (
          <div className="bulk-toolbar">
            <label className="bulk-toolbar__select-all" htmlFor={selectAllId}>
              <input
                id={selectAllId}
                type="checkbox"
                className="fact-checkbox"
                checked={allVisibleSelected}
                ref={(element) => {
                  if (element) {
                    element.indeterminate = someVisibleSelected && !allVisibleSelected
                  }
                }}
                disabled={actionsDisabled}
                onChange={toggleSelectAllVisible}
              />
              Select all visible
            </label>
            <span className="bulk-toolbar__count">
              {selectedCount} selected
            </span>
            <div className="button-row">
              <Button
                variant="primary"
                size="sm"
                disabled={selectedCount === 0 || actionsDisabled}
                onClick={() => setConfirmAction({ kind: 'bulk-verify', ids: [...selectedIds] })}
              >
                <Icon icon={Check} size="xs" />
                Approve selected
              </Button>
              <Button
                variant="danger"
                size="sm"
                disabled={selectedCount === 0 || actionsDisabled}
                onClick={() => setConfirmAction({ kind: 'bulk-reject', ids: [...selectedIds] })}
              >
                <Icon icon={X} size="xs" />
                Reject selected
              </Button>
            </div>
          </div>
        )}

        {loading && <LoadingState label="Loading project facts…" />}
        {error && <ErrorState message={error} />}
        {!loading && !error && facts.length === 0 && (
          <EmptyState
            compact
            title={hasActiveFilters ? 'No facts match your filters' : 'No facts in this view'}
            description={
              hasActiveFilters
                ? 'Try adjusting your search or filters to find project facts.'
                : 'Extract facts from active documentation or propose them manually.'
            }
            icon={Brain}
            action={
              hasActiveFilters ? (
                <Button variant="secondary" size="sm" onClick={clearFilters}>
                  Clear filters
                </Button>
              ) : undefined
            }
          />
        )}
        {!loading && facts.length > 0 && (
          <div className="fact-groups">
            {factGroups.map((group) => {
              const proposedInGroup = group.facts.filter((fact) => fact.status === 'proposed')

              return (
                <section key={group.key} className="fact-group">
                  <div className="fact-group__header">
                    <div>
                      <h3 className="fact-group__title">{group.title}</h3>
                      <p className="fact-group__meta">
                        {proposedInGroup.length} proposed
                        {group.facts.length !== proposedInGroup.length ? ` · ${group.facts.length} in view` : ''}
                      </p>
                    </div>
                    {canManage &&
                      group.sourceDocumentId !== null &&
                      group.sourceRevision !== null &&
                      proposedInGroup.length > 0 && (
                        <Button
                          variant="danger"
                          size="sm"
                          disabled={actionsDisabled}
                          onClick={() =>
                            setConfirmAction({
                              kind: 'reject-source',
                              sourceDocumentId: group.sourceDocumentId as number,
                              sourceRevision: group.sourceRevision as number,
                              title: group.title,
                            })
                          }
                        >
                          Reject all proposed from this document
                        </Button>
                      )}
                  </div>
                  <ul className="data-list">
                    {group.facts.map((fact) => (
                      <li
                        key={fact.id}
                        className={`data-list__item data-list__item--stacked${selectedIds.has(fact.id) ? ' data-list__item--selected' : ''}`}
                      >
                        {canManage && fact.status === 'proposed' && (
                          <label className="fact-select">
                            <span className="sr-only">Select {factLabel(fact)}</span>
                            <input
                              type="checkbox"
                              className="fact-checkbox"
                              checked={selectedIds.has(fact.id)}
                              disabled={actionsDisabled}
                              onChange={() => toggleFactSelection(fact.id)}
                            />
                          </label>
                        )}
                        {canManage && fact.status !== 'proposed' && visibleProposed.length > 0 && (
                          <span className="fact-select" aria-hidden="true" />
                        )}
                        <div className="data-list__primary">
                          <div className="data-list__title-row">
                            <strong>{factLabel(fact)}</strong>
                            <Badge variant={factStatusVariant(fact.status)}>{formatFactStatus(fact.status)}</Badge>
                          </div>
                          <p className="data-list__subtitle">{fact.value}</p>
                          <span className="data-list__meta">
                            Confidence {fact.confidence !== null ? `${Math.round(fact.confidence * 100)}%` : 'n/a'}
                            {fact.source_document
                              ? ` · Source: ${fact.source_document.title} (rev ${fact.source_document.revision_number})`
                              : ''}
                          </span>
                          {fact.source_reference && (
                            <span className="data-list__meta data-list__meta--quote">“{fact.source_reference}”</span>
                          )}
                        </div>
                        <div className="button-row">
                          {(fact.source_document || fact.source_reference) && (
                            <Button variant="secondary" size="sm" onClick={() => setSourceFact(fact)}>
                              <Icon icon={Eye} size="xs" />
                              View source
                            </Button>
                          )}
                          {canManage && fact.status === 'proposed' && (
                            <>
                              <Button
                                variant="primary"
                                size="sm"
                                disabled={actionsDisabled || actionId === fact.id}
                                onClick={() => setConfirmAction({ kind: 'verify', fact })}
                              >
                                <Icon icon={Check} size="xs" />
                                Approve
                              </Button>
                              <Button
                                variant="danger"
                                size="sm"
                                disabled={actionsDisabled || actionId === fact.id}
                                onClick={() => setConfirmAction({ kind: 'reject', fact })}
                              >
                                <Icon icon={X} size="xs" />
                                Reject
                              </Button>
                            </>
                          )}
                        </div>
                      </li>
                    ))}
                  </ul>
                </section>
              )
            })}
          </div>
        )}
      </Card>

      <ConfirmDialog
        open={confirmAction !== null}
        title={confirmTitle}
        description={confirmDescription}
        confirmLabel={confirmLabel}
        variant={
          confirmAction?.kind === 'verify' || confirmAction?.kind === 'bulk-verify' ? 'primary' : 'danger'
        }
        onConfirm={() => {
          if (!confirmAction) {
            return Promise.resolve()
          }

          if (confirmAction.kind === 'verify') {
            return handleVerify(confirmAction.fact)
          }

          if (confirmAction.kind === 'reject') {
            return handleReject(confirmAction.fact)
          }

          if (confirmAction.kind === 'bulk-verify') {
            return handleBulkVerify(confirmAction.ids)
          }

          if (confirmAction.kind === 'bulk-reject') {
            return handleBulkReject(confirmAction.ids)
          }

          return handleRejectSource(
            confirmAction.sourceDocumentId,
            confirmAction.sourceRevision,
            confirmAction.title,
          )
        }}
        onCancel={() => setConfirmAction(null)}
      />

      <ConfirmDialog
        open={sourceFact !== null}
        title="Source evidence"
        description={
          sourceFact
            ? [
                sourceFact.source_document
                  ? `Document: ${sourceFact.source_document.title} (revision ${sourceFact.source_document.revision_number}, ${sourceFact.source_document.original_filename})`
                  : null,
                sourceFact.source_reference ? `Evidence: ${sourceFact.source_reference}` : null,
              ]
                .filter(Boolean)
                .join('\n\n')
            : ''
        }
        confirmLabel="Close"
        cancelLabel="Dismiss"
        variant="primary"
        onConfirm={() => {
          setSourceFact(null)
          return Promise.resolve()
        }}
        onCancel={() => setSourceFact(null)}
      />
    </div>
  )
}
