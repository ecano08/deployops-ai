import type { WorkspaceRole } from '../types'

export function canManageCustomers(role: WorkspaceRole | null | undefined): boolean {
  return role === 'owner' || role === 'admin'
}

export function canManageDeployments(role: WorkspaceRole | null | undefined): boolean {
  return role === 'owner' || role === 'admin' || role === 'engineer'
}

export function canApproveAiActions(role: WorkspaceRole | null | undefined): boolean {
  return role === 'owner' || role === 'admin'
}

export function canManageMembers(role: WorkspaceRole | null | undefined): boolean {
  return role === 'owner' || role === 'admin'
}
