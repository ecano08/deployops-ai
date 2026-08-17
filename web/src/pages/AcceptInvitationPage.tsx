import { useEffect, useId, useRef, useState, type FormEvent } from 'react'
import { Shield } from 'lucide-react'
import { acceptWorkspaceInvitation, fetchWorkspaceInvitation, setToken } from '../api'
import { Alert } from '../components/ui/Alert'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { FormField, FormInput } from '../components/ui/FormField'
import { Icon } from '../components/ui/Icon'
import { LoadingState } from '../components/ui/LoadingState'
import { fieldError, isApiValidationError } from '../lib/apiError'
import { matches, minLength, required } from '../lib/validation'
import type { WorkspaceInvitation } from '../types'

const ACCEPT_REDIRECT_DELAY_MS = 1600

type AcceptInvitationPageProps = {
  token: string
  onAccepted: () => void
}

export function AcceptInvitationPage({ token, onAccepted }: AcceptInvitationPageProps) {
  const nameId = useId()
  const passwordId = useId()
  const passwordConfirmationId = useId()
  const redirectTimeoutRef = useRef<ReturnType<typeof window.setTimeout> | null>(null)
  const [invitation, setInvitation] = useState<WorkspaceInvitation | null>(null)
  const [loadingInvitation, setLoadingInvitation] = useState(true)
  const [invitationError, setInvitationError] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [accepted, setAccepted] = useState(false)

  useEffect(() => {
    let cancelled = false

    fetchWorkspaceInvitation(token)
      .then((response) => {
        if (!cancelled) {
          setInvitation(response.data)
          setInvitationError(null)
        }
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setInvitation(null)
          setInvitationError(
            error instanceof Error ? error.message : 'This invitation is no longer valid.',
          )
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoadingInvitation(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [token])

  useEffect(() => {
    return () => {
      if (redirectTimeoutRef.current !== null) {
        window.clearTimeout(redirectTimeoutRef.current)
      }
    }
  }, [])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFieldErrors({})
    setFormError(null)

    const nextFieldErrors: Record<string, string[]> = {}
    const nameError = required(name, 'Name')
    const passwordError = minLength(password, 8)
    const confirmationError = matches(passwordConfirmation, password, 'Passwords do not match')

    if (nameError) {
      nextFieldErrors.name = [nameError]
    }

    if (passwordError) {
      nextFieldErrors.password = [passwordError]
    }

    if (confirmationError) {
      nextFieldErrors.password_confirmation = [confirmationError]
    }

    if (Object.keys(nextFieldErrors).length > 0) {
      setFieldErrors(nextFieldErrors)
      return
    }

    setSubmitting(true)

    try {
      const response = await acceptWorkspaceInvitation(token, {
        name: name.trim(),
        password,
        passwordConfirmation,
      })
      setToken(response.token)
      setAccepted(true)
      redirectTimeoutRef.current = window.setTimeout(() => {
        window.history.replaceState(null, '', '/')
        onAccepted()
      }, ACCEPT_REDIRECT_DELAY_MS)
    } catch (error) {
      if (isApiValidationError(error)) {
        setFieldErrors(error.fieldErrors)
        return
      }

      setFormError(error instanceof Error ? error.message : 'Unable to accept this invitation.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="auth-page">
      <div className="auth-page__hero">
        <p className="auth-page__eyebrow">
          <Icon icon={Shield} size="xs" />
          Workspace invitation
        </p>
        <h1>Join DeployOps AI</h1>
        <p className="auth-page__tagline">
          Create your account to accept this workspace invitation and start collaborating.
        </p>
      </div>

      <Card
        title={
          accepted
            ? 'Invitation accepted'
            : invitation
              ? `Join ${invitation.workspace?.name ?? 'workspace'}`
              : 'Workspace invitation'
        }
        description={
          accepted
            ? 'Your account is ready. Opening the workspace next.'
            : invitation
              ? `You were invited as ${invitation.role}.`
              : 'Validate your invitation to continue.'
        }
      >
        {loadingInvitation && <LoadingState label="Checking invitation…" />}

        {!loadingInvitation && invitationError && (
          <Alert variant="error">{invitationError}</Alert>
        )}

        {!loadingInvitation && accepted && invitation && (
          <>
            <Alert variant="success">
              You joined {invitation.workspace?.name ?? 'the workspace'} as {invitation.role}.
              Redirecting you to the workspace…
            </Alert>
            <LoadingState label="Opening workspace…" />
          </>
        )}

        {!loadingInvitation && invitation && !accepted && (
          <form className="form" onSubmit={handleSubmit} noValidate>
            <FormField label="Email" htmlFor={`${nameId}-email`}>
              <FormInput id={`${nameId}-email`} type="email" value={invitation.email} readOnly />
            </FormField>

            <FormField label="Name" htmlFor={nameId} error={fieldError(fieldErrors, 'name')}>
              <FormInput
                id={nameId}
                value={name}
                autoComplete="name"
                autoFocus
                onChange={(event) => setName(event.target.value)}
              />
            </FormField>

            <FormField
              label="Password"
              htmlFor={passwordId}
              error={fieldError(fieldErrors, 'password')}
            >
              <FormInput
                id={passwordId}
                type="password"
                value={password}
                autoComplete="new-password"
                onChange={(event) => setPassword(event.target.value)}
              />
            </FormField>

            <FormField
              label="Confirm password"
              htmlFor={passwordConfirmationId}
              error={fieldError(fieldErrors, 'password_confirmation')}
            >
              <FormInput
                id={passwordConfirmationId}
                type="password"
                value={passwordConfirmation}
                autoComplete="new-password"
                onChange={(event) => setPasswordConfirmation(event.target.value)}
              />
            </FormField>

            {formError && <Alert variant="error">{formError}</Alert>}

            <Button type="submit" variant="primary" loading={submitting} className="form__submit">
              Create account and join
            </Button>
          </form>
        )}
      </Card>
    </div>
  )
}
