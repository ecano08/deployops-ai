import { useEffect, useState, type FormEvent } from 'react'
import {
  approveAiAction,
  askCopilot,
  clearToken,
  createWorkspace,
  fetchAiHealth,
  fetchAiTraces,
  fetchCurrentUser,
  fetchCustomers,
  fetchDeployments,
  fetchEvaluationDatasets,
  fetchEvaluationRuns,
  fetchIncidents,
  fetchIntegrations,
  fetchPendingAiActions,
  fetchWorkspaceMembers,
  fetchWorkspaces,
  getToken,
  login,
  logout,
  register,
  rejectAiAction,
  runEvaluationDataset,
  testIntegration,
  setToken,
} from './api'
import type {
  AiHealthSummary,
  AiProposedAction,
  AiTrace,
  Customer,
  Deployment,
  DeploymentIntegration,
  EvaluationRun,
  Incident,
  User,
  Workspace,
  WorkspaceMember,
} from './types'
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
  const [copilotQuestion, setCopilotQuestion] = useState('')
  const [copilotAnswer, setCopilotAnswer] = useState<string | null>(null)
  const [copilotToolsUsed, setCopilotToolsUsed] = useState<string[]>([])
  const [copilotError, setCopilotError] = useState<string | null>(null)
  const [copilotLoading, setCopilotLoading] = useState(false)
  const [evaluationRuns, setEvaluationRuns] = useState<EvaluationRun[]>([])
  const [evaluationRunsError, setEvaluationRunsError] = useState<string | null>(null)
  const [evaluationRunsLoading, setEvaluationRunsLoading] = useState(false)
  const [evaluationDatasetId, setEvaluationDatasetId] = useState<number | null>(null)
  const [evaluationRunMessage, setEvaluationRunMessage] = useState<string | null>(null)
  const [pendingAiActions, setPendingAiActions] = useState<AiProposedAction[]>([])
  const [pendingAiActionsError, setPendingAiActionsError] = useState<string | null>(null)
  const [pendingAiActionsLoading, setPendingAiActionsLoading] = useState(false)
  const [aiActionMessage, setAiActionMessage] = useState<string | null>(null)
  const [aiHealth, setAiHealth] = useState<AiHealthSummary | null>(null)
  const [aiHealthError, setAiHealthError] = useState<string | null>(null)
  const [aiHealthLoading, setAiHealthLoading] = useState(false)
  const [aiTraces, setAiTraces] = useState<AiTrace[]>([])
  const [aiTracesError, setAiTracesError] = useState<string | null>(null)
  const [aiTracesLoading, setAiTracesLoading] = useState(false)
  const [incidents, setIncidents] = useState<Incident[]>([])
  const [incidentsError, setIncidentsError] = useState<string | null>(null)
  const [incidentsLoading, setIncidentsLoading] = useState(false)
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

  useEffect(() => {
    if (!user || selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    let cancelled = false

    Promise.all([
      fetchEvaluationRuns(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
      fetchEvaluationDatasets(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
      fetchPendingAiActions(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
    ])
      .then(([runsResponse, datasetsResponse, actionsResponse]) => {
        if (!cancelled) {
          setEvaluationRuns(runsResponse.data)
          setEvaluationRunsError(null)
          setEvaluationDatasetId(datasetsResponse.data[0]?.id ?? null)
          setPendingAiActions(actionsResponse.data)
          setPendingAiActionsError(null)
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setEvaluationRuns([])
          setEvaluationRunsError(error.message)
          setPendingAiActions([])
          setPendingAiActionsError(error.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setEvaluationRunsLoading(false)
          setPendingAiActionsLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [user, selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

  useEffect(() => {
    if (!user || selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    let cancelled = false

    Promise.all([
      fetchAiHealth(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
      fetchAiTraces(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
      fetchIncidents(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
    ])
      .then(([healthResponse, tracesResponse, incidentsResponse]) => {
        if (!cancelled) {
          setAiHealth(healthResponse.data)
          setAiHealthError(null)
          setAiTraces(tracesResponse.data)
          setAiTracesError(null)
          setIncidents(incidentsResponse.data)
          setIncidentsError(null)
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setAiHealth(null)
          setAiHealthError(error.message)
          setAiTraces([])
          setAiTracesError(error.message)
          setIncidents([])
          setIncidentsError(error.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setAiHealthLoading(false)
          setAiTracesLoading(false)
          setIncidentsLoading(false)
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
    setCopilotQuestion('')
    setCopilotAnswer(null)
    setCopilotToolsUsed([])
    setCopilotError(null)
    setCopilotLoading(false)
    setEvaluationRuns([])
    setEvaluationRunsError(null)
    setEvaluationRunsLoading(false)
    setEvaluationDatasetId(null)
    setEvaluationRunMessage(null)
    setPendingAiActions([])
    setPendingAiActionsError(null)
    setPendingAiActionsLoading(false)
    setAiActionMessage(null)
    setAiHealth(null)
    setAiHealthError(null)
    setAiHealthLoading(false)
    setAiTraces([])
    setAiTracesError(null)
    setAiTracesLoading(false)
    setIncidents([])
    setIncidentsError(null)
    setIncidentsLoading(false)
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
    setCopilotQuestion('')
    setCopilotAnswer(null)
    setCopilotToolsUsed([])
    setCopilotError(null)
    setCopilotLoading(false)
    setEvaluationRuns([])
    setEvaluationRunsError(null)
    setEvaluationRunsLoading(false)
    setEvaluationDatasetId(null)
    setEvaluationRunMessage(null)
    setPendingAiActions([])
    setPendingAiActionsError(null)
    setPendingAiActionsLoading(false)
    setAiActionMessage(null)
    setAiHealth(null)
    setAiHealthError(null)
    setAiHealthLoading(false)
    setAiTraces([])
    setAiTracesError(null)
    setAiTracesLoading(false)
    setIncidents([])
    setIncidentsError(null)
    setIncidentsLoading(false)
  }

  function selectDeployment(deploymentId: number) {
    setSelectedDeploymentId(deploymentId)
    setIntegrations([])
    setIntegrationsError(null)
    setIntegrationsLoading(true)
    setIntegrationTestMessage(null)
    setCopilotQuestion('')
    setCopilotAnswer(null)
    setCopilotToolsUsed([])
    setCopilotError(null)
    setCopilotLoading(false)
    setEvaluationRuns([])
    setEvaluationRunsError(null)
    setEvaluationRunsLoading(true)
    setEvaluationDatasetId(null)
    setEvaluationRunMessage(null)
    setPendingAiActions([])
    setPendingAiActionsError(null)
    setPendingAiActionsLoading(true)
    setAiActionMessage(null)
    setAiHealth(null)
    setAiHealthError(null)
    setAiHealthLoading(true)
    setAiTraces([])
    setAiTracesError(null)
    setAiTracesLoading(true)
    setIncidents([])
    setIncidentsError(null)
    setIncidentsLoading(true)
  }

  async function handleRunEvaluation() {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null ||
      evaluationDatasetId === null
    ) {
      return
    }

    setEvaluationRunMessage(null)

    try {
      const response = await runEvaluationDataset(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
        evaluationDatasetId,
      )
      setEvaluationRuns((current) => [response.data, ...current])
      setEvaluationRunMessage(
        `Evaluation completed: ${response.data.metrics.passed_cases}/${response.data.metrics.total_cases} passed.`,
      )
    } catch (error) {
      setEvaluationRunMessage(error instanceof Error ? error.message : 'Evaluation run failed.')
    }
  }

  async function refreshPendingAiActions() {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    const response = await fetchPendingAiActions(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
    )
    setPendingAiActions(response.data)
  }

  async function handleApproveAiAction(actionId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setAiActionMessage(null)

    try {
      await approveAiAction(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId, actionId)
      await refreshPendingAiActions()
      setAiActionMessage('Action approved and executed.')
    } catch (error) {
      setAiActionMessage(error instanceof Error ? error.message : 'Could not approve action.')
    }
  }

  async function handleRejectAiAction(actionId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setAiActionMessage(null)

    try {
      await rejectAiAction(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId, actionId)
      await refreshPendingAiActions()
      setAiActionMessage('Action rejected.')
    } catch (error) {
      setAiActionMessage(error instanceof Error ? error.message : 'Could not reject action.')
    }
  }

  async function handleAskCopilot(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null ||
      copilotQuestion.trim() === ''
    ) {
      return
    }

    setCopilotLoading(true)
    setCopilotError(null)
    setCopilotAnswer(null)
    setCopilotToolsUsed([])

    try {
      const response = await askCopilot(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
        copilotQuestion.trim(),
      )
      setCopilotAnswer(response.data.answer)
      setCopilotToolsUsed(response.data.tools_used)
    } catch (error) {
      setCopilotError(error instanceof Error ? error.message : 'Copilot request failed.')
    } finally {
      setCopilotLoading(false)
    }
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
    setCopilotQuestion('')
    setCopilotAnswer(null)
    setCopilotToolsUsed([])
    setCopilotError(null)
    setCopilotLoading(false)
    setEvaluationRuns([])
    setEvaluationRunsError(null)
    setEvaluationRunsLoading(false)
    setEvaluationDatasetId(null)
    setEvaluationRunMessage(null)
    setPendingAiActions([])
    setPendingAiActionsError(null)
    setPendingAiActionsLoading(false)
    setAiActionMessage(null)
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

                      <section>
                        <h3>AI Observability — {selectedDeployment.name}</h3>
                        {(aiHealthLoading || aiTracesLoading || incidentsLoading) && (
                          <p>Loading observability data...</p>
                        )}
                        {aiHealthError && <p>{aiHealthError}</p>}
                        {aiHealth && (
                          <div>
                            <h4>Health metrics (7 days)</h4>
                            <ul>
                              <li>Requests: {aiHealth.request_count}</li>
                              <li>
                                Failure rate: {Math.round(aiHealth.failure_rate * 100)}% (
                                {aiHealth.failure_count} failures)
                              </li>
                              <li>Average latency: {aiHealth.average_latency_ms}ms</li>
                              <li>
                                Tokens: {aiHealth.total_input_tokens} in /{' '}
                                {aiHealth.total_output_tokens} out
                              </li>
                              <li>Estimated cost: ${aiHealth.estimated_cost_usd.toFixed(6)}</li>
                              <li>Tool failures: {aiHealth.tool_failure_count}</li>
                              <li>RAG requests: {aiHealth.rag_request_count}</li>
                            </ul>
                          </div>
                        )}
                        {aiTracesError && <p>{aiTracesError}</p>}
                        {!aiTracesLoading && aiTraces.length > 0 && (
                          <div>
                            <h4>Recent traces</h4>
                            <ul>
                              {aiTraces.slice(0, 5).map((trace) => (
                                <li key={trace.id}>
                                  #{trace.id} — {trace.status} — {trace.latency_ms}ms —{' '}
                                  {trace.model}
                                  {trace.tool_names.length > 0
                                    ? ` — tools: ${trace.tool_names.join(', ')}`
                                    : ''}
                                  {trace.rag_used ? ' — RAG used' : ''}
                                </li>
                              ))}
                            </ul>
                          </div>
                        )}
                        {!aiTracesLoading && aiTraces.length === 0 && !aiTracesError && (
                          <p>No copilot traces yet.</p>
                        )}
                        {incidentsError && <p>{incidentsError}</p>}
                        {!incidentsLoading && incidents.length > 0 && (
                          <div>
                            <h4>Incidents</h4>
                            <ul>
                              {incidents.slice(0, 5).map((incident) => (
                                <li key={incident.id}>
                                  #{incident.id} — {incident.severity} / {incident.status} —{' '}
                                  {incident.title} ({incident.source})
                                </li>
                              ))}
                            </ul>
                          </div>
                        )}
                        {!incidentsLoading && incidents.length === 0 && !incidentsError && (
                          <p>No incidents recorded.</p>
                        )}
                      </section>

                      <section>
                        <h3>AI Copilot — {selectedDeployment.name}</h3>
                        <form onSubmit={handleAskCopilot}>
                          <label>
                            Ask about this deployment
                            <textarea
                              value={copilotQuestion}
                              onChange={(event) => setCopilotQuestion(event.target.value)}
                              rows={3}
                              required
                            />
                          </label>
                          <button type="submit" disabled={copilotLoading}>
                            {copilotLoading ? 'Thinking...' : 'Ask copilot'}
                          </button>
                        </form>
                        {copilotError && <p>{copilotError}</p>}
                        {copilotAnswer && (
                          <div>
                            <h4>Answer</h4>
                            <p>{copilotAnswer}</p>
                          </div>
                        )}
                        {copilotToolsUsed.length > 0 && (
                          <div>
                            <h4>Tools used</h4>
                            <ul>
                              {copilotToolsUsed.map((tool) => (
                                <li key={tool}>{tool}</li>
                              ))}
                            </ul>
                          </div>
                        )}
                      </section>

                      <section>
                        <h3>AI Evaluations — {selectedDeployment.name}</h3>
                        {evaluationRunsLoading && <p>Loading evaluation results...</p>}
                        {evaluationRunsError && <p>{evaluationRunsError}</p>}
                        {evaluationDatasetId !== null && (
                          <p>
                            <button type="button" onClick={handleRunEvaluation}>
                              Run evaluation dataset
                            </button>
                          </p>
                        )}
                        {evaluationRunMessage && <p>{evaluationRunMessage}</p>}
                        {!evaluationRunsLoading && evaluationRuns.length === 0 && !evaluationRunsError && (
                          <p>No evaluation runs yet.</p>
                        )}
                        {!evaluationRunsLoading && evaluationRuns.length > 0 && (
                          <ul>
                            {evaluationRuns.map((run) => (
                              <li key={run.id}>
                                Run #{run.id} — {run.status} — pass rate{' '}
                                {Math.round((run.metrics.pass_rate ?? 0) * 100)}% — avg latency{' '}
                                {run.metrics.average_latency_ms}ms
                                {run.results && run.results.length > 0 && (
                                  <ul>
                                    {run.results.map((result) => (
                                      <li key={result.id}>
                                        Case #{result.evaluation_case_id}:{' '}
                                        {result.passed ? 'passed' : 'failed'} ({result.latency_ms}ms)
                                      </li>
                                    ))}
                                  </ul>
                                )}
                              </li>
                            ))}
                          </ul>
                        )}
                      </section>

                      <section>
                        <h3>Pending AI Actions — {selectedDeployment.name}</h3>
                        {pendingAiActionsLoading && <p>Loading pending actions...</p>}
                        {pendingAiActionsError && <p>{pendingAiActionsError}</p>}
                        {aiActionMessage && <p>{aiActionMessage}</p>}
                        {!pendingAiActionsLoading &&
                          pendingAiActions.length === 0 &&
                          !pendingAiActionsError && <p>No pending actions.</p>}
                        {!pendingAiActionsLoading && pendingAiActions.length > 0 && (
                          <ul>
                            {pendingAiActions.map((action) => (
                              <li key={action.id}>
                                {action.action_type} — {JSON.stringify(action.payload)} — requested by user{' '}
                                {action.requested_by}
                                <button type="button" onClick={() => handleApproveAiAction(action.id)}>
                                  Approve
                                </button>
                                <button type="button" onClick={() => handleRejectAiAction(action.id)}>
                                  Reject
                                </button>
                              </li>
                            ))}
                          </ul>
                        )}
                      </section>
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
