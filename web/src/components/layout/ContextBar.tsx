import { useState } from 'react'
import { Building2, FolderPlus, Layers, Plus, Users } from 'lucide-react'
import { canManageCustomers, canManageDeployments } from '../../lib/permissions'
import { required } from '../../lib/validation'
import type { Customer, Deployment, DeploymentStage, Workspace } from '../../types'
import { DEPLOYMENT_STAGES } from '../../types'
import { CustomerFormDialog } from '../forms/CustomerFormDialog'
import { DeploymentFormDialog } from '../forms/DeploymentFormDialog'
import { Button } from '../ui/Button'
import { ConfirmDialog } from '../ui/ConfirmDialog'
import { FormField } from '../ui/FormField'
import { Icon } from '../ui/Icon'

type ContextBarProps = {
  workspaces: Workspace[]
  customers: Customer[]
  deployments: Deployment[]
  selectedWorkspaceId: number | null
  selectedCustomerId: number | null
  selectedDeploymentId: number | null
  onWorkspaceChange: (id: number) => void
  onCustomerChange: (id: number) => void
  onDeploymentChange: (id: number) => void
  onCreateWorkspace: (name: string) => Promise<void>
  onCreateCustomer: (payload: { name: string; description: string | null }) => Promise<void>
  onUpdateCustomer: (
    customerId: number,
    payload: { name: string; description: string | null },
  ) => Promise<void>
  onDeleteCustomer: (customerId: number) => Promise<void>
  onCreateDeployment: (payload: {
    name: string
    description: string | null
    stage: DeploymentStage
  }) => Promise<void>
  onUpdateDeployment: (
    deploymentId: number,
    payload: { name: string; description: string | null; stage: DeploymentStage },
  ) => Promise<void>
  onUpdateDeploymentStage: (deploymentId: number, stage: DeploymentStage) => Promise<void>
  onDeleteDeployment: (deploymentId: number) => Promise<void>
}

