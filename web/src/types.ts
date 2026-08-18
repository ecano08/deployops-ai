export type WorkspaceRole = 'owner' | 'admin' | 'engineer' | 'viewer'

export type User = {
  id: number
  name: string
  email: string
}

export type WorkspaceMember = User & {
  role: WorkspaceRole
}

export type Workspace = {
  id: number
  name: string
  slug: string
  owner_id: number
  owner?: User
  current_user_role?: WorkspaceRole | null
}

export type AuthResponse = {
  data: User
  token: string
}

export type UserResponse = {
  data: User
}

export type WorkspaceListResponse = {
  data: Workspace[]
}

export type WorkspaceResponse = {
  data: Workspace
}

export type WorkspaceMemberListResponse = {
  data: WorkspaceMember[]
}

export type WorkspaceMemberResponse = {
  data: WorkspaceMember
}

export type WorkspaceInvitation = {
  id: number
  email: string
  role: Exclude<WorkspaceRole, 'owner'>
  status: 'pending' | 'accepted'
  expires_at: string
  invitation_url?: string
  workspace?: {
    name: string
  }
}

export type WorkspaceInvitationListResponse = {
  data: WorkspaceInvitation[]
}

export type WorkspaceInvitationResponse = {
  data: WorkspaceInvitation
}

export type InviteWorkspaceMemberResult =
  | { type: 'member'; member: WorkspaceMember }
  | { type: 'invitation'; invitation: WorkspaceInvitation }

export function isWorkspaceInvitation(
  data: WorkspaceMember | WorkspaceInvitation,
): data is WorkspaceInvitation {
  return 'status' in data && 'expires_at' in data
}

export const ASSIGNABLE_WORKSPACE_ROLES: Exclude<WorkspaceRole, 'owner'>[] = [
  'admin',
  'engineer',
  'viewer',
]

export type DeploymentStage =
  | 'discovery'
  | 'integration'
  | 'build'
  | 'validation'
  | 'deployed'

export const DEPLOYMENT_STAGES: DeploymentStage[] = [
  'discovery',
  'integration',
  'build',
  'validation',
  'deployed',
]

export type Customer = {
  id: number
  workspace_id: number
  name: string
  slug: string
  description: string | null
}

export type Deployment = {
  id: number
  workspace_id: number
  customer_id: number
  name: string
  description: string | null
  stage: DeploymentStage
}

export type CustomerListResponse = {
  data: Customer[]
}

export type CustomerResponse = {
  data: Customer
}

export type DeploymentListResponse = {
  data: Deployment[]
}

export type DeploymentResponse = {
  data: Deployment
}

export type IntegrationType = 'rest_api' | 'webhook'

export type IntegrationStatus = 'disconnected' | 'connected' | 'error'

export type DeploymentIntegration = {
  id: number
  workspace_id: number
  deployment_id: number
  type: IntegrationType
  name: string
  base_url: string | null
  endpoint: string | null
  status: IntegrationStatus
  config: Record<string, unknown> | null
  has_api_key: boolean
  has_webhook_secret: boolean
}

export type IntegrationListResponse = {
  data: DeploymentIntegration[]
}

export type IntegrationResponse = {
  data: DeploymentIntegration
}

export type CreateIntegrationPayload = {
  type: IntegrationType
  name: string
  base_url?: string | null
  endpoint?: string | null
  api_key?: string
  webhook_secret?: string
}

export type UpdateIntegrationPayload = {
  name?: string
  base_url?: string | null
  endpoint?: string | null
  api_key?: string | null
  webhook_secret?: string | null
}

export type IntegrationTestResponse = {
  data: {
    success: boolean
    status: IntegrationStatus
    metadata: Record<string, unknown>
    message: string | null
  }
}

export type CopilotResponse = {
  data: {
    answer: string
    tools_used: string[]
  }
}

export type CopilotHistoryTurn = {
  question: string
  answer: string
}

export type CopilotTurn = CopilotHistoryTurn & {
  id: string
  toolsUsed: string[]
}

export type EvaluationCase = {
  id: number
  evaluation_dataset_id: number
  input: string
  expected_behavior: string
  expected_tools: string[] | null
  expected_sources: string[] | null
}

