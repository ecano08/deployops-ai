import { useId, useState, type FormEvent } from 'react'
import { fieldError, isApiValidationError } from '../../lib/apiError'
import type {
  KnowledgeDocument,
  KnowledgeDocumentMatchCandidate,
  KnowledgeDocumentType,
} from '../../types'
import { KNOWLEDGE_DOCUMENT_TYPES } from '../../types'
import { Alert } from '../ui/Alert'
import { Button } from '../ui/Button'
import { FormDialog } from '../ui/FormDialog'
import { FileDropzone } from '../ui/FileDropzone'
import { FormField, FormInput, FormSelect } from '../ui/FormField'

type KnowledgeDocumentFormDialogProps = {
  supersededDocument?: KnowledgeDocument | null
  estimatedNextRevision?: number
  loading?: boolean
  onDetectMatchCandidates?: (
    filename: string,
    title: string,
  ) => Promise<KnowledgeDocumentMatchCandidate[]>
  onSubmit: (payload: {
    file: File
    title: string
    document_type: KnowledgeDocumentType
    version_label: string | null
    effective_at: string | null
    supersedes_document_id: number | null
  }) => Promise<void>
  onCancel: () => void
}

type MatchChoice = 'pending' | 'new_version' | 'separate'

function titleFromFilename(filename: string): string {
  const basename = filename.replace(/\.[^.]+$/, '')
  return basename.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim()
}

