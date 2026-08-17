import { useState } from 'react'
import { Layers, Mail, UserPlus, Users } from 'lucide-react'
import { MemberFormDialog } from '../components/forms/MemberFormDialog'
import { Alert } from '../components/ui/Alert'
import { Badge } from '../components/ui/Badge'
import { roleBadgeVariant, statusBadgeVariant } from '../components/ui/badgeUtils'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { CopyInvitationLinkButton } from '../components/ui/CopyInvitationLinkButton'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { Icon } from '../components/ui/Icon'
import { LoadingState } from '../components/ui/LoadingState'
import { canManageMembers } from '../lib/permissions'
import {
  ASSIGNABLE_WORKSPACE_ROLES,
  type InviteWorkspaceMemberResult,
  type Workspace,
  type WorkspaceInvitation,
  type WorkspaceMember,
  type WorkspaceRole,
} from '../types'

type TeamPageProps = {
  workspace: Workspace | null
  members: WorkspaceMember[]
  invitations: WorkspaceInvitation[]
  loading: boolean
  error: string | null
  invitationsError: string | null
  message: string | null
  onInviteMember: (payload: {
    email: string
    role: Exclude<WorkspaceRole, 'owner'>
  }) => Promise<InviteWorkspaceMemberResult>
  onUpdateMemberRole: (
    memberId: number,
    role: Exclude<WorkspaceRole, 'owner'>,
  ) => Promise<void>
  onRemoveMember: (memberId: number) => Promise<void>
}

function isProtectedOwner(workspace: Workspace, member: WorkspaceMember): boolean {
  return member.role === 'owner' || workspace.owner_id === member.id
}