export type EvaluationDataset = {
  id: number
  workspace_id: number
  customer_id: number
  deployment_id: number
  name: string
  description: string | null
  cases?: EvaluationCase[]
}

export type EvaluationRunMetrics = {
  total_cases: number
  passed_cases: number
  failed_cases: number
  pass_rate: number
  average_latency_ms: number
}

export type EvaluationRunResult = {
  id: number
  evaluation_case_id: number
  passed: boolean
  latency_ms: number
  tools_used: string[]
  sources_used: string[]
  answer: string | null
  error_message: string | null
  metrics: Record<string, boolean | null>
}

export type EvaluationRun = {
  id: number
  evaluation_dataset_id: number
  status: string
  metrics: EvaluationRunMetrics
  results?: EvaluationRunResult[]
}

export type AiActionStatus = 'pending' | 'approved' | 'rejected' | 'executed' | 'failed'

export type AiActionRequester = {
  id: number
  name: string | null
  email?: string | null
}

export function aiActionRequesterLabel(requester: AiActionRequester): string {
  return requester.name ?? 'unknown user'
}

export type AiProposedAction = {
  id: number
  action_type: string
  payload: Record<string, unknown>
  status: AiActionStatus
  requested_by: AiActionRequester
  approved_by: number | null
  executed_at: string | null
  error_message: string | null
}

export type EvaluationDatasetListResponse = {
  data: EvaluationDataset[]
}

export type EvaluationDatasetResponse = {
  data: EvaluationDataset
}

export type EvaluationCaseResponse = {
  data: EvaluationCase
}

export type EvaluationRunListResponse = {
  data: EvaluationRun[]
}

export type EvaluationRunResponse = {
  data: EvaluationRun
}

export type AiHealthSummary = {
  request_count: number
  failure_count: number
  failure_rate: number
  average_latency_ms: number
  total_input_tokens: number
  total_output_tokens: number
  estimated_cost_usd: number
  tool_failure_count: number
  rag_request_count: number
}

export type AiToolCallTrace = {
  id: number
  tool_name: string
  duration_ms: number
  status: string
  metadata: Record<string, unknown>
  created_at: string
}

export type AiTrace = {
  id: number
  workspace_id: number
  customer_id: number
  deployment_id: number
  user_id: number
  model: string
  question_preview: string
  tool_names: string[]
  input_tokens: number | null
  output_tokens: number | null
  rag_used: boolean
  rag_result_count: number
  estimated_cost_usd: string | number | null
  latency_ms: number
  status: string
  error_message: string | null
  tool_call_traces?: AiToolCallTrace[]
  created_at: string
}

export type IncidentSeverity = 'low' | 'medium' | 'high' | 'critical'
export type IncidentStatus = 'open' | 'investigating' | 'resolved'
export type IncidentSource = 'ai_failure' | 'integration_failure' | 'manual'

export type Incident = {
  id: number
  workspace_id: number
  customer_id: number
  deployment_id: number
  deployment_integration_id: number | null
  created_by: number | null
  severity: IncidentSeverity
  status: IncidentStatus
  source: IncidentSource
  source_reference: string | null
  title: string
  description: string
  root_cause: string | null
  resolution: string | null
  resolved_at: string | null
  created_at: string
  updated_at: string
}

export type AiHealthSummaryResponse = {
  data: AiHealthSummary
}

export type AiTraceListResponse = {
  data: AiTrace[]
}

export type IncidentListResponse = {
  data: Incident[]
}

export type AiProposedActionListResponse = {
  data: AiProposedAction[]
}

export type KnowledgeDocumentStatus = 'pending' | 'processing' | 'ready' | 'failed'

export type KnowledgeDocumentLifecycleStatus = 'draft' | 'active' | 'superseded' | 'archived'

export type KnowledgeDocumentType =
  | 'architecture'
  | 'business_rules'
  | 'database'
  | 'authorization'
  | 'integrations'
  | 'workflows'
  | 'technical_decision'
  | 'security'
  | 'conventions'
  | 'operations'
  | 'known_bugs'
  | 'other'