export function KnowledgeDocumentFormDialog({
  supersededDocument = null,
  estimatedNextRevision,
  loading = false,
  onDetectMatchCandidates,
  onSubmit,
  onCancel,
}: KnowledgeDocumentFormDialogProps) {
  const titleId = useId()
  const typeId = useId()
  const versionId = useId()
  const effectiveAtId = useId()
  const fileId = useId()
  const [title, setTitle] = useState(supersededDocument?.title ?? '')
  const [documentType, setDocumentType] = useState<KnowledgeDocumentType>(
    supersededDocument?.document_type ?? 'other',
  )
  const [versionLabel, setVersionLabel] = useState('')
  const [effectiveAt, setEffectiveAt] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [matchCandidates, setMatchCandidates] = useState<KnowledgeDocumentMatchCandidate[]>([])
  const [matchChoice, setMatchChoice] = useState<MatchChoice | null>(null)
  const [matchDetecting, setMatchDetecting] = useState(false)
  const [selectedMatch, setSelectedMatch] = useState<KnowledgeDocumentMatchCandidate | null>(null)

  const isVersionUpload = supersededDocument !== null
  const nextRevisionNumber = isVersionUpload
    ? (estimatedNextRevision ?? supersededDocument.revision_number + 1)
    : matchChoice === 'new_version' && selectedMatch
      ? selectedMatch.chain_head_revision_number + 1
      : 1

  const supersedesDocumentId = isVersionUpload
    ? supersededDocument.id
    : matchChoice === 'new_version' && selectedMatch
      ? selectedMatch.chain_head_id
      : null

  function clearFieldError(field: string) {
    if (fieldErrors[field]) {
      setFieldErrors((current) => {
        const next = { ...current }
        delete next[field]
        return next
      })
    }
  }

  async function handleFileChange(selectedFile: File | null) {
    setFile(selectedFile)
    clearFieldError('file')

    if (selectedFile && !isVersionUpload && title.trim() === '') {
      setTitle(titleFromFilename(selectedFile.name))
    }

    if (!selectedFile || isVersionUpload || !onDetectMatchCandidates) {
      setMatchCandidates([])
      setMatchChoice(null)
      setSelectedMatch(null)
      setMatchDetecting(false)
      return
    }

    setMatchDetecting(true)
    setMatchChoice('pending')
    setSelectedMatch(null)

    const detectionTitle =
      title.trim() !== '' ? title.trim() : titleFromFilename(selectedFile.name)

    try {
      const candidates = await onDetectMatchCandidates(selectedFile.name, detectionTitle)
      setMatchCandidates(candidates)
      setMatchChoice(candidates.length > 0 ? 'pending' : null)
      setSelectedMatch(candidates[0] ?? null)
    } catch {
      setMatchCandidates([])
      setMatchChoice(null)
      setSelectedMatch(null)
    } finally {
      setMatchDetecting(false)
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFieldErrors({})
    setFormError(null)

    const nextFieldErrors: Record<string, string[]> = {}

    if (!file) {
      nextFieldErrors.file = ['A file is required.']
    }

    if (!isVersionUpload && matchCandidates.length > 0 && matchChoice === 'pending') {
      setFormError('Choose whether this upload is a new version or a separate document.')
      return
    }

    if (Object.keys(nextFieldErrors).length > 0) {
      setFieldErrors(nextFieldErrors)
      return
    }

    const resolvedTitle = title.trim() !== '' ? title.trim() : titleFromFilename(file?.name ?? '')

    try {
      await onSubmit({
        file: file as File,
        title: resolvedTitle,
        document_type: documentType,
        version_label: versionLabel.trim() !== '' ? versionLabel.trim() : null,
        effective_at: effectiveAt !== '' ? effectiveAt : null,
        supersedes_document_id: supersedesDocumentId,
      })
    } catch (error) {
      if (isApiValidationError(error)) {
        setFieldErrors(error.fieldErrors)
        return
      }

      setFormError(error instanceof Error ? error.message : 'Upload failed.')
    }
  }

  return (
    <FormDialog
      open
      title={isVersionUpload ? 'Upload new version' : 'Upload project document'}
      description={
        isVersionUpload
          ? `Creates revision ${nextRevisionNumber}, replacing revision ${supersededDocument.revision_number} of "${supersededDocument.title}". The previous revision stays until you activate this one.`
          : 'Upload governed documentation indexed for copilot retrieval when active and ready.'
      }
      submitLabel={loading ? 'Uploading…' : 'Upload'}
      loading={loading}
      onSubmit={handleSubmit}
      onCancel={onCancel}
      error={formError}
    >
      {isVersionUpload && (
        <Alert variant="info">
          Replaces revision {supersededDocument.revision_number}
          {supersededDocument.version_label ? ` (${supersededDocument.version_label})` : ''} of{' '}
          {supersededDocument.title}. New upload will be revision {nextRevisionNumber}.
        </Alert>
      )}

      {!isVersionUpload && matchDetecting && (
        <Alert variant="info">Checking for existing documents with a similar name…</Alert>
      )}

      {!isVersionUpload && matchCandidates.length > 0 && matchChoice === 'pending' && selectedMatch && (
        <Alert variant="info">
          <p>
            Found an existing document that may match this upload:{' '}
            <strong>
              {selectedMatch.title} (revision {selectedMatch.chain_head_revision_number})
            </strong>
            .
          </p>
          <div className="form-actions" style={{ marginTop: '0.75rem' }}>
            <Button
              type="button"
              variant="primary"
              size="sm"
              onClick={() => {
                setMatchChoice('new_version')
                setTitle(selectedMatch.title)
              }}
            >
              Upload as new version
            </Button>
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() => setMatchChoice('separate')}
            >
              Create separate document
            </Button>
          </div>
        </Alert>
      )}

      {!isVersionUpload && matchChoice === 'new_version' && selectedMatch && (
        <Alert variant="info">
          Uploading as revision {nextRevisionNumber} of {selectedMatch.title}, replacing revision{' '}
          {selectedMatch.chain_head_revision_number}.
        </Alert>
      )}

      <FormField label="Title" htmlFor={titleId} error={fieldError(fieldErrors, 'title')}>
        <FormInput
          id={titleId}
          value={title}
          onChange={(event) => {
            setTitle(event.target.value)
            clearFieldError('title')
          }}
          placeholder="Defaults from filename"
        />
      </FormField>

      <FormField label="Document type" htmlFor={typeId} error={fieldError(fieldErrors, 'document_type')}>
        <FormSelect
          id={typeId}
          value={documentType}
          onChange={(event) => {
            setDocumentType(event.target.value as KnowledgeDocumentType)
            clearFieldError('document_type')
          }}
        >
          {KNOWLEDGE_DOCUMENT_TYPES.map((type) => (
            <option key={type.value} value={type.value}>
              {type.label}
            </option>
          ))}
        </FormSelect>
      </FormField>

      <FormField
        label="Version label (optional)"
        htmlFor={versionId}
        error={fieldError(fieldErrors, 'version_label')}
      >
        <FormInput
          id={versionId}
          value={versionLabel}
          onChange={(event) => {
            setVersionLabel(event.target.value)
            clearFieldError('version_label')
          }}
          placeholder="e.g. v2.1"
        />
      </FormField>

      <FormField
        label="Effective date (optional)"
        htmlFor={effectiveAtId}
        error={fieldError(fieldErrors, 'effective_at')}
      >
        <FormInput
          id={effectiveAtId}
          type="date"
          value={effectiveAt}
          onChange={(event) => {
            setEffectiveAt(event.target.value)
            clearFieldError('effective_at')
          }}
        />
      </FormField>

      <FormField label="File" htmlFor={fileId} error={fieldError(fieldErrors, 'file')}>
        <FileDropzone
          id={fileId}
          accept=".pdf,.txt,.md,text/plain,text/markdown,application/pdf"
          value={file}
          onChange={(selectedFile) => {
            void handleFileChange(selectedFile)
          }}
        />
      </FormField>
    </FormDialog>
  )
}
