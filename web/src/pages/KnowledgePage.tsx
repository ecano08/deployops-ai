import { useEffect, useId, useRef, useState } from 'react'
import { AlertCircle, BookOpen, Eye, Layers, Upload } from 'lucide-react'
import { fetchKnowledgeDocuments } from '../api'
import { canManageDeployments } from '../lib/permissions'
import { KnowledgeDocumentFormDialog } from '../components/forms/KnowledgeDocumentFormDialog'
import { KnowledgeDocumentViewDialog } from '../components/knowledge/KnowledgeDocumentViewDialog'
import { Badge } from '../components/ui/Badge'
import { lifecycleBadgeVariant, statusBadgeVariant } from '../components/ui/badgeUtils'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { ContextActionsMenu } from '../components/ui/ContextActionsMenu'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { FormField, FormInput, FormSelect } from '../components/ui/FormField'
import { Icon } from '../components/ui/Icon'
import { LoadingState } from '../components/ui/LoadingState'
import { Alert } from '../components/ui/Alert'
import type {
  Customer,
  Deployment,
  KnowledgeDocument,
  KnowledgeDocumentLibraryEntry,
  KnowledgeDocumentLibraryQuery,
  KnowledgeDocumentLibraryStats,
  KnowledgeDocumentLifecycleStatus,
  KnowledgeDocumentMatchCandidate,
  KnowledgeDocumentRevisionSummary,
  KnowledgeDocumentStatus,
  KnowledgeDocumentType,
  PaginationMeta,
  Workspace,
} from '../types'
import { KNOWLEDGE_DOCUMENT_TYPES } from '../types'

type KnowledgePageProps = {
  workspace: Workspace | null
  customer: Customer | null
  deployment: Deployment | null
  uploadMessage: string | null
  onDetectMatchCandidates?: (
    filename: string,
    title: string,
  ) => Promise<KnowledgeDocumentMatchCandidate[]>
  onUpload: (payload: {
    file: File
    title: string
    document_type: KnowledgeDocumentType
    version_label: string | null
    effective_at: string | null
    supersedes_document_id: number | null
  }) => Promise<void>
  onActivate: (documentId: number) => Promise<void>
  onArchive: (documentId: number) => Promise<void>
  onDelete: (documentId: number) => Promise<void>
}

type LibraryView = KnowledgeDocumentLibraryQuery['view']

const LIBRARY_VIEWS: { value: LibraryView; label: string }[] = [
  { value: 'current', label: 'Current' },
  { value: 'needs_attention', label: 'Needs attention' },
  { value: 'archived', label: 'Archived' },
]

const SORT_OPTIONS: { value: KnowledgeDocumentLibraryQuery['sort']; label: string }[] = [
  { value: 'updated_at', label: 'Recently updated' },
  { value: 'title', label: 'Title' },
  { value: 'effective_at', label: 'Effective date' },
]