export const KNOWLEDGE_DOCUMENT_TYPES: { value: KnowledgeDocumentType; label: string }[] = [
  { value: 'architecture', label: 'Architecture' },
  { value: 'business_rules', label: 'Business rules' },
  { value: 'database', label: 'Database' },
  { value: 'authorization', label: 'Authorization' },
  { value: 'integrations', label: 'Integrations' },
  { value: 'workflows', label: 'Workflows' },
  { value: 'technical_decision', label: 'ADR / technical decision' },
  { value: 'security', label: 'Security' },
  { value: 'conventions', label: 'Conventions' },
  { value: 'operations', label: 'Operations' },
  { value: 'known_bugs', label: 'Known bugs' },
  { value: 'other', label: 'Other' },
]

export type KnowledgeDocumentPreviewFormat = 'pdf' | 'text' | 'markdown'

export type KnowledgeDocumentVersionSummary = {
  id: number
  title: string
  revision_number: number
  lifecycle_status: KnowledgeDocumentLifecycleStatus
  status: KnowledgeDocumentStatus
  version_label: string | null
  effective_at: string | null
  supersedes_document_id: number | null
  created_at: string
}

export type KnowledgeDocumentRevisionSummary = {
  id: number
  title: string
  document_type: KnowledgeDocumentType
  version_label: string | null
  revision_number: number
  lifecycle_status: KnowledgeDocumentLifecycleStatus
  effective_at: string | null
  original_filename: string
  mime_type: string
  size_bytes: number
  status: KnowledgeDocumentStatus
  error_message: string | null
  chunk_count: number
  created_at: string
  updated_at: string
}

export type KnowledgeDocumentLibraryEntry = {
  chain_root_id: number
  title: string
  document_type: KnowledgeDocumentType
  revision_count: number
  needs_attention: boolean
  attention_reason: string | null
  view_document_id: number
  updated_at: string
  effective_at: string | null
  active_revision: KnowledgeDocumentRevisionSummary | null
  chain_head: KnowledgeDocumentRevisionSummary
  attention_draft: KnowledgeDocumentRevisionSummary | null
}

export type KnowledgeDocumentLibraryStats = {
  revision_total: number
  ready_count: number
  active_count: number
  needs_attention_count: number
}

