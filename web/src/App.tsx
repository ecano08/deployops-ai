import { useEffect, useState, type FormEvent } from 'react'
import {
  clearToken,
  createWorkspace,
  fetchCurrentUser,
  fetchWorkspaces,
  getToken,
  login,
  logout,
  register,
  setToken,
} from './api'
import type { User, Workspace } from './types'

type HealthResponse = {
  status: string
  ai_service: string
  details: {
    status: string
    service: string
  }
}

function App() {
  const [health, setHealth] = useState<HealthResponse | null>(null)
  const [healthError, setHealthError] = useState<string | null>(null)
  const [user, setUser] = useState<User | null>(null)
  const [workspaces, setWorkspaces] = useState<Workspace[]>([])
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [workspaceName, setWorkspaceName] = useState('')
  const [authError, setAuthError] = useState<string | null>(null)
  const [loadingUser, setLoadingUser] = useState(() => Boolean(getToken()))

  useEffect(() => {
    fetch(`${import.meta.env.VITE_API_URL}/api/health/ai`)
      .then((response) => {
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`)
        }

        return response.json()
      })
      .then(setHealth)
      .catch((error: Error) => setHealthError(error.message))
  }, [])

  useEffect(() => {
    if (!getToken()) {
      return
    }

    fetchCurrentUser()
      .then((response) => setUser(response.data))
      .catch(() => {
        clearToken()
        setUser(null)
      })
      .finally(() => setLoadingUser(false))
  }, [])

  useEffect(() => {
    if (!user) {
      return
    }

    fetchWorkspaces()
      .then((response) => setWorkspaces(response.data))
      .catch((error: Error) => setAuthError(error.message))
  }, [user])

  async function handleAuth(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setAuthError(null)

    try {
      const response =
        authMode === 'register'
          ? await register(name, email, password, passwordConfirmation)
          : await login(email, password)

      setToken(response.token)
      setUser(response.data)
      setPassword('')
      setPasswordConfirmation('')
    } catch (error) {
      setAuthError(error instanceof Error ? error.message : 'Authentication failed.')
    }
  }

  async function handleLogout() {
    try {
      await logout()
    } catch {
      // Token is cleared locally even if the API call fails.
    }

    clearToken()
    setUser(null)
    setWorkspaces([])
  }

  async function handleCreateWorkspace(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setAuthError(null)

    try {
      const response = await createWorkspace(workspaceName)
      setWorkspaces((current) => [response.data, ...current])
      setWorkspaceName('')
    } catch (error) {
      setAuthError(error instanceof Error ? error.message : 'Could not create workspace.')
    }
  }

  return (
    <main>
      <h1>DeployOps AI</h1>

      {!health && !healthError && <p>Checking services...</p>}

      {healthError && <p>Error: {healthError}</p>}

      {health && (
        <>
          <p>Laravel API: {health.status}</p>
          <p>AI Service: {health.details.status}</p>
        </>
      )}

      {loadingUser && <p>Loading session...</p>}

      {!loadingUser && !user && (
        <section>
          <h2>{authMode === 'login' ? 'Log in' : 'Register'}</h2>

          <form onSubmit={handleAuth}>
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

            {authError && <p>{authError}</p>}

            <button type="submit">{authMode === 'login' ? 'Log in' : 'Register'}</button>
          </form>

          <p>
            <button
              type="button"
              onClick={() => {
                setAuthMode(authMode === 'login' ? 'register' : 'login')
                setAuthError(null)
              }}
            >
              {authMode === 'login' ? 'Need an account?' : 'Already have an account?'}
            </button>
          </p>
        </section>
      )}

      {!loadingUser && user && (
        <section>
          <h2>Signed in</h2>
          <p>
            {user.name} ({user.email})
          </p>
          <p>
            <button type="button" onClick={handleLogout}>
              Log out
            </button>
          </p>

          <h2>Workspaces</h2>

          <form onSubmit={handleCreateWorkspace}>
            <label>
              New workspace
              <input
                value={workspaceName}
                onChange={(event) => setWorkspaceName(event.target.value)}
                required
              />
            </label>
            <button type="submit">Create workspace</button>
          </form>

          {authError && <p>{authError}</p>}

          {workspaces.length === 0 ? (
            <p>No workspaces yet.</p>
          ) : (
            <ul>
              {workspaces.map((workspace) => (
                <li key={workspace.id}>
                  {workspace.name} ({workspace.slug})
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
    </main>
  )
}

export default App
