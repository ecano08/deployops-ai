import { useState } from 'react'
import { Building2, FolderPlus, Layers, Plus, Users } from 'lucide-react'
import type { Customer, Deployment, Workspace } from '../../types'
import { DEPLOYMENT_STAGES } from '../../types'
import { FormField } from '../ui/FormField'
import { Icon } from '../ui/Icon'
import { required } from '../../lib/validation'

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
}: ContextBarProps) {
  const [workspaceName, setWorkspaceName] = useState('')
  const [workspaceNameError, setWorkspaceNameError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  async function handleCreateWorkspace(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const validationError = required(workspaceName, 'Workspace name')

    if (validationError) {
      setWorkspaceNameError(validationError)
      return
    }

    setWorkspaceNameError(null)
    setCreating(true)

    try {
      await onCreateWorkspace(workspaceName.trim())
      setWorkspaceName('')
    } finally {
      setCreating(false)
    }
  }

  return (
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
              const stageDeployments = deployments.filter((deployment) => deployment.stage === stage)

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
            disabled={creating}
          />
        </FormField>
        <button type="submit" className="btn btn--secondary btn--sm context-bar__create-btn" disabled={creating}>
          <Icon icon={creating ? FolderPlus : Plus} size="xs" />
          {creating ? 'Creating…' : 'Create'}
        </button>
      </form>
    </section>
  )
}