export type PaginationLinks = {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export type PaginationMeta = {
  current_page: number
  from: number | null
  last_page: number
  path: string
  per_page: number
  to: number | null
  total: number
}

export type KnowledgeDocumentLibraryQuery = {
  view?: 'current' | 'needs_attention' | 'archived'
  search?: string
  document_type?: KnowledgeDocumentType
  lifecycle_status?: KnowledgeDocumentLifecycleStatus
  attention?: 'needs_attention' | 'processing_failed' | 'draft_pending'
  status?: KnowledgeDocumentStatus
  sort?: 'updated_at' | 'title' | 'effective_at'
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

export type KnowledgeDocument = {
  id: number
  workspace_id: number
  customer_id: number
  deployment_id: number
  title: string
  document_type: KnowledgeDocumentType
  version_label: string | null
  revision_number: number
  lifecycle_status: KnowledgeDocumentLifecycleStatus
  effective_at: string | null
  supersedes_document_id: number | null
  supersedes?: {
    id: number
    title: string
    revision_number: number
  } | null
  metadata: Record<string, unknown> | null
  original_filename: string
  mime_type: string
  size_bytes: number
  status: KnowledgeDocumentStatus
  error_message: string | null
  chunk_count: number
  uploaded_by: number
  preview_format?: KnowledgeDocumentPreviewFormat | null
  version_history?: KnowledgeDocumentVersionSummary[]
  created_at: string
  updated_at: string
}

export type KnowledgeDocumentListResponse = {
  data: KnowledgeDocument[]
}

export type KnowledgeDocumentLibraryListResponse = {
  data: KnowledgeDocumentLibraryEntry[]
  links: PaginationLinks
  meta: PaginationMeta
  stats: KnowledgeDocumentLibraryStats
}

export type KnowledgeDocumentResponse = {
  data: KnowledgeDocument
}

export type KnowledgeDocumentMatchCandidate = {
  id: number
  title: string
  revision_number: number
  lifecycle_status: KnowledgeDocumentLifecycleStatus
  original_filename: string
  chain_head_id: number
  chain_head_revision_number: number
}

export type KnowledgeDocumentMatchCandidateListResponse = {
  data: KnowledgeDocumentMatchCandidate[]
}

export type ProjectFactStatus = 'proposed' | 'verified' | 'rejected' | 'superseded'

export type ProjectFactUser = {
  id: number
  name: string
  email: string
}

export type ProjectFactSourceDocument = {
  id: number
  title: string
  revision_number: number
  original_filename: string
}

export type ProjectFact = {
  id: number
  workspace_id: number
  customer_id: number
  deployment_id: number
  category: string
  key: string
  value: string
  source_document_id: number | null
  source_revision: number | null
  source_reference: string | null
  confidence: number | null
  status: ProjectFactStatus
  verified_at: string | null
  verified_by: ProjectFactUser | null
  superseded_by_id: number | null
  created_by: ProjectFactUser | null
  source_document: ProjectFactSourceDocument | null
  created_at: string
  updated_at: string
}

export type ProjectFactStats = {
  proposed_count: number
  verified_count: number
  rejected_count: number
}

export type ProjectFactFilterSourceDocument = {
  id: number
  title: string
  revision_number: number
}

export type ProjectFactFilterOptions = {
  categories: string[]
  source_documents: ProjectFactFilterSourceDocument[]
}

export type ProjectFactListQuery = {
  status?: ProjectFactStatus
  search?: string
  category?: string
  source_document_id?: number
  page?: number
  per_page?: number
}

export type ProjectFactListResponse = {
  data: ProjectFact[]
  meta: PaginationMeta
  stats: ProjectFactStats
  filter_options?: ProjectFactFilterOptions
}

export type ProjectFactResponse = {
  data: ProjectFact
}

export type ProjectFactBulkResponse = {
  data: ProjectFact[]
  stats: ProjectFactStats
  processed_count: number
}

export type ProjectFactExtractionStatus = 'pending' | 'processing' | 'completed' | 'failed'

export type ProjectFactExtraction = {
  id: number
  workspace_id: number
  customer_id: number
  deployment_id: number
  source_document_id: number
  source_revision: number
  status: ProjectFactExtractionStatus
  proposed_count: number
  error_message: string | null
  started_at: string | null
  completed_at: string | null
  created_at: string
  updated_at: string
}

export type ProjectFactExtractionResponse = {
  data: ProjectFactExtraction
}

export type GroundedContextKind =
  | 'documented'
  | 'verified_fact'
  | 'inferred'
  | 'unknown'
  | 'conflicting'

export type GroundedContextFact = {
  id: number
  category: string
  key: string
  value: string
  confidence: number | null
  relevance: number
  grounding: GroundedContextKind
  provenance: {
    type: 'project_fact'
    fact_id: number
    status: ProjectFactStatus
    source_document_id: number | null
    source_revision: number | null
    source_reference: string | null
    source_document: ProjectFactSourceDocument | null
    verified_at: string | null
  }
}

export type GroundedContextDocument = {
  document_id: number
  title: string
  source_filename: string
  revision_number: number
  chunk_index: number
  content: string
  score: number
  grounding: GroundedContextKind
  provenance: {
    type: 'knowledge_document'
    document_id: number
    title: string
    original_filename: string
    revision_number: number
    chunk_index: number
    lifecycle_status: string
    status: string
  }
}

export type GroundedContextConflictItem =
  | { type: 'project_fact'; id: number; value: string }
  | { type: 'knowledge_document'; document_id: number; chunk_index: number; excerpt: string }

export type GroundedContextConflict = {
  grounding: GroundedContextKind
  topic: string
  summary: string
  fact_ids: number[]
  document_ids: number[]
  items: GroundedContextConflictItem[]
}

export type GroundedContextUnknown = {
  grounding: GroundedContextKind
  topic: string
  reason: string
}

export type GroundedContextSource =
  | {
      type: 'project_fact'
      id: number
      label: string
      status: ProjectFactStatus
      source_document_id: number | null
      source_revision: number | null
    }
  | {
      type: 'knowledge_document'
      id: number
      title: string
      revision_number: number
      original_filename: string
      lifecycle_status: string
      status: string
    }

export type GroundedContextPackage = {
  query: string
  facts: GroundedContextFact[]
  documents: GroundedContextDocument[]
  conflicts: GroundedContextConflict[]
  unknowns: GroundedContextUnknown[]
  sources: GroundedContextSource[]
}

export type GroundedContextResponse = {
  data: GroundedContextPackage
}