function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`
  }

  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function documentTypeLabel(type: string): string {
  return KNOWLEDGE_DOCUMENT_TYPES.find((entry) => entry.value === type)?.label ?? type
}

function revisionToSupersededDocument(
  revision: KnowledgeDocumentRevisionSummary,
  deployment: Deployment,
): KnowledgeDocument {
  return {
    id: revision.id,
    workspace_id: deployment.workspace_id,
    customer_id: deployment.customer_id,
    deployment_id: deployment.id,
    title: revision.title,
    document_type: revision.document_type,
    version_label: revision.version_label,
    revision_number: revision.revision_number,
    lifecycle_status: revision.lifecycle_status,
    effective_at: revision.effective_at,
    supersedes_document_id: null,
    metadata: null,
    original_filename: revision.original_filename,
    mime_type: revision.mime_type,
    size_bytes: revision.size_bytes,
    status: revision.status,
    error_message: revision.error_message,
    chunk_count: revision.chunk_count,
    uploaded_by: 0,
    created_at: revision.created_at,
    updated_at: revision.updated_at,
  }
}

function DocumentLibraryRow({
  entry,
  deployment,
  canManage,
  actionId,
  onView,
  onActivate,
  onArchive,
  onNewVersion,
  onDelete,
}: {
  entry: KnowledgeDocumentLibraryEntry
  deployment: Deployment
  canManage: boolean
  actionId: number | null
  onView: (documentId: number) => void
  onActivate: (documentId: number) => Promise<void>
  onArchive: (documentId: number) => Promise<void>
  onNewVersion: (document: KnowledgeDocument) => void
  onDelete: (revision: KnowledgeDocumentRevisionSummary) => void
}) {
  const activeRevision = entry.active_revision
  const summaryRevision = activeRevision ?? entry.chain_head
  const archiveTarget = activeRevision ?? entry.chain_head
  const canArchive =
    canManage &&
    archiveTarget.lifecycle_status !== 'archived' &&
    archiveTarget.lifecycle_status !== 'superseded'

  const menuItems = [
    canArchive
      ? {
          label: 'Archive',
          onSelect: () => onArchive(archiveTarget.id),
          disabled: actionId === archiveTarget.id,
        }
      : null,
    canManage
      ? {
          label: 'New version',
          onSelect: () => onNewVersion(revisionToSupersededDocument(entry.chain_head, deployment)),
        }
      : null,
    canManage
      ? {
          label: 'Delete latest revision',
          onSelect: () => onDelete(entry.chain_head),
          destructive: true,
        }
      : null,
  ].filter((item): item is NonNullable<typeof item> => item !== null)

  return (
    <li className="data-list__item data-list__item--stacked document-library__row">
      <div className="data-list__primary">
        <div className="data-list__title-row">
          <strong>{entry.title}</strong>
          {entry.needs_attention && (
            <Badge variant="warning">
              <Icon icon={AlertCircle} size="xs" />
              Draft ready
            </Badge>
          )}
          <Badge variant={lifecycleBadgeVariant(summaryRevision.lifecycle_status)}>
            {summaryRevision.lifecycle_status}
          </Badge>
          <Badge variant={statusBadgeVariant(summaryRevision.status)}>
            {summaryRevision.status}
          </Badge>
        </div>
        <span className="data-list__meta">
          {documentTypeLabel(entry.document_type)} · {entry.revision_count}{' '}
          {entry.revision_count === 1 ? 'revision' : 'revisions'}
        </span>
        <span className="data-list__meta">
          Active revision {summaryRevision.revision_number}
          {summaryRevision.version_label ? ` · ${summaryRevision.version_label}` : ''}
          {summaryRevision.effective_at ? ` · effective ${summaryRevision.effective_at}` : ''}
        </span>
        <span className="data-list__meta">
          {summaryRevision.original_filename} · {formatBytes(summaryRevision.size_bytes)} ·{' '}
          {summaryRevision.chunk_count} chunks
        </span>
        {entry.attention_draft && (
          <span className="data-list__meta document-library__attention">
            Revision {entry.attention_draft.revision_number} is ready to activate
          </span>
        )}
        {summaryRevision.error_message && (
          <span className="data-list__meta data-list__meta--error">{summaryRevision.error_message}</span>
        )}
      </div>
      <div className="data-list__actions">
        <Button variant="secondary" size="sm" onClick={() => onView(entry.view_document_id)}>
          <Icon icon={Eye} size="xs" />
          View
        </Button>
        {canManage && entry.attention_draft && (
          <Button
            variant="primary"
            size="sm"
            disabled={actionId === entry.attention_draft.id}
            onClick={() => onActivate(entry.attention_draft!.id)}
          >
            Activate
          </Button>
        )}
        {canManage && menuItems.length > 0 && (
          <ContextActionsMenu label={`Actions for ${entry.title}`} items={menuItems} />
        )}
      </div>
    </li>
  )
}

function LibraryPagination({
  meta,
  onPageChange,
}: {
  meta: PaginationMeta
  onPageChange: (page: number) => void
}) {
  if (meta.last_page <= 1) {
    return null
  }

  return (
    <div className="document-library__pagination">
      <span className="document-library__pagination-summary">
        Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} documents
      </span>
      <div className="document-library__pagination-actions">
        <Button
          variant="secondary"
          size="sm"
          disabled={meta.current_page <= 1}
          onClick={() => onPageChange(meta.current_page - 1)}
        >
          Previous
        </Button>
        <span className="document-library__pagination-page">
          Page {meta.current_page} of {meta.last_page}
        </span>
        <Button
          variant="secondary"
          size="sm"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPageChange(meta.current_page + 1)}
        >
          Next
        </Button>
      </div>
    </div>
  )
}

export function KnowledgePage({
  workspace,
  customer,
  deployment,
  uploadMessage,
  onDetectMatchCandidates,
  onUpload,
  onActivate,
  onArchive,
  onDelete,
}: KnowledgePageProps) {
  const [deleteTarget, setDeleteTarget] = useState<KnowledgeDocumentRevisionSummary | null>(null)
  const [versionTarget, setVersionTarget] = useState<KnowledgeDocument | null>(null)
  const [viewDocumentId, setViewDocumentId] = useState<number | null>(null)
  const [showUploadDialog, setShowUploadDialog] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [actionId, setActionId] = useState<number | null>(null)
  const [entries, setEntries] = useState<KnowledgeDocumentLibraryEntry[]>([])
  const [stats, setStats] = useState<KnowledgeDocumentLibraryStats | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [view, setView] = useState<LibraryView>('current')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [documentType, setDocumentType] = useState<KnowledgeDocumentType | ''>('')
  const [lifecycleStatus, setLifecycleStatus] = useState<KnowledgeDocumentLifecycleStatus | ''>('')
  const [attention, setAttention] = useState<KnowledgeDocumentLibraryQuery['attention'] | ''>('')
  const [processingStatus, setProcessingStatus] = useState<KnowledgeDocumentStatus | ''>('')
  const [sort, setSort] = useState<KnowledgeDocumentLibraryQuery['sort']>('updated_at')
  const [page, setPage] = useState(1)
  const pollInFlight = useRef(false)
  const searchFieldId = useId()
  const documentTypeFieldId = useId()
  const lifecycleFieldId = useId()
  const attentionFieldId = useId()
  const processingFieldId = useId()
  const sortFieldId = useId()

  const canManage = canManageDeployments(workspace?.current_user_role)

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      setSearch(searchInput.trim())
      setPage(1)
    }, 300)

    return () => window.clearTimeout(timeoutId)
  }, [searchInput])

  useEffect(() => {
    if (!workspace || !customer || !deployment) {
      return
    }

    let cancelled = false
    // eslint-disable-next-line react-hooks/set-state-in-effect -- show loading state when library query changes
    setLoading(true)

    fetchKnowledgeDocuments(workspace.id, customer.id, deployment.id, {
      view,
      search: search || undefined,
      document_type: documentType || undefined,
      lifecycle_status: lifecycleStatus || undefined,
      attention: attention || undefined,
      status: processingStatus || undefined,
      sort,
      direction: sort === 'title' ? 'asc' : 'desc',
      page,
      per_page: 20,
    })
      .then((response) => {
        if (!cancelled) {
          setEntries(response.data)
          setStats(response.stats)
          setMeta(response.meta)
          setError(null)
        }
      })
      .catch((loadError) => {
        if (!cancelled) {
          setEntries([])
          setStats(null)
          setMeta(null)
          setError(loadError instanceof Error ? loadError.message : 'Failed to load documents.')
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
  }, [
    workspace,
    customer,
    deployment,
    view,
    search,
    documentType,
    lifecycleStatus,
    attention,
    processingStatus,
    sort,
    page,
  ])

  async function refreshLibrary() {
    if (!workspace || !customer || !deployment) {
      return
    }

    setLoading(true)

    try {
      const response = await fetchKnowledgeDocuments(workspace.id, customer.id, deployment.id, {
        view,
        search: search || undefined,
        document_type: documentType || undefined,
        lifecycle_status: lifecycleStatus || undefined,
        attention: attention || undefined,
        status: processingStatus || undefined,
        sort,
        direction: sort === 'title' ? 'asc' : 'desc',
        page,
        per_page: 20,
      })

      setEntries(response.data)
      setStats(response.stats)
      setMeta(response.meta)
      setError(null)
    } catch (loadError) {
      setEntries([])
      setStats(null)
      setMeta(null)
      setError(loadError instanceof Error ? loadError.message : 'Failed to load documents.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (!workspace || !customer || !deployment) {
      return
    }

    const hasProcessingDocuments = entries.some((entry) => {
      const revisions = [
        entry.active_revision,
        entry.chain_head,
        entry.attention_draft,
      ].filter((revision): revision is KnowledgeDocumentRevisionSummary => revision !== null)

      return revisions.some((revision) => {
        const status = revision.status.toLowerCase()
        return status === 'pending' || status === 'processing'
      })
    })

    if (!hasProcessingDocuments) {
      return
    }

    const intervalId = window.setInterval(() => {
      if (pollInFlight.current) {
        return
      }

      pollInFlight.current = true

      fetchKnowledgeDocuments(workspace.id, customer.id, deployment.id, {
        view,
        search: search || undefined,
        document_type: documentType || undefined,
        lifecycle_status: lifecycleStatus || undefined,
        attention: attention || undefined,
        status: processingStatus || undefined,
        sort,
        direction: sort === 'title' ? 'asc' : 'desc',
        page,
        per_page: 20,
      })
        .then((response) => {
          setEntries(response.data)
          setStats(response.stats)
          setMeta(response.meta)
        })
        .catch(() => {
          // Keep the current list visible if a poll request fails transiently.
        })
        .finally(() => {
          pollInFlight.current = false
        })
    }, 2500)

    return () => window.clearInterval(intervalId)
  }, [
    workspace,
    customer,
    deployment,
    entries,
    view,
    search,
    documentType,
    lifecycleStatus,
    attention,
    processingStatus,
    sort,
    page,
  ])

  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="Upload governed project documentation to power copilot retrieval for this deployment."
        icon={Layers}
      />
    )
  }

  async function handleUpload(payload: {
    file: File
    title: string
    document_type: KnowledgeDocumentType
    version_label: string | null
    effective_at: string | null
    supersedes_document_id: number | null
  }) {
    setUploading(true)

    try {
      await onUpload(payload)
      setShowUploadDialog(false)
      setVersionTarget(null)
      await refreshLibrary()
    } finally {
      setUploading(false)
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) {
      return
    }

    await onDelete(deleteTarget.id)
    setDeleteTarget(null)
    await refreshLibrary()
  }

  async function handleActivate(documentId: number) {
    setActionId(documentId)

    try {
      await onActivate(documentId)
      await refreshLibrary()
    } finally {
      setActionId(null)
    }
  }

  async function handleArchive(documentId: number) {
    setActionId(documentId)

    try {
      await onArchive(documentId)
      await refreshLibrary()
    } finally {
      setActionId(null)
    }
  }

  return (
    <div className="page-stack">
      {uploadMessage && <Alert variant="info">{uploadMessage}</Alert>}

      <div className="stat-grid" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Total revisions</span>
              <span className="stat-card__icon stat-card__icon--accent">
                <Icon icon={BookOpen} size="sm" />
              </span>
            </div>
            <p className="stat-value">{loading && !stats ? '…' : stats?.revision_total ?? 0}</p>
          </div>
        </Card>
        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Indexed & ready</span>
              <span className="stat-card__icon stat-card__icon--success">
                <Icon icon={BookOpen} size="sm" />
              </span>
            </div>
            <p className="stat-value">{loading && !stats ? '…' : stats?.ready_count ?? 0}</p>
          </div>
        </Card>
        <Card className="stat-card">
          <div className="card__body">
            <div className="stat-card__header">
              <span className="stat-label">Active (RAG)</span>
              <span className="stat-card__icon stat-card__icon--success">
                <Icon icon={BookOpen} size="sm" />
              </span>
            </div>
            <p className="stat-value">{loading && !stats ? '…' : stats?.active_count ?? 0}</p>
          </div>
        </Card>
      </div>

      <Card
        title="Project documentation"
        description="Governed engineering documents indexed for copilot when active and ready"
        actions={
          canManage ? (
            <Button variant="primary" size="sm" onClick={() => setShowUploadDialog(true)}>
              <Icon icon={Upload} size="xs" />
              Upload document
            </Button>
          ) : undefined
        }
      >
        <div className="document-library__toolbar">
          <div className="document-library__tabs" role="tablist" aria-label="Document library views">
            {LIBRARY_VIEWS.map((tab) => (
              <button
                key={tab.value}
                type="button"
                role="tab"
                aria-selected={view === tab.value}
                className={`document-library__tab${view === tab.value ? ' document-library__tab--active' : ''}`}
                onClick={() => {
                  setView(tab.value)
                  setPage(1)
                }}
              >
                {tab.label}
                {tab.value === 'needs_attention' && stats?.needs_attention_count
                  ? ` (${stats.needs_attention_count})`
                  : ''}
              </button>
            ))}
          </div>

          <div className="document-library__filters">
            <FormField label="Search" hideLabel htmlFor={searchFieldId} className="document-library__search">
              <FormInput
                id={searchFieldId}
                value={searchInput}
                placeholder="Search title or filename"
                onChange={(event) => setSearchInput(event.target.value)}
              />
            </FormField>

            <FormField label="Document type" hideLabel htmlFor={documentTypeFieldId}>
              <FormSelect
                id={documentTypeFieldId}
                value={documentType}
                onChange={(event) => {
                  setDocumentType(event.target.value as KnowledgeDocumentType | '')
                  setPage(1)
                }}
              >
                <option value="">All types</option>
                {KNOWLEDGE_DOCUMENT_TYPES.map((type) => (
                  <option key={type.value} value={type.value}>{type.label}</option>
                ))}
              </FormSelect>
            </FormField>

            <FormField label="Lifecycle" hideLabel htmlFor={lifecycleFieldId}>
              <FormSelect
                id={lifecycleFieldId}
                value={lifecycleStatus}
                onChange={(event) => {
                  setLifecycleStatus(event.target.value as KnowledgeDocumentLifecycleStatus | '')
                  setPage(1)
                }}
              >
                <option value="">All lifecycle states</option>
                <option value="draft">Draft</option>
                <option value="active">Active</option>
                <option value="superseded">Superseded</option>
                <option value="archived">Archived</option>
              </FormSelect>
            </FormField>

            <FormField label="Attention" hideLabel htmlFor={attentionFieldId}>
              <FormSelect
                id={attentionFieldId}
                value={attention}
                onChange={(event) => {
                  setAttention(event.target.value as KnowledgeDocumentLibraryQuery['attention'] | '')
                  setPage(1)
                }}
              >
                <option value="">All attention states</option>
                <option value="needs_attention">Needs activation</option>
                <option value="draft_pending">Draft processing</option>
                <option value="processing_failed">Processing failed</option>
              </FormSelect>
            </FormField>

            <FormField label="Processing status" hideLabel htmlFor={processingFieldId}>
              <FormSelect
                id={processingFieldId}
                value={processingStatus}
                onChange={(event) => {
                  setProcessingStatus(event.target.value as KnowledgeDocumentStatus | '')
                  setPage(1)
                }}
              >
                <option value="">All processing states</option>
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="ready">Ready</option>
                <option value="failed">Failed</option>
              </FormSelect>
            </FormField>

            <FormField label="Sort" hideLabel htmlFor={sortFieldId}>
              <FormSelect
                id={sortFieldId}
                value={sort ?? 'updated_at'}
                onChange={(event) => {
                  setSort(event.target.value as KnowledgeDocumentLibraryQuery['sort'])
                  setPage(1)
                }}
              >
                {SORT_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </FormSelect>
            </FormField>
          </div>
        </div>

        {loading && <LoadingState label="Loading documents…" />}
        {error && <ErrorState message={error} />}
        {!loading && !error && entries.length === 0 && (
          <EmptyState
            compact
            title="No documents yet"
            description="Upload architecture notes, runbooks, ADRs, and other project truth sources."
            icon={BookOpen}
          />
        )}
        {!loading && entries.length > 0 && (
          <ul className="data-list document-library__list">
            {entries.map((entry) => (
              <DocumentLibraryRow
                key={entry.chain_root_id}
                entry={entry}
                deployment={deployment}
                canManage={canManage}
                actionId={actionId}
                onView={setViewDocumentId}
                onActivate={handleActivate}
                onArchive={handleArchive}
                onNewVersion={setVersionTarget}
                onDelete={setDeleteTarget}
              />
            ))}
          </ul>
        )}

        {meta && <LibraryPagination meta={meta} onPageChange={setPage} />}
      </Card>

      {(showUploadDialog || versionTarget) && (
        <KnowledgeDocumentFormDialog
          supersededDocument={versionTarget}
          estimatedNextRevision={
            versionTarget ? versionTarget.revision_number + 1 : undefined
          }
          loading={uploading}
          onDetectMatchCandidates={onDetectMatchCandidates}
          onSubmit={handleUpload}
          onCancel={() => {
            setShowUploadDialog(false)
            setVersionTarget(null)
          }}
        />
      )}

      <ConfirmDialog
        open={deleteTarget !== null}
        title="Delete document?"
        description={`This will remove revision ${deleteTarget?.revision_number} of "${deleteTarget?.title}" and its vector index. This cannot be undone.`}
        confirmLabel="Delete"
        onConfirm={confirmDelete}
        onCancel={() => setDeleteTarget(null)}
      />

      {workspace && customer && deployment && (
        <KnowledgeDocumentViewDialog
          open={viewDocumentId !== null}
          workspace={workspace}
          customer={customer}
          deployment={deployment}
          documentId={viewDocumentId}
          onClose={() => setViewDocumentId(null)}
          onSelectRevision={setViewDocumentId}
        />
      )}
    </div>
  )
}
