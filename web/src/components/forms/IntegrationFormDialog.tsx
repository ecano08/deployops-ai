import { useId, useState, type FormEvent } from 'react'
import { fieldError, isApiValidationError } from '../../lib/apiError'
import { required } from '../../lib/validation'
import type { DeploymentIntegration, IntegrationType } from '../../types'
import { FormDialog } from '../ui/FormDialog'
import { FormField, FormInput, FormSelect } from '../ui/FormField'

type IntegrationFormDialogProps = {
  integration?: DeploymentIntegration | null
  loading?: boolean
  onSubmit: (payload: {
    type: IntegrationType
    name: string
    base_url: string | null
    endpoint: string | null
    api_key: string
    webhook_secret: string
  }) => Promise<void>
  onCancel: () => void
}

export function IntegrationFormDialog({
  integration,
  loading = false,
  onSubmit,
  onCancel,
}: IntegrationFormDialogProps) {
  const typeId = useId()
  const nameId = useId()
  const baseUrlId = useId()
  const endpointId = useId()
  const apiKeyId = useId()
  const webhookSecretId = useId()
  const [type, setType] = useState<IntegrationType>(integration?.type ?? 'rest_api')
  const [name, setName] = useState(integration?.name ?? '')
  const [baseUrl, setBaseUrl] = useState(integration?.base_url ?? '')
  const [endpoint, setEndpoint] = useState(integration?.endpoint ?? '')
  const [apiKey, setApiKey] = useState('')
  const [webhookSecret, setWebhookSecret] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

  function clearFieldError(field: string) {
    if (fieldErrors[field]) {
      setFieldErrors((current) => {
        const next = { ...current }
        delete next[field]
        return next
      })
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFieldErrors({})
    setFormError(null)

    const nextFieldErrors: Record<string, string[]> = {}
    const nameError = required(name, 'Name')

    if (nameError) {
      nextFieldErrors.name = [nameError]
    }

    if (type === 'rest_api' && !integration) {
      const baseUrlError = required(baseUrl, 'Base URL')

      if (baseUrlError) {
        nextFieldErrors.base_url = [baseUrlError]
      }
    }

    if (Object.keys(nextFieldErrors).length > 0) {
      setFieldErrors(nextFieldErrors)
      return
    }

    try {
      await onSubmit({
        type,
        name: name.trim(),
        base_url: baseUrl.trim() === '' ? null : baseUrl.trim(),
        endpoint: endpoint.trim() === '' ? null : endpoint.trim(),
        api_key: apiKey,
        webhook_secret: webhookSecret,
      })
    } catch (error) {
      if (isApiValidationError(error)) {
        setFieldErrors(error.fieldErrors)
        setFormError(error.message)
        return
      }

      setFormError(error instanceof Error ? error.message : 'Unable to save integration.')
    }
  }

  return (
    <FormDialog
      open
      title={integration ? 'Edit integration' : 'Add integration'}
      description="Connect REST APIs or inbound webhooks. Secrets are write-only and never shown after save."
      submitLabel={integration ? 'Save changes' : 'Add integration'}
      loading={loading}
      error={formError}
      onSubmit={handleSubmit}
      onCancel={onCancel}
    >
      <FormField label="Type" htmlFor={typeId} error={fieldError(fieldErrors, 'type')}>
        <FormSelect
          id={typeId}
          value={type}
          onChange={(event) => setType(event.target.value as IntegrationType)}
          disabled={Boolean(integration)}
        >
          <option value="rest_api">REST API</option>
          <option value="webhook">Webhook</option>
        </FormSelect>
      </FormField>

      <FormField label="Name" htmlFor={nameId} error={fieldError(fieldErrors, 'name')}>
        <FormInput
          id={nameId}
          value={name}
          onChange={(event) => {
            setName(event.target.value)
            clearFieldError('name')
          }}
          autoFocus
        />
      </FormField>

      {type === 'rest_api' && (
        <FormField
          label="Base URL"
          htmlFor={baseUrlId}
          error={fieldError(fieldErrors, 'base_url')}
        >
          <FormInput
            id={baseUrlId}
            type="url"
            value={baseUrl}
            onChange={(event) => {
              setBaseUrl(event.target.value)
              clearFieldError('base_url')
            }}
            placeholder="https://api.example.com"
          />
        </FormField>
      )}

      <FormField label="Endpoint" htmlFor={endpointId} error={fieldError(fieldErrors, 'endpoint')}>
        <FormInput
          id={endpointId}
          value={endpoint}
          onChange={(event) => {
            setEndpoint(event.target.value)
            clearFieldError('endpoint')
          }}
          placeholder={type === 'rest_api' ? '/health' : '/webhooks/deployops'}
        />
      </FormField>

      {type === 'rest_api' && (
        <FormField
          label="API key"
          htmlFor={apiKeyId}
          error={fieldError(fieldErrors, 'api_key')}
          hint={
            integration?.has_api_key
              ? 'Leave blank to keep the existing API key.'
              : 'Optional on create.'
          }
        >
          <FormInput
            id={apiKeyId}
            type="password"
            value={apiKey}
            onChange={(event) => {
              setApiKey(event.target.value)
              clearFieldError('api_key')
            }}
            autoComplete="new-password"
          />
        </FormField>
      )}

      {type === 'webhook' && (
        <FormField
          label="Webhook secret"
          htmlFor={webhookSecretId}
          error={fieldError(fieldErrors, 'webhook_secret')}
          hint={
            integration?.has_webhook_secret
              ? 'Leave blank to keep the existing secret.'
              : 'Optional on create.'
          }
        >
          <FormInput
            id={webhookSecretId}
            type="password"
            value={webhookSecret}
            onChange={(event) => {
              setWebhookSecret(event.target.value)
              clearFieldError('webhook_secret')
            }}
            autoComplete="new-password"
          />
        </FormField>
      )}
    </FormDialog>
  )
}