export function ContextBar({
  workspaces,
  customers,
  deployments,
  selectedWorkspaceId,
  selectedCustomerId,
  selectedDeploymentId,
  onWorkspaceChange,
  onCustomerChange,
  onDeploymentChange,
  onCreateWorkspace,
  onCreateCustomer,
  onUpdateCustomer,
  onDeleteCustomer,
  onCreateDeployment,
  onUpdateDeployment,
  onUpdateDeploymentStage,
  onDeleteDeployment,
}: ContextBarProps) {
  const selectedWorkspace =
    workspaces.find((workspace) => workspace.id === selectedWorkspaceId) ?? null
  const selectedCustomer = customers.find((customer) => customer.id === selectedCustomerId) ?? null
  const selectedDeployment =
    deployments.find((deployment) => deployment.id === selectedDeploymentId) ?? null

  const canManageCustomerEntities = canManageCustomers(selectedWorkspace?.current_user_role)
  const canManageDeploymentEntities = canManageDeployments(selectedWorkspace?.current_user_role)

  const [workspaceName, setWorkspaceName] = useState('')
  const [workspaceNameError, setWorkspaceNameError] = useState<string | null>(null)
  const [creatingWorkspace, setCreatingWorkspace] = useState(false)
  const [customerDialogMode, setCustomerDialogMode] = useState<'create' | 'edit' | null>(null)
  const [deploymentDialogMode, setDeploymentDialogMode] = useState<'create' | 'edit' | null>(null)
  const [deleteCustomerTarget, setDeleteCustomerTarget] = useState<Customer | null>(null)
  const [deleteDeploymentTarget, setDeleteDeploymentTarget] = useState<Deployment | null>(null)
  const [customerSaving, setCustomerSaving] = useState(false)
  const [deploymentSaving, setDeploymentSaving] = useState(false)
  const [deletingCustomer, setDeletingCustomer] = useState(false)
  const [deletingDeployment, setDeletingDeployment] = useState(false)
  const [stageUpdating, setStageUpdating] = useState(false)

  async function handleCreateWorkspace(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const validationError = required(workspaceName, 'Workspace name')

    if (validationError) {
      setWorkspaceNameError(validationError)
      return
    }

    setWorkspaceNameError(null)
    setCreatingWorkspace(true)

    try {
      await onCreateWorkspace(workspaceName.trim())
      setWorkspaceName('')
    } finally {
      setCreatingWorkspace(false)
    }
  }

  async function handleCustomerSubmit(payload: { name: string; description: string | null }) {
    setCustomerSaving(true)

    try {
      if (customerDialogMode === 'edit' && selectedCustomer) {
        await onUpdateCustomer(selectedCustomer.id, payload)
      } else {
        await onCreateCustomer(payload)
      }

      setCustomerDialogMode(null)
    } finally {
      setCustomerSaving(false)
    }
  }

  async function handleDeploymentSubmit(payload: {
    name: string
    description: string | null
    stage: DeploymentStage
  }) {
    setDeploymentSaving(true)

    try {
      if (deploymentDialogMode === 'edit' && selectedDeployment) {
        await onUpdateDeployment(selectedDeployment.id, payload)
      } else {
        await onCreateDeployment(payload)
      }

      setDeploymentDialogMode(null)
    } finally {
      setDeploymentSaving(false)
    }
  }

  async function confirmDeleteCustomer() {
    if (!deleteCustomerTarget) {
      return
    }

    setDeletingCustomer(true)

    try {
      await onDeleteCustomer(deleteCustomerTarget.id)
      setDeleteCustomerTarget(null)
    } finally {
      setDeletingCustomer(false)
    }
  }

  async function confirmDeleteDeployment() {
    if (!deleteDeploymentTarget) {
      return
    }

    setDeletingDeployment(true)

    try {
      await onDeleteDeployment(deleteDeploymentTarget.id)
      setDeleteDeploymentTarget(null)
    } finally {
      setDeletingDeployment(false)
    }
  }

  async function handleStageChange(stage: DeploymentStage) {
    if (!selectedDeployment || stage === selectedDeployment.stage) {
      return
    }

    setStageUpdating(true)

    try {
      await onUpdateDeploymentStage(selectedDeployment.id, stage)
    } finally {
      setStageUpdating(false)
    }
  }

  return (
    <>
      <section className="context-bar" aria-label="Workspace context">
        <div className="context-bar__selectors">
          <label className="context-bar__field">
            <span className="context-bar__field-label">
              <Icon icon={Building2} size="xs" />
              Workspace
            </span>
            <select
              value={selectedWorkspaceId ?? ''}
              onChange={(event) => onWorkspaceChange(Number(event.target.value))}
              disabled={workspaces.length === 0}
            >
              <option value="" disabled>
                Select workspace
              </option>
              {workspaces.map((workspace) => (
                <option key={workspace.id} value={workspace.id}>
                  {workspace.name}
                </option>
              ))}
            </select>
          </label>

          <div className="context-bar__group">
            <label className="context-bar__field">
              <span className="context-bar__field-label">
                <Icon icon={Users} size="xs" />
                Customer
              </span>
              <select
                value={selectedCustomerId ?? ''}
                onChange={(event) => onCustomerChange(Number(event.target.value))}
                disabled={!selectedWorkspaceId || customers.length === 0}
              >
                <option value="" disabled>
                  Select customer
                </option>
                {customers.map((customer) => (
                  <option key={customer.id} value={customer.id}>
                    {customer.name}
                  </option>
                ))}
              </select>
            </label>

            {canManageCustomerEntities && (
              <div className="context-bar__actions">
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => setCustomerDialogMode('create')}
                  disabled={!selectedWorkspaceId}
                >
                  Create
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setCustomerDialogMode('edit')}
                  disabled={!selectedCustomer}
                >
                  Edit
                </Button>
                <Button
                  variant="danger"
                  size="sm"
                  onClick={() => selectedCustomer && setDeleteCustomerTarget(selectedCustomer)}
                  disabled={!selectedCustomer}
                >
                  Delete
                </Button>
              </div>
            )}
          </div>

          <div className="context-bar__group">
            <label className="context-bar__field">
              <span className="context-bar__field-label">
                <Icon icon={Layers} size="xs" />
                Deployment
              </span>
              <select
                value={selectedDeploymentId ?? ''}
                onChange={(event) => onDeploymentChange(Number(event.target.value))}
                disabled={!selectedCustomerId || deployments.length === 0}
              >
                <option value="" disabled>
                  Select deployment
                </option>
                {DEPLOYMENT_STAGES.map((stage) => {
                  const stageDeployments = deployments.filter(
                    (deployment) => deployment.stage === stage,
                  )

                  if (stageDeployments.length === 0) {
                    return null
                  }

                  return (
                    <optgroup key={stage} label={stage}>
                      {stageDeployments.map((deployment) => (
                        <option key={deployment.id} value={deployment.id}>
                          {deployment.name}
                        </option>
                      ))}
                    </optgroup>
                  )
                })}
              </select>
            </label>

            {canManageDeploymentEntities && (
              <div className="context-bar__actions">
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => setDeploymentDialogMode('create')}
                  disabled={!selectedCustomerId}
                >
                  Create
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setDeploymentDialogMode('edit')}
                  disabled={!selectedDeployment}
                >
                  Edit
                </Button>
                <Button
                  variant="danger"
                  size="sm"
                  onClick={() =>
                    selectedDeployment && setDeleteDeploymentTarget(selectedDeployment)
                  }
                  disabled={!selectedDeployment}
                >
                  Delete
                </Button>
              </div>
            )}
          </div>

          {selectedDeployment && canManageDeploymentEntities && (
            <label className="context-bar__field">
              <span className="context-bar__field-label">
                <Icon icon={Layers} size="xs" />
                Stage
              </span>
              <select
                value={selectedDeployment.stage}
                onChange={(event) => handleStageChange(event.target.value as DeploymentStage)}
                disabled={stageUpdating}
              >
                {DEPLOYMENT_STAGES.map((stage) => (
                  <option key={stage} value={stage}>
                    {stage}
                  </option>
                ))}
              </select>
            </label>
          )}
        </div>

        <div className="context-bar__divider" aria-hidden="true" />

        <form className="context-bar__create" onSubmit={handleCreateWorkspace} noValidate>
          <FormField
            label="New workspace"
            error={workspaceNameError}
            className="context-bar__create-field"
          >
            <input
              value={workspaceName}
              onChange={(event) => {
                setWorkspaceName(event.target.value)
                if (workspaceNameError) {
                  setWorkspaceNameError(null)
                }
              }}
              placeholder="Workspace name"
              disabled={creatingWorkspace}
            />
          </FormField>
          <button
            type="submit"
            className="btn btn--secondary btn--sm context-bar__create-btn"
            disabled={creatingWorkspace}
          >
            <Icon icon={creatingWorkspace ? FolderPlus : Plus} size="xs" />
            {creatingWorkspace ? 'Creating…' : 'Create'}
          </button>
        </form>
      </section>

      {customerDialogMode !== null && (
        <CustomerFormDialog
          key={customerDialogMode === 'edit' ? selectedCustomer?.id : 'create'}
          customer={customerDialogMode === 'edit' ? selectedCustomer : null}
          loading={customerSaving}
          onSubmit={handleCustomerSubmit}
          onCancel={() => setCustomerDialogMode(null)}
        />
      )}

      {deploymentDialogMode !== null && (
        <DeploymentFormDialog
          key={deploymentDialogMode === 'edit' ? selectedDeployment?.id : 'create'}
          deployment={deploymentDialogMode === 'edit' ? selectedDeployment : null}
          loading={deploymentSaving}
          onSubmit={handleDeploymentSubmit}
          onCancel={() => setDeploymentDialogMode(null)}
        />
      )}

      <ConfirmDialog
        open={deleteCustomerTarget !== null}
        title="Delete customer?"
        description={`This will permanently delete "${deleteCustomerTarget?.name}" and all of its deployments.`}
        confirmLabel="Delete customer"
        loading={deletingCustomer}
        onConfirm={confirmDeleteCustomer}
        onCancel={() => setDeleteCustomerTarget(null)}
      />

      <ConfirmDialog
        open={deleteDeploymentTarget !== null}
        title="Delete deployment?"
        description={`This will permanently delete "${deleteDeploymentTarget?.name}" and its integrations.`}
        confirmLabel="Delete deployment"
        loading={deletingDeployment}
        onConfirm={confirmDeleteDeployment}
        onCancel={() => setDeleteDeploymentTarget(null)}
      />
    </>
  )
}
