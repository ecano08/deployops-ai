import { ApiError, ApiValidationError } from './lib/apiError'
import type {
  AiHealthSummaryResponse,
  AiProposedActionListResponse,
  AiTraceListResponse,
  AuthResponse,
  CopilotResponse,
  CreateIntegrationPayload,
  CustomerListResponse,
  CustomerResponse,
  DeploymentListResponse,
  DeploymentResponse,
  DeploymentStage,
  EvaluationDatasetListResponse,
  EvaluationDatasetResponse,
  EvaluationCaseResponse,
  EvaluationRunListResponse,
  EvaluationRunResponse,
  GroundedContextResponse,
  IncidentListResponse,
  IntegrationListResponse,
  IntegrationResponse,
  IntegrationTestResponse,
  KnowledgeDocumentLibraryListResponse,
  KnowledgeDocumentLibraryQuery,
  KnowledgeDocumentMatchCandidateListResponse,
  KnowledgeDocumentResponse,
  ProjectFactExtractionResponse,
  ProjectFactListQuery,
  ProjectFactListResponse,
  ProjectFactBulkResponse,
  ProjectFactResponse,
  UpdateIntegrationPayload,
  UserResponse,
  WorkspaceListResponse,
  WorkspaceInvitationListResponse,
  WorkspaceInvitationResponse,
  WorkspaceMemberListResponse,
  WorkspaceMemberResponse,
  WorkspaceResponse,
  WorkspaceRole,
} from './types'

const TOKEN_KEY = 'deployops_token'

type ApiErrorPayload = {
  message?: string
  errors?: Record<string, string[]>
  reference?: string | number | null
}

function throwApiError(payload: ApiErrorPayload, status: number): never {
  const firstError = payload.errors
    ? Object.values(payload.errors)[0]?.[0]
    : undefined
  const reference =
    payload.reference !== undefined && payload.reference !== null
      ? String(payload.reference)
      : null

  if (payload.errors) {
    throw new ApiValidationError(
      firstError ?? payload.message ?? `HTTP ${status}`,
      payload.errors,
    )
  }

  throw new ApiError(firstError ?? payload.message ?? `HTTP ${status}`, reference)
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')

  if (options.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  const token = getToken()

  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`${import.meta.env.VITE_API_URL}${path}`, {
    ...options,
    headers,
  })

  const payload = (await response.json().catch(() => ({}))) as ApiErrorPayload & T

  if (!response.ok) {
    throwApiError(payload, response.status)
  }

  return payload
}

export function register(name: string, email: string, password: string, passwordConfirmation: string) {
  return request<AuthResponse>('/api/register', {
    method: 'POST',
    body: JSON.stringify({
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    }),
  })
}