export function TeamPage({
  workspace,
  members,
  invitations,
  loading,
  error,
  invitationsError,
  message,
  onInviteMember,
  onUpdateMemberRole,
  onRemoveMember,
}: TeamPageProps) {
  const [showInviteDialog, setShowInviteDialog] = useState(false)
  const [saving, setSaving] = useState(false)
  const [updatingMemberId, setUpdatingMemberId] = useState<number | null>(null)
  const [removeTarget, setRemoveTarget] = useState<WorkspaceMember | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  if (!workspace) {
    return (
      <EmptyState
        title="No workspace selected"
        description="Create or select a workspace to manage team members."
        icon={Layers}
      />
    )
  }

  const canManage = canManageMembers(workspace.current_user_role)
  const sortedMembers = [...members].sort((left, right) => left.name.localeCompare(right.name))
  const sortedInvitations = [...invitations].sort((left, right) => left.email.localeCompare(right.email))

  async function handleInviteMember(payload: {
    email: string
    role: Exclude<WorkspaceRole, 'owner'>
  }): Promise<InviteWorkspaceMemberResult> {
    setSaving(true)
    setActionError(null)

    try {
      const result = await onInviteMember(payload)

      if (result.type === 'member') {
        setShowInviteDialog(false)
      }

      return result
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Unable to invite member.')
      throw error
    } finally {
      setSaving(false)
    }
  }

  async function handleRoleChange(member: WorkspaceMember, role: Exclude<WorkspaceRole, 'owner'>) {
    if (role === member.role) {
      return
    }

    setUpdatingMemberId(member.id)
    setActionError(null)

    try {
      await onUpdateMemberRole(member.id, role)
    } catch (error) {
      setActionError(error instanceof Error ? error.message : 'Unable to update member role.')
    } finally {
      setUpdatingMemberId(null)
    }
  }

  async function confirmRemove() {
    if (!removeTarget) {
      return
    }

    setActionError(null)
    await onRemoveMember(removeTarget.id)
  }

  return (
    <div className="page-stack">
      {(message || actionError) && (
        <Alert variant={actionError ? 'error' : 'success'}>{actionError ?? message}</Alert>
      )}

      <Card
        title="Team members"
        description={`People with access to ${workspace.name}`}
        actions={
          canManage ? (
            <Button variant="primary" size="sm" onClick={() => setShowInviteDialog(true)}>
              <Icon icon={UserPlus} size="xs" />
              Invite member
            </Button>
          ) : undefined
        }
      >
        {loading && <LoadingState label="Loading team members…" />}
        {error && <ErrorState message={error} />}
        {!loading && !error && members.length === 0 && (
          <EmptyState
            compact
            title="No members yet"
            description="Workspace members will appear here once added."
            icon={Users}
          />
        )}
        {!loading && !error && sortedMembers.length > 0 && (
          <ul className="data-list">
            {sortedMembers.map((member) => {
              const protectedOwner = isProtectedOwner(workspace, member)

              return (
                <li key={member.id} className="data-list__item data-list__item--member">
                  <div className="data-list__member-info">
                    <span className="data-list__member-name">{member.name}</span>
                    <span className="data-list__member-email" title={member.email}>
                      {member.email}
                    </span>
                  </div>

                  <div className="data-list__actions">
                    {canManage && !protectedOwner ? (
                      <>
                        <label className="sr-only" htmlFor={`member-role-${member.id}`}>
                          Role for {member.name}
                        </label>
                        <select
                          id={`member-role-${member.id}`}
                          className="form-field__control member-role-select"
                          value={member.role}
                          disabled={updatingMemberId === member.id}
                          onChange={(event) =>
                            handleRoleChange(
                              member,
                              event.target.value as Exclude<WorkspaceRole, 'owner'>,
                            )
                          }
                        >
                          {ASSIGNABLE_WORKSPACE_ROLES.map((assignableRole) => (
                            <option key={assignableRole} value={assignableRole}>
                              {assignableRole}
                            </option>
                          ))}
                        </select>
                        <Button
                          variant="danger"
                          size="sm"
                          onClick={() => setRemoveTarget(member)}
                        >
                          Remove
                        </Button>
                      </>
                    ) : (
                      <Badge className="data-list__member-role" variant={roleBadgeVariant(member.role)}>
                        {member.role}
                      </Badge>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>
        )}

        {!canManage && !loading && !error && (
          <p className="state__hint">You can view team members but cannot change roles or membership.</p>
        )}
      </Card>

      <Card title="Pending invitations" description="People invited to this workspace who have not joined yet.">
        {loading && <LoadingState label="Loading invitations…" />}
        {invitationsError && <ErrorState message={invitationsError} />}
        {!loading && !invitationsError && sortedInvitations.length === 0 && (
          <EmptyState
            compact
            title="No pending invitations"
            description="Invitations for people without an account will appear here."
            icon={Mail}
          />
        )}
        {!loading && !invitationsError && sortedInvitations.length > 0 && (
          <ul className="data-list">
            {sortedInvitations.map((invitation) => (
              <li key={invitation.id} className="data-list__item data-list__item--member">
                <div className="data-list__member-info">
                  <span className="data-list__member-name">{invitation.email}</span>
                </div>
                <div className="data-list__actions">
                  <Badge variant={roleBadgeVariant(invitation.role)}>{invitation.role}</Badge>
                  <Badge variant={statusBadgeVariant(invitation.status)}>Pending</Badge>
                  {canManage && invitation.invitation_url && (
                    <CopyInvitationLinkButton url={invitation.invitation_url} />
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {showInviteDialog && (
        <MemberFormDialog
          loading={saving}
          onSubmit={handleInviteMember}
          onCancel={() => setShowInviteDialog(false)}
        />
      )}

      <ConfirmDialog
        open={removeTarget !== null}
        title="Remove team member?"
        description={`This will revoke ${removeTarget?.name}'s access to ${workspace.name}.`}
        confirmLabel="Remove member"
        onConfirm={confirmRemove}
        onCancel={() => setRemoveTarget(null)}
      />
    </div>
  )
}
