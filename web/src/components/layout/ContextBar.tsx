import type { Customer, Deployment, Workspace } from '../../types'
import { DEPLOYMENT_STAGES } from '../../types'

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
  async function handleCreateWorkspace(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    const input = form.elements.namedItem('workspaceName') as HTMLInputElement
    const name = input.value.trim()

    if (!name) {
      return
    }

    await onCreateWorkspace(name)
    input.value = ''
  }

  return (
    <section className="context-bar" aria-label="Workspace context">
      <div className="context-bar__selectors">
        <label className="context-bar__field">
          <span>Workspace</span>
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
          <span>Customer</span>
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
          <span>Deployment</span>
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

      <form className="context-bar__create" onSubmit={handleCreateWorkspace}>
        <label className="context-bar__field context-bar__field--inline">
          <span className="sr-only">New workspace</span>
          <input name="workspaceName" placeholder="New workspace name" required />
        </label>
        <button type="submit" className="btn btn--secondary btn--sm">
          Create
        </button>
      </form>
    </section>
  )
}
