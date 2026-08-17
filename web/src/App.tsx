import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
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
  updateCustomer,
  updateDeployment,
  updateDeploymentStage,
  updateEvaluationCase,
  updateEvaluationDataset,
  updateIntegration,
  uploadKnowledgeDocument,
} from './api'
import { AppLayout } from './components/layout/AppLayout'
import type { AppView } from './components/layout/Sidebar'
import { EmptyState } from './components/ui/EmptyState'
import { LoadingState } from './components/ui/LoadingState'
import { canManageCustomers, canManageDeployments } from './lib/permissions'
import { apiErrorReference } from './lib/apiError'
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
  DeploymentStage,
  EvaluationDataset,
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
  const [copilotErrorReference, setCopilotErrorReference] = useState<string | null>(null)
  const [copilotLoading, setCopilotLoading] = useState(false)
  const knowledgePollInFlight = useRef(false)
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
    if (
      !user ||
      selectedWorkspaceId === null ||
      selectedCustomerId === null ||
      selectedDeploymentId === null
    ) {
      return
    }

    const hasProcessingDocuments = knowledgeDocuments.some((document) => {
      const status = document.status.toLowerCase()
      return status === 'pending' || status === 'processing'
    })

    if (!hasProcessingDocuments) {
      return
    }

    const intervalId = window.setInterval(() => {
      if (knowledgePollInFlight.current) {
        return
      }

      knowledgePollInFlight.current = true

      fetchKnowledgeDocuments(selectedWorkspaceId, selectedCustomerId, selectedDeploymentId)
        .then((response) => {
          setKnowledgeDocuments(response.data)
        })
        .catch(() => {
          // Keep the current list visible if a poll request fails transiently.
        })
        .finally(() => {
          knowledgePollInFlight.current = false
        })
    }, 2500)

    return () => {
      window.clearInterval(intervalId)
    }
  }, [
    user,
    selectedWorkspaceId,
    selectedCustomerId,
    selectedDeploymentId,
    knowledgeDocuments,
  ])

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
    setKnowledgeDocuments([])
    setKnowledgeError(null)
    setKnowledgeLoading(false)
    setKnowledgeMessage(null)
    setCopilotQuestion('')
    setCopilotAnswer(null)
    setCopilotToolsUsed([])
    setCopilotError(null)
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
    setEvaluationDatasetsLoading(true)
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
    setCopilotErrorReference(null)
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
            integrations={integrations}
            integrationsLoading={integrationsLoading}
            knowledgeDocuments={knowledgeDocuments}
            knowledgeLoading={knowledgeLoading}
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
            answer={copilotAnswer}
            toolsUsed={copilotToolsUsed}
            error={copilotError}
            errorReference={copilotErrorReference}
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
    if (customersLoading || deploymentsLoading) {
      return <LoadingState label="Loading context…" />
    }

    if (customersError) {
      return <p role="alert">{customersError}</p>
    }

    if (selectedWorkspace && customers.length === 0) {
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

    if (selectedCustomer && deployments.length === 0 && activeView !== 'dashboard') {
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
