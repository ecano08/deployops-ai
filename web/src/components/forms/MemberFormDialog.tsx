import { useId, useState, type FormEvent } from 'react'
import { fieldError, isApiValidationError } from '../../lib/apiError'
import { required } from '../../lib/validation'
import {
  ASSIGNABLE_WORKSPACE_ROLES,
  type InviteWorkspaceMemberResult,
  type WorkspaceInvitation,
  type WorkspaceRole,
} from '../../types'
import { CopyInvitationLinkButton } from '../ui/CopyInvitationLinkButton'
import { FormDialog } from '../ui/FormDialog'
import { FormField, FormInput, FormSelect } from '../ui/FormField'

type MemberFormDialogProps = {
  loading?: boolean
  onSubmit: (payload: {
    email: string
    role: Exclude<WorkspaceRole, 'owner'>
  }) => Promise<InviteWorkspaceMemberResult>
  onCancel: () => void
}

export function MemberFormDialog({ loading = false, onSubmit, onCancel }: MemberFormDialogProps) {
  const emailId = useId()
  const roleId = useId()
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<Exclude<WorkspaceRole, 'owner'>>('admin')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [createdInvitation, setCreatedInvitation] = useState<WorkspaceInvitation | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (createdInvitation) {
      onCancel()
      return
    }

    setFieldErrors({})
    setFormError(null)

    const nextFieldErrors: Record<string, string[]> = {}
    const emailError = required(email, 'Email')

    if (emailError) {
      nextFieldErrors.email = [emailError]
    }

    if (Object.keys(nextFieldErrors).length > 0) {
      setFieldErrors(nextFieldErrors)
      return
    }

    try {
      const result = await onSubmit({
        email: email.trim().toLowerCase(),
        role,
      })

      if (result.type === 'invitation') {
        setCreatedInvitation(result.invitation)
      }
    } catch (error) {
      if (isApiValidationError(error)) {
        setFieldErrors(error.fieldErrors)
        return
      }

      setFormError(error instanceof Error ? error.message : 'Unable to invite member.')
    }
  }

  if (createdInvitation?.invitation_url) {
    return (
      <FormDialog
        open
        title="Invitation created"
        description={`Share this link with ${createdInvitation.email} so they can join as ${createdInvitation.role}.`}
        submitLabel="Done"
        loading={false}
        onSubmit={handleSubmit}
        onCancel={onCancel}
      >
        <FormField label="Invitation link" htmlFor={`${emailId}-invite-link`}>
          <FormInput
            id={`${emailId}-invite-link`}
            readOnly
            value={createdInvitation.invitation_url}
          />
        </FormField>
        <div className="invite-link-actions">
          <CopyInvitationLinkButton url={createdInvitation.invitation_url} />
        </div>
      </FormDialog>
    )
  }

  return (
    <FormDialog
      open
      title="Invite member"
      description="Invite someone by email. Existing users are added immediately; new users receive an invitation link."
      submitLabel="Invite member"
      loading={loading}
      error={formError}
      onSubmit={handleSubmit}
      onCancel={onCancel}
    >
      <FormField label="Email" htmlFor={emailId} error={fieldError(fieldErrors, 'email')}>
        <FormInput
          id={emailId}
          type="email"
          value={email}
          autoComplete="email"
          onChange={(event) => {
            setEmail(event.target.value)
            if (fieldErrors.email) {
              setFieldErrors((current) => {
                const next = { ...current }
                delete next.email
                return next
              })
            }
          }}
          autoFocus
        />
      </FormField>

      <FormField label="Role" htmlFor={roleId} error={fieldError(fieldErrors, 'role')}>
        <FormSelect
          id={roleId}
          value={role}
          onChange={(event) => {
            setRole(event.target.value as Exclude<WorkspaceRole, 'owner'>)
            if (fieldErrors.role) {
              setFieldErrors((current) => {
                const next = { ...current }
                delete next.role
                return next
              })
            }
          }}
        >
          {ASSIGNABLE_WORKSPACE_ROLES.map((assignableRole) => (
            <option key={assignableRole} value={assignableRole}>
              {assignableRole}
            </option>
          ))}
        </FormSelect>
      </FormField>
    </FormDialog>
  )
}
