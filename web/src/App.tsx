import { useEffect, useState, type FormEvent } from 'react'
import {
  approveAiAction,
  askCopilot,
  clearToken,
  createWorkspace,
  deleteKnowledgeDocument,
  fetchAiHealth,
  fetchAiTraces,
  fetchCurrentUser,
  fetchCustomers,
  fetchDeployments,
  fetchEvaluationDatasets,
  fetchEvaluationRuns,
  fetchIncidents,
  fetchIntegrations,
  fetchKnowledgeDocuments,
  fetchPendingAiActions,
  fetchWorkspaceMembers,
  fetchWorkspaces,
  getToken,
  logout,
  rejectAiAction,
  runEvaluationDataset,
  testIntegration,
  uploadKnowledgeDocument,
} from './api'
import { AppLayout } from './components/layout/AppLayout'
import type { AppView } from './components/layout/Sidebar'
import { LoadingState } from './components/ui/LoadingState'
import { ApprovalsPage } from './pages/ApprovalsPage'
import { AuthPage } from './pages/AuthPage'
import { CopilotPage } from './pages/CopilotPage'
import { DashboardPage } from './pages/DashboardPage'
import { EvalsPage } from './pages/EvalsPage'
import { IntegrationsPage } from './pages/IntegrationsPage'
import { KnowledgePage } from './pages/KnowledgePage'
import { ObservabilityPage } from './pages/ObservabilityPage'
import type {
  AiHealthSummary,
  AiProposedAction,
  AiTrace,
  Customer,
  Deployment,
  DeploymentIntegration,
  EvaluationRun,
  Incident,
  KnowledgeDocument,
  User,
  Workspace,
  WorkspaceMember,
} from './types'

type HealthResponse = {
  status: string
  ai_service: string
  details: {
    status: string
    service: string
  }
}