export function login(email: string, password: string) {
  return request<AuthResponse>('/api/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
}

export function logout() {
  return request<{ message: string }>('/api/logout', { method: 'POST' })
}

export function fetchCurrentUser() {
  return request<UserResponse>('/api/user')
}

export function fetchWorkspaces() {
  return request<WorkspaceListResponse>('/api/workspaces')
}

export function createWorkspace(name: string) {
  return request<WorkspaceResponse>('/api/workspaces', {
    method: 'POST',
    body: JSON.stringify({ name }),
  })
}

export function fetchWorkspaceMembers(workspaceId: number) {
  return request<WorkspaceMemberListResponse>(`/api/workspaces/${workspaceId}/members`)
}

export function createWorkspaceMember(
  workspaceId: number,
  payload: { email: string; role: Exclude<WorkspaceRole, 'owner'> },
) {
  return request<WorkspaceMemberResponse>(`/api/workspaces/${workspaceId}/members`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateWorkspaceMember(
  workspaceId: number,
  memberId: number,
  payload: { role: Exclude<WorkspaceRole, 'owner'> },
) {
  return request<WorkspaceMemberResponse>(`/api/workspaces/${workspaceId}/members/${memberId}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteWorkspaceMember(workspaceId: number, memberId: number) {
  return request<void>(`/api/workspaces/${workspaceId}/members/${memberId}`, {
    method: 'DELETE',
  })
}

export function fetchWorkspaceInvitations(workspaceId: number) {
  return request<WorkspaceInvitationListResponse>(`/api/workspaces/${workspaceId}/invitations`)
}

export function inviteWorkspaceMember(
  workspaceId: number,
  payload: { email: string; role: Exclude<WorkspaceRole, 'owner'> },
) {
  return request<WorkspaceMemberResponse | WorkspaceInvitationResponse>(
    `/api/workspaces/${workspaceId}/invitations`,
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function fetchWorkspaceInvitation(token: string) {
  return request<WorkspaceInvitationResponse>(`/api/invitations/${token}`)
}

export function acceptWorkspaceInvitation(
  token: string,
  payload: { name: string; password: string; passwordConfirmation: string },
) {
  return request<AuthResponse>(`/api/invitations/${token}/accept`, {
    method: 'POST',
    body: JSON.stringify({
      name: payload.name,
      password: payload.password,
      password_confirmation: payload.passwordConfirmation,
    }),
  })
}

export function fetchCustomers(workspaceId: number) {
  return request<CustomerListResponse>(`/api/workspaces/${workspaceId}/customers`)
}

export function createCustomer(
  workspaceId: number,
  payload: { name: string; description?: string | null },
) {
  return request<CustomerResponse>(`/api/workspaces/${workspaceId}/customers`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateCustomer(
  workspaceId: number,
  customerId: number,
  payload: { name?: string; description?: string | null },
) {
  return request<CustomerResponse>(`/api/workspaces/${workspaceId}/customers/${customerId}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteCustomer(workspaceId: number, customerId: number) {
  return request<void>(`/api/workspaces/${workspaceId}/customers/${customerId}`, {
    method: 'DELETE',
  })
}

export function fetchDeployments(workspaceId: number, customerId: number) {
  return request<DeploymentListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments`,
  )
}

export function createDeployment(
  workspaceId: number,
  customerId: number,
  payload: { name: string; description?: string | null; stage?: DeploymentStage },
) {
  return request<DeploymentResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments`,
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function updateDeployment(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  payload: { name?: string; description?: string | null },
) {
  return request<DeploymentResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function updateDeploymentStage(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  stage: DeploymentStage,
) {
  return request<DeploymentResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/stage`,
    {
      method: 'PATCH',
      body: JSON.stringify({ stage }),
    },
  )
}

export function deleteDeployment(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
) {
  return request<void>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}`,
    { method: 'DELETE' },
  )
}

export function fetchIntegrations(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
) {
  return request<IntegrationListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/integrations`,
  )
}

export function createIntegration(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  payload: CreateIntegrationPayload,
) {
  return request<IntegrationResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/integrations`,
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function updateIntegration(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  integrationId: number,
  payload: UpdateIntegrationPayload,
) {
  return request<IntegrationResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/integrations/${integrationId}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deleteIntegration(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  integrationId: number,
) {
  return request<void>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/integrations/${integrationId}`,
    { method: 'DELETE' },
  )
}

export function testIntegration(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  integrationId: number,
) {
  return request<IntegrationTestResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/integrations/${integrationId}/test`,
    { method: 'POST' },
  )
}

export function askCopilot(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  message: string,
  history: Array<{ question: string; answer: string }> = [],
) {
  const payload: {
    message: string
    history?: Array<{ question: string; answer: string }>
    history_deployment_id?: number
  } = { message }

  if (history.length > 0) {
    payload.history = history
    payload.history_deployment_id = deploymentId
  }

  return request<CopilotResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/copilot`,
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function fetchEvaluationDatasets(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
) {
  return request<EvaluationDatasetListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/evaluation-datasets`,
  )
}

export function createEvaluationDataset(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  payload: { name: string; description?: string | null },
) {
  return request<EvaluationDatasetResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/evaluation-datasets`,
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function updateEvaluationDataset(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  datasetId: number,
  payload: { name?: string; description?: string | null },
) {
  return request<EvaluationDatasetResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/evaluation-datasets/${datasetId}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deleteEvaluationDataset(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  datasetId: number,
) {
  return request<void>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/evaluation-datasets/${datasetId}`,
    { method: 'DELETE' },
  )
}

export function createEvaluationCase(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  datasetId: number,
  payload: {
    input: string
    expected_behavior: string
    expected_tools?: string[] | null
    expected_sources?: string[] | null
  },
) {
  return request<EvaluationCaseResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/evaluation-datasets/${datasetId}/cases`,
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function updateEvaluationCase(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  datasetId: number,
  caseId: number,
  payload: {
    input?: string
    expected_behavior?: string
    expected_tools?: string[] | null
    expected_sources?: string[] | null
  },
) {
  return request<EvaluationCaseResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/evaluation-datasets/${datasetId}/cases/${caseId}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deleteEvaluationCase(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  datasetId: number,
  caseId: number,
) {
  return request<void>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/evaluation-datasets/${datasetId}/cases/${caseId}`,
    { method: 'DELETE' },
  )
}

export function fetchEvaluationRuns(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
) {
  return request<EvaluationRunListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/evaluation-runs`,
  )
}

export function runEvaluationDataset(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  datasetId: number,
) {
  return request<EvaluationRunResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/evaluation-datasets/${datasetId}/runs`,
    { method: 'POST' },
  )
}

export function fetchPendingAiActions(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
) {
  return request<AiProposedActionListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/ai-actions/pending`,
  )
}

export function approveAiAction(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  actionId: number,
) {
  return request<AiProposedActionListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/ai-actions/${actionId}/approve`,
    { method: 'POST' },
  )
}

export function rejectAiAction(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  actionId: number,
) {
  return request<AiProposedActionListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/ai-actions/${actionId}/reject`,
    { method: 'POST' },
  )
}

export function fetchAiHealth(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
) {
  return request<AiHealthSummaryResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/ai-health`,
  )
}

export function fetchAiTraces(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
) {
  return request<AiTraceListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/ai-traces`,
  )
}

export function fetchIncidents(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
) {
  return request<IncidentListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/incidents`,
  )
}

