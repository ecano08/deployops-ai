import { useState, type FormEvent } from 'react'
import { login, register, setToken } from '../api'
import { Alert } from '../components/ui/Alert'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'

type AuthPageProps = {
  onAuthenticated: () => void
}

export function AuthPage({ onAuthenticated }: AuthPageProps) {
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [authError, setAuthError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  async function handleAuth(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setAuthError(null)
    setLoading(true)

    try {
      const response =
        authMode === 'register'
          ? await register(name, email, password, passwordConfirmation)
          : await login(email, password)

      setToken(response.token)
      onAuthenticated()
    } catch (error) {
      setAuthError(error instanceof Error ? error.message : 'Authentication failed.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="auth-page">
      <div className="auth-page__hero">
        <p className="auth-page__eyebrow">Forward Deployed Engineering</p>
        <h1>DeployOps AI</h1>
        <p className="auth-page__tagline">
          Operate customer deployments, integrations, and AI copilots from one secure workspace.
        </p>
      </div>

      <Card title={authMode === 'login' ? 'Sign in' : 'Create account'}>
        <form className="form" onSubmit={handleAuth}>
          {authMode === 'register' && (
            <label>
              Name
              <input
                value={name}
                onChange={(event) => setName(event.target.value)}
                autoComplete="name"
                required
              />
            </label>
          )}

          <label>
            Email
            <input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              autoComplete="email"
              required
            />
          </label>

          <label>
            Password
            <input
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete={authMode === 'login' ? 'current-password' : 'new-password'}
              required
            />
          </label>

          {authMode === 'register' && (
            <label>
              Confirm password
              <input
                type="password"
                value={passwordConfirmation}
                onChange={(event) => setPasswordConfirmation(event.target.value)}
                autoComplete="new-password"
                required
              />
            </label>
          )}

          {authError && <Alert variant="error">{authError}</Alert>}

          <Button type="submit" variant="primary" loading={loading} className="form__submit">
            {authMode === 'login' ? 'Sign in' : 'Create account'}
          </Button>
        </form>

        <p className="auth-page__switch">
          <button
            type="button"
            className="link-button"
            onClick={() => {
              setAuthMode(authMode === 'login' ? 'register' : 'login')
              setAuthError(null)
            }}
          >
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
