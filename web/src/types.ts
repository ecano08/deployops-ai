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
