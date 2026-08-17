import { useCallback, useEffect, useState, type FormEvent } from 'react'
import {
  approveAiAction,
  askCopilot,
  clearToken,
  createCustomer,
  createDeployment,
  createEvaluationCase,
  createEvaluationDataset,
  createIntegration,
  createWorkspace,
  deleteCustomer,
  deleteDeployment,
  deleteEvaluationCase,
  deleteEvaluationDataset,
  deleteIntegration,
  activateKnowledgeDocument,
  archiveKnowledgeDocument,
  deleteKnowledgeDocument,
  deleteWorkspaceMember,
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
  fetchKnowledgeDocumentMatchCandidates,
  fetchPendingAiActions,
  fetchWorkspaceInvitations,
  fetchWorkspaceMembers,
  fetchWorkspaces,
  getToken,
  inviteWorkspaceMember,
  logout,
  rejectAiAction,
  runEvaluationDataset,
  testIntegration,
  updateCustomer,
  updateDeployment,
  updateDeploymentStage,
  updateEvaluationCase,
  updateEvaluationDataset,
  updateIntegration,
  updateWorkspaceMember,
  uploadKnowledgeDocument,
} from './api'
import { AppLayout } from './components/layout/AppLayout'
import type { AppView } from './components/layout/Sidebar'
import { EmptyState } from './components/ui/EmptyState'
import { LoadingState } from './components/ui/LoadingState'
import { canManageCustomers, canManageDeployments } from './lib/permissions'
import { apiErrorReference } from './lib/apiError'
import { AcceptInvitationPage } from './pages/AcceptInvitationPage'
import { ApprovalsPage } from './pages/ApprovalsPage'
import { AuthPage } from './pages/AuthPage'
import { CopilotPage } from './pages/CopilotPage'
import { DashboardPage } from './pages/DashboardPage'
import { EvalsPage } from './pages/EvalsPage'
import { IntegrationsPage } from './pages/IntegrationsPage'
import { KnowledgePage } from './pages/KnowledgePage'
import { ObservabilityPage } from './pages/ObservabilityPage'
import { TeamPage } from './pages/TeamPage'
import {
  isWorkspaceInvitation,
  type AiHealthSummary,
  type AiProposedAction,
  type AiTrace,
  type CopilotTurn,
  type Customer,
  type Deployment,
  type DeploymentIntegration,
  type DeploymentStage,
  type EvaluationDataset,
  type EvaluationRun,
  type Incident,
  type InviteWorkspaceMemberResult,
  type KnowledgeDocumentLibraryStats,
  type User,
  type Workspace,
  type WorkspaceInvitation,
  type WorkspaceMember,
  type WorkspaceRole,
} from './types'

type HealthResponse = {
  status: string
  ai_service: string
  details: {
    status: string
    service: string
  }
}

