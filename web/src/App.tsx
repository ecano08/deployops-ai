import { useEffect, useState, type FormEvent } from 'react'
import {
  clearToken,
  createWorkspace,
  fetchCurrentUser,
  fetchCustomers,
  fetchDeployments,
  fetchIntegrations,
  fetchWorkspaceMembers,
  fetchWorkspaces,
  getToken,
  login,
  logout,
  register,
  testIntegration,
  setToken,
} from './api'
import type { Customer, Deployment, DeploymentIntegration, User, Workspace, WorkspaceMember } from './types'
import { DEPLOYMENT_STAGES } from './types'

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
  const [selectedWorkspaceId, setSelectedWorkspaceId] = useState<number | null>(null)
  const [members, setMembers] = useState<WorkspaceMember[]>([])
  const [membersError, setMembersError] = useState<string | null>(null)
  const [membersLoading, setMembersLoading] = useState(false)
  const [customers, setCustomers] = useState<Customer[]>([])
  const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(null)
  const [customersError, setCustomersError] = useState<string | null>(null)
  const [customersLoading, setCustomersLoading] = useState(false)
  const [deployments, setDeployments] = useState<Deployment[]>([])
  const [deploymentsError, setDeploymentsError] = useState<string | null>(null)
  const [deploymentsLoading, setDeploymentsLoading] = useState(false)
  const [selectedDeploymentId, setSelectedDeploymentId] = useState<number | null>(null)
  const [integrations, setIntegrations] = useState<DeploymentIntegration[]>([])
  const [integrationsError, setIntegrationsError] = useState<string | null>(null)
  const [integrationsLoading, setIntegrationsLoading] = useState(false)
  const [integrationTestMessage, setIntegrationTestMessage] = useState<string | null>(null)
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [workspaceName, setWorkspaceName] = useState('')
  const [authError, setAuthError] = useState<string | null>(null)
  const [loadingUser, setLoadingUser] = useState(() => Boolean(getToken()))
  const selectedWorkspace = workspaces.find((workspace) => workspace.id === selectedWorkspaceId) ?? null
  const selectedCustomer = customers.find((customer) => customer.id === selectedCustomerId) ?? null
  const selectedDeployment = deployments.find((deployment) => deployment.id === selectedDeploymentId) ?? null
  const deploymentsByStage = DEPLOYMENT_STAGES.reduce<Record<string, Deployment[]>>((groups, stage) => {
    groups[stage] = deployments.filter((deployment) => deployment.stage === stage)
    return groups
  }, {})

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

  useEffect(() => {
    if (!user || selectedWorkspaceId === null) {
      return
    }

    let cancelled = false

    fetchWorkspaceMembers(selectedWorkspaceId)
      .then((response) => {
        if (!cancelled) {
          setMembers(response.data)
          setMembersError(null)
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setMembers([])
          setMembersError(error.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setMembersLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [user, selectedWorkspaceId])

  useEffect(() => {
    if (!user || selectedWorkspaceId === null) {
      return
    }

    let cancelled = false

    fetchCustomers(selectedWorkspaceId)
      .then((response) => {
        if (!cancelled) {
          setCustomers(response.data)
          setCustomersError(null)
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setCustomers([])
          setCustomersError(error.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setCustomersLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [user, selectedWorkspaceId])

  useEffect(() => {
    if (!user || selectedWorkspaceId === null || selectedCustomerId === null) {
      return
    }

    let cancelled = false

    fetchDeployments(selectedWorkspaceId, selectedCustomerId)
      .then((response) => {
        if (!cancelled) {
          setDeployments(response.data)
          setDeploymentsError(null)
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setDeployments([])
          setDeploymentsError(error.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setDeploymentsLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [user, selectedWorkspaceId, selectedCustomerId])

  useEffect(() => {
    if (!user || selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    let cancelled = false

    fetchIntegrations(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId)
      .then((response) => {
        if (!cancelled) {
          setIntegrations(response.data)
          setIntegrationsError(null)
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setIntegrations([])
          setIntegrationsError(error.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIntegrationsLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [user, selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

  function selectWorkspace(workspaceId: number) {
    setSelectedWorkspaceId(workspaceId)
    setSelectedCustomerId(null)
    setMembers([])
    setMembersError(null)
    setMembersLoading(true)
    setCustomers([])
    setCustomersError(null)
    setCustomersLoading(true)
    setDeployments([])
    setDeploymentsError(null)
    setDeploymentsLoading(false)
    setSelectedDeploymentId(null)
    setIntegrations([])
    setIntegrationsError(null)
    setIntegrationsLoading(false)
    setIntegrationTestMessage(null)
  }

  function selectCustomer(customerId: number) {
    setSelectedCustomerId(customerId)
    setDeployments([])
    setDeploymentsError(null)
    setDeploymentsLoading(true)
    setSelectedDeploymentId(null)
    setIntegrations([])
    setIntegrationsError(null)
    setIntegrationsLoading(false)
    setIntegrationTestMessage(null)
  }

  function selectDeployment(deploymentId: number) {
    setSelectedDeploymentId(deploymentId)
    setIntegrations([])
    setIntegrationsError(null)
    setIntegrationsLoading(true)
    setIntegrationTestMessage(null)
  }

  async function handleTestIntegration(integrationId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setIntegrationTestMessage(null)

    try {
      const response = await testIntegration(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
        integrationId,
      )
      setIntegrationTestMessage(
        response.data.success
          ? `Connection test succeeded (${response.data.status}).`
          : `Connection test failed (${response.data.status}).`,
      )
      const refreshed = await fetchIntegrations(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
      )
      setIntegrations(refreshed.data)
    } catch (error) {
      setIntegrationTestMessage(error instanceof Error ? error.message : 'Connection test failed.')
    }
  }

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
    setSelectedWorkspaceId(null)
    setSelectedCustomerId(null)
    setMembers([])
    setMembersError(null)
    setMembersLoading(false)
    setCustomers([])
    setCustomersError(null)
    setCustomersLoading(false)
    setDeployments([])
    setDeploymentsError(null)
    setDeploymentsLoading(false)
    setSelectedDeploymentId(null)
    setIntegrations([])
    setIntegrationsError(null)
    setIntegrationsLoading(false)
    setIntegrationTestMessage(null)
  }

  async function handleCreateWorkspace(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setAuthError(null)

    try {
      const response = await createWorkspace(workspaceName)
      setWorkspaces((current) => [response.data, ...current])
      selectWorkspace(response.data.id)
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
                  <button
                    type="button"
                    aria-pressed={workspace.id === selectedWorkspaceId}
                    onClick={() => selectWorkspace(workspace.id)}
                  >
                    {workspace.name} ({workspace.slug})
                    {workspace.current_user_role ? ` — ${workspace.current_user_role}` : ''}
                  </button>
                </li>
              ))}
            </ul>
          )}

          {selectedWorkspace && (
            <section>
              <h2>Members — {selectedWorkspace.name}</h2>
              {membersLoading && <p>Loading members...</p>}
              {membersError && <p>{membersError}</p>}
              {!membersLoading && members.length === 0 && !membersError && (
                <p>No members to display.</p>
              )}
              {!membersLoading && members.length > 0 && (
                <ul>
                  {members.map((member) => (
                    <li key={member.id}>
                      {member.name} ({member.email}) — {member.role}
                    </li>
                  ))}
                </ul>
              )}

              <h2>Customers — {selectedWorkspace.name}</h2>
              {customersLoading && <p>Loading customers...</p>}
              {customersError && <p>{customersError}</p>}
              {!customersLoading && customers.length === 0 && !customersError && (
                <p>No customers yet.</p>
              )}
              {!customersLoading && customers.length > 0 && (
                <ul>
                  {customers.map((customer) => (
                    <li key={customer.id}>
                      <button
                        type="button"
                        aria-pressed={customer.id === selectedCustomerId}
                        onClick={() => selectCustomer(customer.id)}
                      >
                        {customer.name} ({customer.slug})
                      </button>
                    </li>
                  ))}
                </ul>
              )}

              {selectedCustomer && (
                <section>
                  <h2>Deployments — {selectedCustomer.name}</h2>
                  {deploymentsLoading && <p>Loading deployments...</p>}
                  {deploymentsError && <p>{deploymentsError}</p>}
                  {!deploymentsLoading && deployments.length === 0 && !deploymentsError && (
                    <p>No deployments yet.</p>
                  )}
                  {!deploymentsLoading && deployments.length > 0 && (
                    <div>
                      {DEPLOYMENT_STAGES.map((stage) => (
                        <section key={stage}>
                          <h3>{stage}</h3>
                          {deploymentsByStage[stage]?.length ? (
                            <ul>
                              {deploymentsByStage[stage].map((deployment) => (
                                <li key={deployment.id}>
                                  <button
                                    type="button"
                                    aria-pressed={deployment.id === selectedDeploymentId}
                                    onClick={() => selectDeployment(deployment.id)}
                                  >
                                    {deployment.name}
                                    {deployment.description ? ` — ${deployment.description}` : ''}
                                  </button>
                                </li>
                              ))}
                            </ul>
                          ) : (
                            <p>No deployments in this stage.</p>
                          )}
                        </section>
                      ))}
                    </div>
                  )}
                  {selectedDeployment && (
                    <section>
                      <h2>Integrations — {selectedDeployment.name}</h2>
                      {integrationsLoading && <p>Loading integrations...</p>}
                      {integrationsError && <p>{integrationsError}</p>}
                      {integrationTestMessage && <p>{integrationTestMessage}</p>}
                      {!integrationsLoading && integrations.length === 0 && !integrationsError && (
                        <p>No integrations yet.</p>
                      )}
                      {!integrationsLoading && integrations.length > 0 && (
                        <ul>
                          {integrations.map((integration) => (
                            <li key={integration.id}>
                              {integration.name} ({integration.type}) — {integration.status}
                              <button
                                type="button"
                                onClick={() => handleTestIntegration(integration.id)}
                              >
                                Test connection
                              </button>
                            </li>
                          ))}
                        </ul>
                      )}
                    </section>
                  )}
                </section>
              )}
            </section>
          )}
        </section>
      )}
    </main>
  )
}

export default App
