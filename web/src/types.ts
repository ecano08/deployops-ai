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

export type AiProposedAction = {
  id: number
  action_type: string
  payload: Record<string, unknown>
  status: AiActionStatus
  requested_by: number
  approved_by: number | null
  executed_at: string | null
  error_message: string | null
}

export type EvaluationDatasetListResponse = {
  data: EvaluationDataset[]
}

export type EvaluationRunListResponse = {
  data: EvaluationRun[]
}

export type EvaluationRunResponse = {
  data: EvaluationRun
}

export type AiProposedActionListResponse = {
  data: AiProposedAction[]
}