function invitationTokenFromPath(): string | null {
  const match = window.location.pathname.match(/^\/invitations\/([^/]+)$/)

  return match ? decodeURIComponent(match[1]) : null
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
  const [invitations, setInvitations] = useState<WorkspaceInvitation[]>([])
  const [invitationsError, setInvitationsError] = useState<string | null>(null)
  const [teamMessage, setTeamMessage] = useState<string | null>(null)
  const [invitationToken, setInvitationToken] = useState(() => invitationTokenFromPath())
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
  const [knowledgeStats, setKnowledgeStats] = useState<KnowledgeDocumentLibraryStats | null>(null)
  const [knowledgeStatsLoading, setKnowledgeStatsLoading] = useState(false)
  const [knowledgeMessage, setKnowledgeMessage] = useState<string | null>(null)
  const [copilotQuestion, setCopilotQuestion] = useState('')
  const [copilotTurns, setCopilotTurns] = useState<CopilotTurn[]>([])
  const [copilotError, setCopilotError] = useState<string | null>(null)
  const [copilotErrorReference, setCopilotErrorReference] = useState<string | null>(null)
  const [copilotLoading, setCopilotLoading] = useState(false)
  const [evaluationDatasets, setEvaluationDatasets] = useState<EvaluationDataset[]>([])
  const [evaluationDatasetsError, setEvaluationDatasetsError] = useState<string | null>(null)
  const [evaluationDatasetsLoading, setEvaluationDatasetsLoading] = useState(false)
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

  const refreshEvaluationData = useCallback(async () => {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    setEvaluationDatasetsLoading(true)
    setEvaluationRunsLoading(true)

    try {
      const [runsResponse, datasetsResponse] = await Promise.all([
        fetchEvaluationRuns(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
        fetchEvaluationDatasets(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
      ])

      setEvaluationRuns(runsResponse.data)
      setEvaluationRunsError(null)
      setEvaluationDatasets(datasetsResponse.data)
      setEvaluationDatasetsError(null)
      setEvaluationDatasetId((current) => {
        if (current !== null && datasetsResponse.data.some((dataset) => dataset.id === current)) {
          return current
        }

        return datasetsResponse.data[0]?.id ?? null
      })
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Failed to load evaluation data.'
      setEvaluationRuns([])
      setEvaluationRunsError(message)
      setEvaluationDatasets([])
      setEvaluationDatasetsError(message)
      setEvaluationDatasetId(null)
    } finally {
      setEvaluationDatasetsLoading(false)
      setEvaluationRunsLoading(false)
    }
  }, [selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

  const refreshObservabilityData = useCallback(async () => {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    try {
      const [healthResponse, tracesResponse, incidentsResponse] = await Promise.all([
        fetchAiHealth(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
        fetchAiTraces(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
        fetchIncidents(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
      ])
      setAiHealth(healthResponse.data)
      setAiHealthError(null)
      setAiTraces(tracesResponse.data)
      setAiTracesError(null)
      setIncidents(incidentsResponse.data)
      setIncidentsError(null)
    } catch (error) {
      setAiHealth(null)
      setAiHealthError(error instanceof Error ? error.message : 'Failed to load observability data.')
      setAiTraces([])
      setAiTracesError(error instanceof Error ? error.message : 'Failed to load observability data.')
      setIncidents([])
      setIncidentsError(error instanceof Error ? error.message : 'Failed to load observability data.')
    }
  }, [selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

  const refreshDeployments = useCallback(async () => {
    if (selectedWorkspaceId === null || selectedCustomerId === null) {
      return
    }

    try {
      const response = await fetchDeployments(selectedWorkspaceId, selectedCustomerId)
      setDeployments(response.data)
      setDeploymentsError(null)
    } catch (error) {
      setDeploymentsError(error instanceof Error ? error.message : 'Failed to load deployments.')
    }
  }, [selectedWorkspaceId, selectedCustomerId])

  const refreshPendingAiActions = useCallback(async () => {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    setPendingAiActionsLoading(true)

    try {
      const response = await fetchPendingAiActions(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
      )
      setPendingAiActions(response.data)
      setPendingAiActionsError(null)
    } catch (error) {
      setPendingAiActions([])
      setPendingAiActionsError(
        error instanceof Error ? error.message : 'Failed to load pending actions.',
      )
    } finally {
      setPendingAiActionsLoading(false)
    }
  }, [selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

  const refreshTeamData = useCallback(async (isCancelled: () => boolean = () => false) => {
    if (selectedWorkspaceId === null) {
      return
    }

    const workspaceId = selectedWorkspaceId

    await Promise.all([
      fetchWorkspaceMembers(workspaceId)
        .then((response) => {
          if (isCancelled()) {
            return
          }

          setMembers(response.data)
          setMembersError(null)
        })
        .catch((error: unknown) => {
          if (isCancelled()) {
            return
          }

          setMembers([])
          setMembersError(error instanceof Error ? error.message : 'Failed to load members.')
        }),
      fetchWorkspaceInvitations(workspaceId)
        .then((response) => {
          if (isCancelled()) {
            return
          }

          setInvitations(response.data)
          setInvitationsError(null)
        })
        .catch((error: unknown) => {
          if (isCancelled()) {
            return
          }

          setInvitations([])
          setInvitationsError(
            error instanceof Error ? error.message : 'Failed to load invitations.',
          )
        }),
    ])

    if (!isCancelled()) {
      setMembersLoading(false)
    }
  }, [selectedWorkspaceId])

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
    if (invitationTokenFromPath() || !getToken()) {
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

    void Promise.resolve().then(() => refreshTeamData(() => cancelled))

    return () => {
      cancelled = true
    }
  }, [user, selectedWorkspaceId, refreshTeamData])

  useEffect(() => {
    if (activeView !== 'team' || !user || selectedWorkspaceId === null) {
      return
    }

    let cancelled = false

    void Promise.resolve().then(() => refreshTeamData(() => cancelled))

    const intervalId = window.setInterval(() => {
      void refreshTeamData(() => cancelled)
    }, 4000)

    return () => {
      cancelled = true
      window.clearInterval(intervalId)
    }
  }, [activeView, user, selectedWorkspaceId, refreshTeamData])

  useEffect(() => {
    if (!user || selectedWorkspaceId === null) {
      return
    }

    const handleVisible = () => {
      if (document.visibilityState === 'visible') {
        void refreshTeamData()
      }
    }

    document.addEventListener('visibilitychange', handleVisible)
    window.addEventListener('focus', handleVisible)

    return () => {
      document.removeEventListener('visibilitychange', handleVisible)
      window.removeEventListener('focus', handleVisible)
    }
  }, [user, selectedWorkspaceId, refreshTeamData])

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

    fetchKnowledgeDocuments(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId, {
      per_page: 1,
    })
      .then((response) => {
        if (!cancelled) {
          setKnowledgeStats(response.stats)
        }
      })
      .catch(() => {
        if (!cancelled) {
          setKnowledgeStats(null)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setKnowledgeStatsLoading(false)
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
    ])
      .then(([runsResponse, datasetsResponse]) => {
        if (!cancelled) {
          setEvaluationRuns(runsResponse.data)
          setEvaluationRunsError(null)
          setEvaluationDatasets(datasetsResponse.data)
          setEvaluationDatasetsError(null)
          setEvaluationDatasetId((current) => {
            if (current !== null && datasetsResponse.data.some((dataset) => dataset.id === current)) {
              return current
            }

            return datasetsResponse.data[0]?.id ?? null
          })
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          const message = error.message
          setEvaluationRuns([])
          setEvaluationRunsError(message)
          setEvaluationDatasets([])
          setEvaluationDatasetsError(message)
          setEvaluationDatasetId(null)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setEvaluationDatasetsLoading(false)
          setEvaluationRunsLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [user, selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

  useEffect(() => {
    if (
      activeView !== 'evals' ||
      !user ||
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    let cancelled = false

    Promise.all([
      fetchEvaluationRuns(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
      fetchEvaluationDatasets(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId),
    ])
      .then(([runsResponse, datasetsResponse]) => {
        if (!cancelled) {
          setEvaluationRuns(runsResponse.data)
          setEvaluationRunsError(null)
          setEvaluationDatasets(datasetsResponse.data)
          setEvaluationDatasetsError(null)
          setEvaluationDatasetId((current) => {
            if (current !== null && datasetsResponse.data.some((dataset) => dataset.id === current)) {
              return current
            }

            return datasetsResponse.data[0]?.id ?? null
          })
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          const message = error.message
          setEvaluationRuns([])
          setEvaluationRunsError(message)
          setEvaluationDatasets([])
          setEvaluationDatasetsError(message)
        }
      })

    return () => {
      cancelled = true
    }
  }, [activeView, user, selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

  useEffect(() => {
    if (!user || selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    let cancelled = false

    fetchPendingAiActions(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId)
      .then((actionsResponse) => {
        if (!cancelled) {
          setPendingAiActions(actionsResponse.data)
          setPendingAiActionsError(null)
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setPendingAiActions([])
          setPendingAiActionsError(error.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setPendingAiActionsLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [user, selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

  useEffect(() => {
    if (
      activeView !== 'approvals' ||
      !user ||
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    let cancelled = false

    fetchPendingAiActions(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId)
      .then((actionsResponse) => {
        if (!cancelled) {
          setPendingAiActions(actionsResponse.data)
          setPendingAiActionsError(null)
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setPendingAiActions([])
          setPendingAiActionsError(error.message)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setPendingAiActionsLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [activeView, user, selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

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

  useEffect(() => {
    if (
      activeView !== 'observability' ||
      !user ||
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
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

    return () => {
      cancelled = true
    }
  }, [activeView, user, selectedWorkspaceId, selectedCustomerId, selectedDeploymentId])

  function resetDeploymentState() {
    setIntegrations([])
    setIntegrationsError(null)
    setIntegrationsLoading(false)
    setIntegrationTestMessage(null)
    setKnowledgeStats(null)
    setKnowledgeStatsLoading(false)
    setKnowledgeMessage(null)
    setCopilotQuestion('')
    setCopilotTurns([])
    setCopilotError(null)
    setCopilotErrorReference(null)
    setCopilotLoading(false)
    setEvaluationDatasets([])
    setEvaluationDatasetsError(null)
    setEvaluationDatasetsLoading(false)
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
    setInvitations([])
    setInvitationsError(null)
    setTeamMessage(null)
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
    setKnowledgeStatsLoading(true)
    setEvaluationDatasetsLoading(true)
    setEvaluationRunsLoading(true)
    setPendingAiActionsLoading(true)
    setAiHealthLoading(true)
    setAiTracesLoading(true)
    setIncidentsLoading(true)
  }

  async function handleInviteWorkspaceMember(payload: {
    email: string
    role: Exclude<WorkspaceRole, 'owner'>
  }): Promise<InviteWorkspaceMemberResult> {
    if (selectedWorkspaceId === null) {
      throw new Error('No workspace selected.')
    }

    setTeamMessage(null)
    const response = await inviteWorkspaceMember(selectedWorkspaceId, payload)
    const result = response.data

    if (isWorkspaceInvitation(result)) {
      setInvitations((current) =>
        [...current.filter((invitation) => invitation.email !== result.email), result].sort((left, right) =>
          left.email.localeCompare(right.email),
        ),
      )
      setTeamMessage(`Invitation created for ${result.email}.`)

      return { type: 'invitation', invitation: result }
    }

    setMembers((current) =>
      [...current, result].sort((left, right) => left.name.localeCompare(right.name)),
    )
    setInvitations((current) => current.filter((invitation) => invitation.email !== result.email))
    setTeamMessage(`Added ${result.name} as ${result.role}.`)

    return { type: 'member', member: result }
  }

  async function handleUpdateWorkspaceMemberRole(
    memberId: number,
    role: Exclude<WorkspaceRole, 'owner'>,
  ) {
    if (selectedWorkspaceId === null) {
      return
    }

    setTeamMessage(null)
    const response = await updateWorkspaceMember(selectedWorkspaceId, memberId, { role })
    setMembers((current) =>
      current
        .map((member) => (member.id === memberId ? response.data : member))
        .sort((left, right) => left.name.localeCompare(right.name)),
    )
    setTeamMessage(`Updated ${response.data.name}'s role to ${response.data.role}.`)
  }

  async function handleRemoveWorkspaceMember(memberId: number) {
    if (selectedWorkspaceId === null) {
      return
    }

    setTeamMessage(null)
    await deleteWorkspaceMember(selectedWorkspaceId, memberId)
    setMembers((current) => current.filter((member) => member.id !== memberId))
    setTeamMessage('Member removed from workspace.')
  }

  async function handleCreateWorkspace(name: string) {
    setAppError(null)
    const response = await createWorkspace(name)
    setWorkspaces((current) => [response.data, ...current])
    selectWorkspace(response.data.id)
  }

  async function handleCreateCustomer(payload: { name: string; description: string | null }) {
    if (selectedWorkspaceId === null) {
      return
    }

    setAppError(null)
    const response = await createCustomer(selectedWorkspaceId, payload)
    setCustomers((current) =>
      [...current, response.data].sort((left, right) => left.name.localeCompare(right.name)),
    )
    selectCustomer(response.data.id)
  }

  async function handleUpdateCustomer(
    customerId: number,
    payload: { name: string; description: string | null },
  ) {
    if (selectedWorkspaceId === null) {
      return
    }

    setAppError(null)
    const response = await updateCustomer(selectedWorkspaceId, customerId, payload)
    setCustomers((current) =>
      current
        .map((customer) => (customer.id === customerId ? response.data : customer))
        .sort((left, right) => left.name.localeCompare(right.name)),
    )
  }

  async function handleDeleteCustomer(customerId: number) {
    if (selectedWorkspaceId === null) {
      return
    }

    setAppError(null)
    await deleteCustomer(selectedWorkspaceId, customerId)

    const remaining = customers.filter((customer) => customer.id !== customerId)
    setCustomers(remaining)

    if (selectedCustomerId === customerId) {
      if (remaining.length > 0) {
        selectCustomer(remaining[0].id)
      } else {
        setSelectedCustomerId(null)
        setDeployments([])
        setDeploymentsError(null)
        setDeploymentsLoading(false)
        setSelectedDeploymentId(null)
        resetDeploymentState()
      }
    }
  }

  async function handleCreateDeployment(payload: {
    name: string
    description: string | null
    stage: DeploymentStage
  }) {
    if (selectedWorkspaceId === null || selectedCustomerId === null) {
      return
    }

    setAppError(null)
    const response = await createDeployment(selectedWorkspaceId, selectedCustomerId, payload)
    setDeployments((current) =>
      [...current, response.data].sort((left, right) => left.name.localeCompare(right.name)),
    )
    selectDeployment(response.data.id)
  }

  async function handleUpdateDeployment(
    deploymentId: number,
    payload: { name: string; description: string | null; stage: DeploymentStage },
  ) {
    if (selectedWorkspaceId === null || selectedCustomerId === null) {
      return
    }

    setAppError(null)

    const response = await updateDeployment(selectedWorkspaceId, selectedCustomerId, deploymentId, {
      name: payload.name,
      description: payload.description,
    })

    let updatedDeployment = response.data

    if (payload.stage !== response.data.stage) {
      const stageResponse = await updateDeploymentStage(
        selectedWorkspaceId,
        selectedCustomerId,
        deploymentId,
        payload.stage,
      )
      updatedDeployment = stageResponse.data
    }

    setDeployments((current) =>
      current
        .map((deployment) => (deployment.id === deploymentId ? updatedDeployment : deployment))
        .sort((left, right) => left.name.localeCompare(right.name)),
    )
  }

  async function handleUpdateDeploymentStage(deploymentId: number, stage: DeploymentStage) {
    if (selectedWorkspaceId === null || selectedCustomerId === null) {
      return
    }

    setAppError(null)
    const response = await updateDeploymentStage(
      selectedWorkspaceId,
      selectedCustomerId,
      deploymentId,
      stage,
    )
    setDeployments((current) =>
      current.map((deployment) => (deployment.id === deploymentId ? response.data : deployment)),
    )
  }

  async function handleDeleteDeployment(deploymentId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null) {
      return
    }

    setAppError(null)
    await deleteDeployment(selectedWorkspaceId, selectedCustomerId, deploymentId)

    const remaining = deployments.filter((deployment) => deployment.id !== deploymentId)
    setDeployments(remaining)

    if (selectedDeploymentId === deploymentId) {
      if (remaining.length > 0) {
        selectDeployment(remaining[0].id)
      } else {
        setSelectedDeploymentId(null)
        resetDeploymentState()
      }
    }
  }

  async function handleCreateIntegration(payload: {
    type: 'rest_api' | 'webhook'
    name: string
    base_url?: string | null
    endpoint?: string | null
    api_key?: string
    webhook_secret?: string
  }) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setIntegrationsError(null)
    const response = await createIntegration(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      payload,
    )
    setIntegrations((current) =>
      [...current, response.data].sort((left, right) => left.name.localeCompare(right.name)),
    )
  }

  async function handleUpdateIntegration(
    integrationId: number,
    payload: {
      name: string
      base_url?: string | null
      endpoint?: string | null
      api_key?: string
      webhook_secret?: string
    },
  ) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setIntegrationsError(null)
    const response = await updateIntegration(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      integrationId,
      payload,
    )
    setIntegrations((current) =>
      current.map((integration) => (integration.id === integrationId ? response.data : integration)),
    )
  }

  async function handleDeleteIntegration(integrationId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setIntegrationsError(null)
    await deleteIntegration(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      integrationId,
    )
    setIntegrations((current) => current.filter((integration) => integration.id !== integrationId))
    setIntegrationTestMessage(null)
  }

  async function handleRunEvaluation(datasetId: number) {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    setEvaluationRunMessage(null)

    try {
      const response = await runEvaluationDataset(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
        datasetId,
      )
      setEvaluationRuns((current) => [response.data, ...current])
      setEvaluationRunMessage(
        `Evaluation completed: ${response.data.metrics.passed_cases}/${response.data.metrics.total_cases} passed.`,
      )
      await refreshEvaluationData()
    } catch (error) {
      setEvaluationRunMessage(error instanceof Error ? error.message : 'Evaluation run failed.')
    }
  }

  async function handleCreateEvaluationDataset(payload: {
    name: string
    description: string | null
  }) {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    const response = await createEvaluationDataset(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      payload,
    )

    setEvaluationDatasets((current) =>
      [...current, response.data].sort((left, right) => left.name.localeCompare(right.name)),
    )
    setEvaluationDatasetId(response.data.id)
    setEvaluationRunMessage(`Created dataset "${response.data.name}".`)
  }

  async function handleUpdateEvaluationDataset(
    datasetId: number,
    payload: { name: string; description: string | null },
  ) {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    const response = await updateEvaluationDataset(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      datasetId,
      payload,
    )

    setEvaluationDatasets((current) =>
      current
        .map((dataset) => (dataset.id === datasetId ? response.data : dataset))
        .sort((left, right) => left.name.localeCompare(right.name)),
    )
    setEvaluationRunMessage(`Updated dataset "${response.data.name}".`)
  }

  async function handleDeleteEvaluationDataset(datasetId: number) {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    await deleteEvaluationDataset(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      datasetId,
    )

    setEvaluationDatasets((current) => {
      const next = current.filter((dataset) => dataset.id !== datasetId)
      setEvaluationDatasetId((selectedId) => {
        if (selectedId === datasetId) {
          return next[0]?.id ?? null
        }

        return selectedId
      })

      return next
    })
    setEvaluationRunMessage('Evaluation dataset deleted.')
  }

  async function handleCreateEvaluationCase(
    datasetId: number,
    payload: {
      input: string
      expected_behavior: string
      expected_tools: string[] | null
      expected_sources: string[] | null
    },
  ) {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    const response = await createEvaluationCase(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      datasetId,
      payload,
    )

    setEvaluationDatasets((current) =>
      current.map((dataset) =>
        dataset.id === datasetId
          ? { ...dataset, cases: [...(dataset.cases ?? []), response.data] }
          : dataset,
      ),
    )
    setEvaluationRunMessage('Evaluation case added.')
  }

  async function handleUpdateEvaluationCase(
    datasetId: number,
    caseId: number,
    payload: {
      input: string
      expected_behavior: string
      expected_tools: string[] | null
      expected_sources: string[] | null
    },
  ) {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    const response = await updateEvaluationCase(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      datasetId,
      caseId,
      payload,
    )

    setEvaluationDatasets((current) =>
      current.map((dataset) =>
        dataset.id === datasetId
          ? {
              ...dataset,
              cases: (dataset.cases ?? []).map((evaluationCase) =>
                evaluationCase.id === caseId ? response.data : evaluationCase,
              ),
            }
          : dataset,
      ),
    )
    setEvaluationRunMessage('Evaluation case updated.')
  }

  async function handleDeleteEvaluationCase(datasetId: number, caseId: number) {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    await deleteEvaluationCase(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      datasetId,
      caseId,
    )

    setEvaluationDatasets((current) =>
      current.map((dataset) =>
        dataset.id === datasetId
          ? {
              ...dataset,
              cases: (dataset.cases ?? []).filter((evaluationCase) => evaluationCase.id !== caseId),
            }
          : dataset,
      ),
    )
    setEvaluationRunMessage('Evaluation case deleted.')
  }

  async function handleApproveAiAction(actionId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setAiActionMessage(null)
    await approveAiAction(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId, actionId)
    await Promise.all([refreshPendingAiActions(), refreshDeployments()])
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

  function resetCopilotConversation() {
    setCopilotQuestion('')
    setCopilotTurns([])
    setCopilotError(null)
    setCopilotErrorReference(null)
    setCopilotLoading(false)
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

    const question = copilotQuestion.trim()
    const history = copilotTurns.map(({ question: priorQuestion, answer }) => ({
      question: priorQuestion,
      answer,
    }))

    setCopilotLoading(true)
    setCopilotError(null)
    setCopilotErrorReference(null)

    try {
      const response = await askCopilot(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
        question,
        history,
      )
      setCopilotTurns((currentTurns) => [
        ...currentTurns,
        {
          id: `${Date.now()}-${currentTurns.length}`,
          question,
          answer: response.data.answer,
          toolsUsed: response.data.tools_used,
        },
      ])
      setCopilotQuestion('')
      await refreshPendingAiActions()
    } catch (error) {
      setCopilotError(error instanceof Error ? error.message : 'Copilot request failed.')
      setCopilotErrorReference(apiErrorReference(error))
    } finally {
      setCopilotLoading(false)
      await refreshObservabilityData()
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

  async function handleDetectKnowledgeMatchCandidates(filename: string, title: string) {
    if (
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return []
    }

    const response = await fetchKnowledgeDocumentMatchCandidates(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      { filename, title },
    )

    return response.data
  }

  async function handleUploadKnowledge(payload: {
    file: File
    title: string
    document_type: string
    version_label: string | null
    effective_at: string | null
    supersedes_document_id: number | null
  }) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    setKnowledgeMessage(null)

    try {
      const response = await uploadKnowledgeDocument(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
        payload,
      )
      setKnowledgeMessage(`Uploaded ${response.data.title}.`)
      const statsResponse = await fetchKnowledgeDocuments(
        selectedWorkspaceId,
        selectedCustomerId,
        selectedDeploymentId,
        { per_page: 1 },
      )
      setKnowledgeStats(statsResponse.stats)
    } catch (error) {
      setKnowledgeMessage(error instanceof Error ? error.message : 'Upload failed.')
      throw error
    }
  }

  async function handleActivateKnowledge(documentId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    const response = await activateKnowledgeDocument(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      documentId,
    )

    const statsResponse = await fetchKnowledgeDocuments(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      { per_page: 1 },
    )
    setKnowledgeStats(statsResponse.stats)
    setKnowledgeMessage(`Activated ${response.data.title}.`)
  }

  async function handleArchiveKnowledge(documentId: number) {
    if (selectedWorkspaceId === null || selectedCustomerId === null || selectedDeploymentId === null) {
      return
    }

    const response = await archiveKnowledgeDocument(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      documentId,
    )
    const statsResponse = await fetchKnowledgeDocuments(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      { per_page: 1 },
    )
    setKnowledgeStats(statsResponse.stats)
    setKnowledgeMessage(`Archived ${response.data.title}.`)
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
    const statsResponse = await fetchKnowledgeDocuments(
      selectedWorkspaceId,
      selectedCustomerId,
      selectedDeploymentId,
      { per_page: 1 },
    )
    setKnowledgeStats(statsResponse.stats)
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
    setInvitations([])
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
            integrations={integrations}
            integrationsLoading={integrationsLoading}
            knowledgeStats={knowledgeStats}
            knowledgeStatsLoading={knowledgeStatsLoading}
            aiHealth={aiHealth}
            aiHealthLoading={aiHealthLoading}
            pendingActions={pendingAiActions}
            incidents={incidents}
            incidentsLoading={incidentsLoading}
          />
        )
      case 'team':
        return (
          <TeamPage
            workspace={selectedWorkspace}
            members={members}
            invitations={invitations}
            loading={membersLoading}
            error={membersError}
            invitationsError={invitationsError}
            message={teamMessage}
            onInviteMember={handleInviteWorkspaceMember}
            onUpdateMemberRole={handleUpdateWorkspaceMemberRole}
            onRemoveMember={handleRemoveWorkspaceMember}
          />
        )
      case 'integrations':
        return (
          <IntegrationsPage
            workspace={selectedWorkspace}
            deployment={selectedDeployment}
            integrations={integrations}
            loading={integrationsLoading}
            error={integrationsError}
            testMessage={integrationTestMessage}
            onCreate={handleCreateIntegration}
            onUpdate={handleUpdateIntegration}
            onDelete={handleDeleteIntegration}
            onTest={handleTestIntegration}
          />
        )
      case 'copilot':
        return (
          <CopilotPage
            deployment={selectedDeployment}
            question={copilotQuestion}
            turns={copilotTurns}
            error={copilotError}
            errorReference={copilotErrorReference}
            loading={copilotLoading}
            onQuestionChange={setCopilotQuestion}
            onSubmit={handleAskCopilot}
            onNewConversation={resetCopilotConversation}
          />
        )
      case 'knowledge':
        return (
          <KnowledgePage
            workspace={selectedWorkspace}
            customer={selectedCustomer}
            deployment={selectedDeployment}
            uploadMessage={knowledgeMessage}
            onDetectMatchCandidates={handleDetectKnowledgeMatchCandidates}
            onUpload={handleUploadKnowledge}
            onActivate={handleActivateKnowledge}
            onArchive={handleArchiveKnowledge}
            onDelete={handleDeleteKnowledge}
          />
        )
      case 'evals':
        return (
          <EvalsPage
            workspace={selectedWorkspace}
            deployment={selectedDeployment}
            datasets={evaluationDatasets}
            datasetsLoading={evaluationDatasetsLoading}
            datasetsError={evaluationDatasetsError}
            selectedDatasetId={evaluationDatasetId}
            onSelectDataset={setEvaluationDatasetId}
            runs={evaluationRuns}
            runsLoading={evaluationRunsLoading}
            runsError={evaluationRunsError}
            runMessage={evaluationRunMessage}
            onCreateDataset={handleCreateEvaluationDataset}
            onUpdateDataset={handleUpdateEvaluationDataset}
            onDeleteDataset={handleDeleteEvaluationDataset}
            onCreateCase={handleCreateEvaluationCase}
            onUpdateCase={handleUpdateEvaluationCase}
            onDeleteCase={handleDeleteEvaluationCase}
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
            currentUserId={user?.id ?? null}
            workspaceRole={selectedWorkspace?.current_user_role}
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

  function renderContextEmptyState() {
    const workspaceLevelViews: AppView[] = ['dashboard', 'team']

    if (customersLoading || deploymentsLoading) {
      return <LoadingState label="Loading context…" />
    }

    if (customersError) {
      return <p role="alert">{customersError}</p>
    }

    if (
      selectedWorkspace &&
      customers.length === 0 &&
      !workspaceLevelViews.includes(activeView)
    ) {
      return (
        <EmptyState
          title="No customers yet"
          description="Create a customer to start managing deployments and integrations."
          action={
            canManageCustomers(selectedWorkspace.current_user_role) ? (
              <p className="state__hint">Use the Create button in the customer context bar above.</p>
            ) : (
              <p className="state__hint">Ask a workspace admin to create a customer.</p>
            )
          }
        />
      )
    }

    if (deploymentsError) {
      return <p role="alert">{deploymentsError}</p>
    }

    if (
      selectedCustomer &&
      deployments.length === 0 &&
      !workspaceLevelViews.includes(activeView)
    ) {
      return (
        <EmptyState
          title="No deployments yet"
          description="Create a deployment to connect integrations, knowledge, and copilot workflows."
          action={
            canManageDeployments(selectedWorkspace?.current_user_role) ? (
              <p className="state__hint">Use the Create button in the deployment context bar above.</p>
            ) : (
              <p className="state__hint">Ask an engineer or admin to create a deployment.</p>
            )
          }
        />
      )
    }

    return null
  }

  const contextEmptyState = renderContextEmptyState()

  if (invitationToken) {
    return (
      <AcceptInvitationPage
        token={invitationToken}
        onAccepted={() => {
          setInvitationToken(null)
          setSelectedWorkspaceId(null)
          setMembers([])
          setInvitations([])
          setMembersError(null)
          setInvitationsError(null)
          setTeamMessage(null)
          setActiveView('team')
          handleAuthenticated()
        }}
      />
    )
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
      onCreateCustomer={handleCreateCustomer}
      onUpdateCustomer={handleUpdateCustomer}
      onDeleteCustomer={handleDeleteCustomer}
      onCreateDeployment={handleCreateDeployment}
      onUpdateDeployment={handleUpdateDeployment}
      onUpdateDeploymentStage={handleUpdateDeploymentStage}
      onDeleteDeployment={handleDeleteDeployment}
      pendingApprovals={pendingAiActions.length}
    >
      {appError && (
        <div className="app-alert" role="alert">
          {appError}
        </div>
      )}
      {contextEmptyState ?? renderView()}
    </AppLayout>
  )
}

export default App