function App() {
  const [activeView, setActiveView] = useState<AppView>('dashboard')
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [health, setHealth] = useState<HealthResponse | null>(null)
  const [user, setUser] = useState<User | null>(null)
  const [loadingUser, setLoadingUser] = useState(() => Boolean(getToken()))
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
  const [knowledgeDocuments, setKnowledgeDocuments] = useState<KnowledgeDocument[]>([])
  const [knowledgeError, setKnowledgeError] = useState<string | null>(null)
  const [knowledgeLoading, setKnowledgeLoading] = useState(false)
  const [knowledgeMessage, setKnowledgeMessage] = useState<string | null>(null)
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
  const [appError, setAppError] = useState<string | null>(null)

  const selectedWorkspace = workspaces.find((workspace) => workspace.id === selectedWorkspaceId) ?? null
  const selectedCustomer = customers.find((customer) => customer.id === selectedCustomerId) ?? null
  const selectedDeployment = deployments.find((deployment) => deployment.id === selectedDeploymentId) ?? null

  const handleAuthenticated = () => {
    setLoadingUser(true)

    fetchCurrentUser()
      .then((response) => setUser(response.data))
      .catch(() => {
        clearToken()
        setUser(null)
      })
      .finally(() => setLoadingUser(false))
  }

  useEffect(() => {
    fetch(`${import.meta.env.VITE_API_URL}/api/health/ai`)
      .then((response) => (response.ok ? response.json() : Promise.reject()))
      .then(setHealth)
      .catch(() => setHealth(null))
  }, [])

  useEffect(() => {
    if (!getToken()) {
      return
    }

    let cancelled = false

    fetchCurrentUser()
      .then((response) => {
        if (!cancelled) {
          setUser(response.data)
        }
      })
      .catch(() => {
        if (!cancelled) {
          clearToken()
          setUser(null)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoadingUser(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    if (!user) {
      return
    }

    fetchWorkspaces()
      .then((response) => {
        setWorkspaces(response.data)
        if (response.data.length > 0 && selectedWorkspaceId === null) {
          selectWorkspace(response.data[0].id)
        }
      })
      .catch((error: Error) => setAppError(error.message))
  }, [user]) // eslint-disable-line react-hooks/exhaustive-deps

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
          if (response.data.length > 0 && selectedCustomerId === null) {
            selectCustomer(response.data[0].id)
          }
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
  }, [user, selectedWorkspaceId]) // eslint-disable-line react-hooks/exhaustive-deps

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
          if (response.data.length > 0 && selectedDeploymentId === null) {
            selectDeployment(response.data[0].id)
          }
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
  }, [user, selectedWorkspaceId, selectedCustomerId]) // eslint-disable-line react-hooks/exhaustive-deps

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

    fetchKnowledgeDocuments(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId)
      .then((response) => {
        if (!cancelled) {
          setKnowledgeDocuments(response.data)
          setKnowledgeError(null)
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setKnowledgeDocuments([])
          setKnowledgeError(error.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setKnowledgeLoading(false)
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

  function resetDeploymentState() {
    setIntegrations([])
    setIntegrationsError(null)
    setIntegrationsLoading(false)
    setIntegrationTestMessage(null)
    setKnowledgeDocuments([])
    setKnowledgeError(null)
    setKnowledgeLoading(false)
    setKnowledgeMessage(null)
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

  function selectWorkspace(workspaceId: number) {
    setSelectedWorkspaceId(workspaceId)
    setSelectedCustomerId(null)
    setSelectedDeploymentId(null)
    setMembers([])
    setMembersError(null)
    setMembersLoading(true)
    setCustomers([])
    setCustomersError(null)
    setCustomersLoading(true)
    setDeployments([])
    setDeploymentsError(null)
    setDeploymentsLoading(false)
    resetDeploymentState()
  }

  function selectCustomer(customerId: number) {
    setSelectedCustomerId(customerId)
    setSelectedDeploymentId(null)
    setDeployments([])
    setDeploymentsError(null)
    setDeploymentsLoading(true)
    resetDeploymentState()
  }

  function selectDeployment(deploymentId: number) {
    setSelectedDeploymentId(deploymentId)
    resetDeploymentState()
    setIntegrationsLoading(true)
    setKnowledgeLoading(true)
    setEvaluationRunsLoading(true)
    setPendingAiActionsLoading(true)
    setAiHealthLoading(true)
    setAiTracesLoading(true)
    setIncidentsLoading(true)
  }

  async function handleCreateWorkspace(name: string) {
    setAppError(null)
    const response = await createWorkspace(name)
    setWorkspaces((current) => [response.data, ...current])
    selectWorkspace(response.data.id)
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
    await approveAiAction(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId, actionId)
    await refreshPendingAiActions()
    setAiActionMessage('Action approved and executed.')
  }

  async function handleRejectAiAction(actionId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setAiActionMessage(null)
    await rejectAiAction(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId, actionId)
    await refreshPendingAiActions()
    setAiActionMessage('Action rejected.')
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

  async function handleUploadKnowledge(file: File) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setKnowledgeMessage(null)

    try {
      const response = await uploadKnowledgeDocument(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
        file,
      )
      setKnowledgeDocuments((current) => [response.data, ...current])
      setKnowledgeMessage(`Uploaded ${response.data.original_filename}.`)
    } catch (error) {
      setKnowledgeMessage(error instanceof Error ? error.message : 'Upload failed.')
    }
  }

  async function handleDeleteKnowledge(documentId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    await deleteKnowledgeDocument(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      documentId,
    )
    setKnowledgeDocuments((current) => current.filter((document) => document.id !== documentId))
    setKnowledgeMessage('Document deleted.')
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
    setCustomers([])
    setDeployments([])
    setSelectedDeploymentId(null)
    resetDeploymentState()
  }

  function renderView() {
    switch (activeView) {
      case 'dashboard':
        return (
          <DashboardPage
            workspace={selectedWorkspace}
            customer={selectedCustomer}
            deployment={selectedDeployment}
            members={members}
            membersLoading={membersLoading}
            membersError={membersError}
            deployments={deployments}
            aiHealth={aiHealth}
            aiHealthLoading={aiHealthLoading}
            pendingActions={pendingAiActions}
            incidents={incidents}
            incidentsLoading={incidentsLoading}
          />
        )
      case 'integrations':
        return (
          <IntegrationsPage
            deployment={selectedDeployment}
            integrations={integrations}
            loading={integrationsLoading}
            error={integrationsError}
            testMessage={integrationTestMessage}
            onTest={handleTestIntegration}
          />
        )
      case 'copilot':
        return (
          <CopilotPage
            deployment={selectedDeployment}
            question={copilotQuestion}
            answer={copilotAnswer}
            toolsUsed={copilotToolsUsed}
            error={copilotError}
            loading={copilotLoading}
            onQuestionChange={setCopilotQuestion}
            onSubmit={handleAskCopilot}
          />
        )
      case 'knowledge':
        return (
          <KnowledgePage
            deployment={selectedDeployment}
            documents={knowledgeDocuments}
            loading={knowledgeLoading}
            error={knowledgeError}
            uploadMessage={knowledgeMessage}
            onUpload={handleUploadKnowledge}
            onDelete={handleDeleteKnowledge}
          />
        )
      case 'evals':
        return (
          <EvalsPage
            deployment={selectedDeployment}
            runs={evaluationRuns}
            loading={evaluationRunsLoading}
            error={evaluationRunsError}
            canRun={evaluationDatasetId !== null}
            runMessage={evaluationRunMessage}
            onRun={handleRunEvaluation}
          />
        )
      case 'approvals':
        return (
          <ApprovalsPage
            deployment={selectedDeployment}
            actions={pendingAiActions}
            loading={pendingAiActionsLoading}
            error={pendingAiActionsError}
            message={aiActionMessage}
            onApprove={handleApproveAiAction}
            onReject={handleRejectAiAction}
          />
        )
      case 'observability':
        return (
          <ObservabilityPage
            deployment={selectedDeployment}
            aiHealth={aiHealth}
            aiHealthLoading={aiHealthLoading}
            aiHealthError={aiHealthError}
            traces={aiTraces}
            tracesLoading={aiTracesLoading}
            tracesError={aiTracesError}
            incidents={incidents}
            incidentsLoading={incidentsLoading}
            incidentsError={incidentsError}
          />
        )
      default:
        return null
    }
  }

  if (loadingUser) {
    return (
      <div className="auth-page">
        <LoadingState label="Loading session…" />
      </div>
    )
  }

  if (!user) {
    return <AuthPage onAuthenticated={handleAuthenticated} />
  }

  return (
    <AppLayout
      activeView={activeView}
      onNavigate={setActiveView}
      sidebarOpen={sidebarOpen}
      onSidebarToggle={() => setSidebarOpen((open) => !open)}
      onSidebarClose={() => setSidebarOpen(false)}
      userName={user.name}
      userEmail={user.email}
      apiStatus={health?.status ?? null}
      aiStatus={health?.ai_service ?? null}
      onLogout={handleLogout}
      workspaces={workspaces}
      customers={customers}
      deployments={deployments}
      selectedWorkspaceId={selectedWorkspaceId}
      selectedCustomerId={selectedCustomerId}
      selectedDeploymentId={selectedDeploymentId}
      onWorkspaceChange={selectWorkspace}
      onCustomerChange={selectCustomer}
      onDeploymentChange={selectDeployment}
      onCreateWorkspace={handleCreateWorkspace}
      pendingApprovals={pendingAiActions.length}
    >
      {appError && <p role="alert">{appError}</p>}
      {(customersLoading || deploymentsLoading) && activeView === 'dashboard' && (
        <LoadingState label="Loading context…" />
      )}
      {customersError && <p role="alert">{customersError}</p>}
      {deploymentsError && <p role="alert">{deploymentsError}</p>}
      {renderView()}
    </AppLayout>
  )
}

export default App
