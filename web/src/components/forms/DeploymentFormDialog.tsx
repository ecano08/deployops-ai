import { useId, useState, type FormEvent } from 'react'
import { fieldError, isApiValidationError } from '../../lib/apiError'
import { DEPLOYMENT_STAGES, type Deployment, type DeploymentStage } from '../../types'
import { FormDialog } from '../ui/FormDialog'
import { FormField, FormInput, FormSelect, FormTextarea } from '../ui/FormField'

type DeploymentFormDialogProps = {
  deployment?: Deployment | null
  loading?: boolean
  onSubmit: (payload: {
    name: string
    description: string | null
    stage: DeploymentStage
  }) => Promise<void>
  onCancel: () => void
}

export function DeploymentFormDialog({
  deployment,
  loading = false,
  onSubmit,
  onCancel,
}: DeploymentFormDialogProps) {
  const nameId = useId()
  const descriptionId = useId()
  const stageId = useId()
  const [name, setName] = useState(deployment?.name ?? '')
  const [description, setDescription] = useState(deployment?.description ?? '')
  const [stage, setStage] = useState<DeploymentStage>(deployment?.stage ?? 'discovery')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFieldErrors({})
    setFormError(null)

    try {
      await onSubmit({
        name: name.trim(),
        description: description.trim() === '' ? null : description.trim(),
        stage,
      })
    } catch (error) {
      if (isApiValidationError(error)) {
        setFieldErrors(error.fieldErrors)
        setFormError(error.message)
        return
      }

      setFormError(error instanceof Error ? error.message : 'Unable to save deployment.')
    }
  }

  return (
    <FormDialog
      open
      title={deployment ? 'Edit deployment' : 'Create deployment'}
      description="Deployments track delivery stages for a customer engagement."
      submitLabel={deployment ? 'Save changes' : 'Create deployment'}
      loading={loading}
      error={formError}
      onSubmit={handleSubmit}
      onCancel={onCancel}
    >
      <FormField label="Name" htmlFor={nameId} error={fieldError(fieldErrors, 'name')}>
        <FormInput
          id={nameId}
          value={name}
          onChange={(event) => setName(event.target.value)}
          required
          autoFocus
        />
      </FormField>

      <FormField
        label="Description"
        htmlFor={descriptionId}
        error={fieldError(fieldErrors, 'description')}
      >
        <FormTextarea
          id={descriptionId}
          value={description}
          onChange={(event) => setDescription(event.target.value)}
          rows={3}
        />
      </FormField>

      <FormField label="Stage" htmlFor={stageId} error={fieldError(fieldErrors, 'stage')}>
        <FormSelect
          id={stageId}
          value={stage}
          onChange={(event) => setStage(event.target.value as DeploymentStage)}
        >
          {DEPLOYMENT_STAGES.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </FormSelect>
      </FormField>
    </FormDialog>
  )
}
