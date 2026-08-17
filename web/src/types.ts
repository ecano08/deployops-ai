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

export type KnowledgeDocument = {
  id: number
  workspace_id: number
  customer_id: number
  deployment_id: number
  original_filename: string
  mime_type: string
  size_bytes: number
  status: KnowledgeDocumentStatus
  error_message: string | null
  chunk_count: number
  uploaded_by: number
  created_at: string
  updated_at: string
}

export type KnowledgeDocumentListResponse = {
  data: KnowledgeDocument[]
}

export type KnowledgeDocumentResponse = {
  data: KnowledgeDocument
}