export function fetchKnowledgeDocumentMatchCandidates(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  payload: { filename: string; title?: string | null },
) {
  return request<KnowledgeDocumentMatchCandidateListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/knowledge-documents/match-candidates`,
    {
      method: 'POST',
      body: JSON.stringify({
        filename: payload.filename,
        title: payload.title ?? null,
      }),
    },
  )
}

export function fetchKnowledgeDocuments(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  query: KnowledgeDocumentLibraryQuery = {},
) {
  const params = new URLSearchParams()

  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      params.set(key, String(value))
    }
  })

  const queryString = params.toString()
  const suffix = queryString ? `?${queryString}` : ''

  return request<KnowledgeDocumentLibraryListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/knowledge-documents${suffix}`,
  )
}

export function fetchKnowledgeDocument(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  documentId: number,
) {
  return request<KnowledgeDocumentResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/knowledge-documents/${documentId}`,
  )
}

export function knowledgeDocumentContentPath(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  documentId: number,
): string {
  return `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/knowledge-documents/${documentId}/content`
}

export type KnowledgeDocumentPreviewError = {
  message: string
  preview_state: string
  error_message?: string | null
}

export async function fetchKnowledgeDocumentContent(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  documentId: number,
): Promise<Blob> {
  const headers = new Headers({ Accept: '*/*' })
  const token = getToken()

  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(
    `${import.meta.env.VITE_API_URL}${knowledgeDocumentContentPath(workspaceId, customerId, deploymentId, documentId)}`,
    { headers },
  )

  if (!response.ok) {
    const payload = (await response.json().catch(() => ({}))) as KnowledgeDocumentPreviewError
    const error = new Error(payload.message ?? `HTTP ${response.status}`)
    ;(error as Error & { previewState?: string }).previewState = payload.preview_state
  ;(error as Error & { errorMessage?: string | null }).errorMessage = payload.error_message ?? null
    throw error
  }

  return response.blob()
}

export function uploadKnowledgeDocument(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  payload: {
    file: File
    title: string
    document_type: string
    version_label?: string | null
    effective_at?: string | null
    supersedes_document_id?: number | null
  },
) {
  const formData = new FormData()
  formData.append('file', payload.file)
  formData.append('title', payload.title)
  formData.append('document_type', payload.document_type)

  if (payload.version_label) {
    formData.append('version_label', payload.version_label)
  }

  if (payload.effective_at) {
    formData.append('effective_at', payload.effective_at)
  }

  if (payload.supersedes_document_id) {
    formData.append('supersedes_document_id', String(payload.supersedes_document_id))
  }

  const headers = new Headers({ Accept: 'application/json' })
  const token = getToken()

  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  return fetch(
    `${import.meta.env.VITE_API_URL}/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/knowledge-documents`,
    {
      method: 'POST',
      headers,
      body: formData,
    },
  ).then(async (response) => {
    const payload = (await response.json().catch(() => ({}))) as KnowledgeDocumentResponse & {
      message?: string
      errors?: Record<string, string[]>
    }

    if (!response.ok) {
      const firstError = payload.errors
        ? Object.values(payload.errors)[0]?.[0]
        : undefined

      throw new Error(firstError ?? payload.message ?? `HTTP ${response.status}`)
    }

    return payload
  })
}

export function deleteKnowledgeDocument(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  documentId: number,
) {
  return request<void>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/knowledge-documents/${documentId}`,
    { method: 'DELETE' },
  )
}

