import { useState, type FormEvent } from 'react'
import { Bot, Layers, Plug, Radar, Shield } from 'lucide-react'
import { login, register, setToken } from '../api'
import { Alert } from '../components/ui/Alert'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { FormField } from '../components/ui/FormField'
import { Icon } from '../components/ui/Icon'
import { email, matches, minLength, required } from '../lib/validation'

type AuthPageProps = {
  onAuthenticated: () => void
}

type AuthFieldErrors = {
  name?: string
  email?: string
  password?: string
  passwordConfirmation?: string
}

const features = [
  { icon: Layers, label: 'Manage customer deployments across stages' },
  { icon: Plug, label: 'Connect and test customer integrations' },
  { icon: Bot, label: 'AI copilot with RAG-powered context' },
  { icon: Radar, label: 'Observability, evals, and approval gates' },
]

export function AuthPage({ onAuthenticated }: AuthPageProps) {
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login')
  const [name, setName] = useState('')
  const [emailValue, setEmailValue] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [fieldErrors, setFieldErrors] = useState<AuthFieldErrors>({})
  const [authError, setAuthError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  function validateForm(): boolean {
    const errors: AuthFieldErrors = {}

    if (authMode === 'register') {
      errors.name = required(name, 'Name') ?? undefined
    }

    errors.email = email(emailValue) ?? undefined
    errors.password =
      (authMode === 'register' ? minLength(password, 8) : required(password, 'Password')) ?? undefined

    if (authMode === 'register') {
      errors.passwordConfirmation =
        matches(passwordConfirmation, password, 'Passwords do not match') ?? undefined
    }

    const filtered = Object.fromEntries(
      Object.entries(errors).filter(([, value]) => value !== undefined),
    ) as AuthFieldErrors

    setFieldErrors(filtered)

    return Object.keys(filtered).length === 0
  }

  async function handleAuth(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setAuthError(null)

    if (!validateForm()) {
      return
    }

    setLoading(true)

    try {
      const response =
        authMode === 'register'
          ? await register(name, emailValue, password, passwordConfirmation)
          : await login(emailValue, password)

      setToken(response.token)
      onAuthenticated()
    } catch (error) {
      setAuthError(error instanceof Error ? error.message : 'Authentication failed.')
    } finally {
      setLoading(false)
    }
  }

  function switchMode() {
    setAuthMode(authMode === 'login' ? 'register' : 'login')
    setAuthError(null)
    setFieldErrors({})
  }

  return (
    <div className="auth-page">
      <div className="auth-page__hero">
        <p className="auth-page__eyebrow">
          <Icon icon={Shield} size="xs" />
          Forward Deployed Engineering
        </p>
        <h1>DeployOps AI</h1>
        <p className="auth-page__tagline">
          Operate customer deployments, integrations, and AI copilots from one secure workspace.
        </p>

        <div className="auth-page__features">
          {features.map((feature) => (
            <div key={feature.label} className="auth-page__feature">
              <span className="auth-page__feature-icon">
                <Icon icon={feature.icon} size="xs" />
              </span>
              {feature.label}
            </div>
          ))}
        </div>
      </div>

      <Card title={authMode === 'login' ? 'Sign in' : 'Create account'}>
        <form className="form" onSubmit={handleAuth} noValidate>
          {authMode === 'register' && (
            <FormField label="Name" error={fieldErrors.name}>
              <input
                value={name}
                onChange={(event) => {
                  setName(event.target.value)
                  if (fieldErrors.name) {
                    setFieldErrors((current) => ({ ...current, name: undefined }))
                  }
                }}
                autoComplete="name"
              />
            </FormField>
          )}

          <FormField label="Email" error={fieldErrors.email}>
            <input
              type="email"
              value={emailValue}
              onChange={(event) => {
                setEmailValue(event.target.value)
                if (fieldErrors.email) {
                  setFieldErrors((current) => ({ ...current, email: undefined }))
                }
              }}
              autoComplete="email"
            />
          </FormField>

          <FormField label="Password" error={fieldErrors.password}>
            <input
              type="password"
              value={password}
              onChange={(event) => {
                setPassword(event.target.value)
                if (fieldErrors.password) {
                  setFieldErrors((current) => ({ ...current, password: undefined }))
                }
              }}
              autoComplete={authMode === 'login' ? 'current-password' : 'new-password'}
            />
          </FormField>

          {authMode === 'register' && (
            <FormField label="Confirm password" error={fieldErrors.passwordConfirmation}>
              <input
                type="password"
                value={passwordConfirmation}
                onChange={(event) => {
                  setPasswordConfirmation(event.target.value)
                  if (fieldErrors.passwordConfirmation) {
                    setFieldErrors((current) => ({ ...current, passwordConfirmation: undefined }))
                  }
                }}
                autoComplete="new-password"
              />
            </FormField>
          )}

          {authError && <Alert variant="error">{authError}</Alert>}

          <Button type="submit" variant="primary" loading={loading} className="form__submit">
            {authMode === 'login' ? 'Sign in' : 'Create account'}
          </Button>
        </form>

        <p className="auth-page__switch">
          <button type="button" className="link-button" onClick={switchMode}>
            {authMode === 'login' ? 'Need an account? Register' : 'Already have an account? Sign in'}
          </button>
        </p>

        <p className="auth-page__demo-hint">
          Demo: <code>demo@deployops.ai</code> / <code>password</code>
        </p>
      </Card>
    </div>
  )
}
