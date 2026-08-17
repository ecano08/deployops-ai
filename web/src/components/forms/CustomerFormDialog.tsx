import { useId, useState, type FormEvent } from 'react'
import { fieldError, isApiValidationError } from '../../lib/apiError'
import { required } from '../../lib/validation'
import type { Customer } from '../../types'
import { FormDialog } from '../ui/FormDialog'
import { FormField, FormInput, FormTextarea } from '../ui/FormField'

type CustomerFormDialogProps = {
  customer?: Customer | null
  loading?: boolean
  onSubmit: (payload: { name: string; description: string | null }) => Promise<void>
  onCancel: () => void
}

export function CustomerFormDialog({
  customer,
  loading = false,
  onSubmit,
  onCancel,
}: CustomerFormDialogProps) {
  const nameId = useId()
  const descriptionId = useId()
  const [name, setName] = useState(customer?.name ?? '')
  const [description, setDescription] = useState(customer?.description ?? '')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFieldErrors({})
    setFormError(null)

    const nextFieldErrors: Record<string, string[]> = {}
    const nameError = required(name, 'Name')

    if (nameError) {
      nextFieldErrors.name = [nameError]
    }

    if (Object.keys(nextFieldErrors).length > 0) {
      setFieldErrors(nextFieldErrors)
      return
    }

    try {
      await onSubmit({
        name: name.trim(),
        description: description.trim() === '' ? null : description.trim(),
      })
    } catch (error) {
      if (isApiValidationError(error)) {
        setFieldErrors(error.fieldErrors)
        setFormError(error.message)
        return
      }

      setFormError(error instanceof Error ? error.message : 'Unable to save customer.')
    }
  }

  return (
    <FormDialog
      open
      title={customer ? 'Edit customer' : 'Create customer'}
      description="Customers group deployments and integrations for a single end client."
      submitLabel={customer ? 'Save changes' : 'Create customer'}
      loading={loading}
      error={formError}
      onSubmit={handleSubmit}
      onCancel={onCancel}
    >
      <FormField
        label="Name"
        htmlFor={nameId}
        error={fieldError(fieldErrors, 'name')}
      >
        <FormInput
          id={nameId}
          value={name}
          onChange={(event) => {
            setName(event.target.value)
            if (fieldErrors.name) {
              setFieldErrors((current) => {
                const next = { ...current }
                delete next.name
                return next
              })
            }
          }}
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
          onChange={(event) => {
            setDescription(event.target.value)
            if (fieldErrors.description) {
              setFieldErrors((current) => {
                const next = { ...current }
                delete next.description
                return next
              })
            }
          }}
          rows={3}
        />
      </FormField>
    </FormDialog>
  )
}