export function activateKnowledgeDocument(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  documentId: number,
) {
  return request<KnowledgeDocumentResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/knowledge-documents/${documentId}/activate`,
    { method: 'POST' },
  )
}

export function archiveKnowledgeDocument(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  documentId: number,
) {
  return request<KnowledgeDocumentResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/knowledge-documents/${documentId}/archive`,
    { method: 'POST' },
  )
}

export function fetchProjectFacts(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  query: ProjectFactListQuery = {},
) {
  const params = new URLSearchParams()

  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      params.set(key, String(value))
    }
  })

  const queryString = params.toString()
  const suffix = queryString ? `?${queryString}` : ''

  return request<ProjectFactListResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/project-facts${suffix}`,
  )
}

export function createProjectFact(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  payload: {
    category: string
    key: string
    value: string
    source_document_id?: number | null
    source_reference?: string | null
    confidence?: number | null
  },
) {
  return request<ProjectFactResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/project-facts`,
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function updateProjectFact(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  factId: number,
  payload: {
    category?: string
    key?: string
    value?: string
    source_reference?: string | null
    confidence?: number | null
  },
) {
  return request<ProjectFactResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/project-facts/${factId}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function verifyProjectFact(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  factId: number,
) {
  return request<ProjectFactResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/project-facts/${factId}/verify`,
    { method: 'POST' },
  )
}

export function rejectProjectFact(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  factId: number,
) {
  return request<ProjectFactResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/project-facts/${factId}/reject`,
    { method: 'POST' },
  )
}

export function bulkVerifyProjectFacts(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  ids: number[],
) {
  return request<ProjectFactBulkResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/project-facts/bulk-verify`,
    {
      method: 'POST',
      body: JSON.stringify({ ids }),
    },
  )
}

export function bulkRejectProjectFacts(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  payload: { ids: number[] } | { source_document_id: number; source_revision: number },
) {
  return request<ProjectFactBulkResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/project-facts/bulk-reject`,
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function extractProjectFacts(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  documentId: number,
) {
  return request<ProjectFactExtractionResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/knowledge-documents/${documentId}/extract-facts`,
    { method: 'POST' },
  )
}

export function fetchProjectFactExtraction(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  extractionId: number,
) {
  return request<ProjectFactExtractionResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/project-fact-extractions/${extractionId}`,
  )
}

export function buildGroundedContext(
  workspaceId: number,
  customerId: number,
  deploymentId: number,
  query: string,
) {
  return request<GroundedContextResponse>(
    `/api/workspaces/${workspaceId}/customers/${customerId}/deployments/${deploymentId}/grounded-context`,
    {
      method: 'POST',
      body: JSON.stringify({ query }),
    },
  )
}
