import { useId, useState, type FormEvent } from 'react'
import { fieldError, isApiValidationError } from '../../lib/apiError'
import { required } from '../../lib/validation'
import type { EvaluationCase } from '../../types'
import { FormDialog } from '../ui/FormDialog'
import { FormField, FormTextarea } from '../ui/FormField'

type EvaluationCaseFormDialogProps = {
  evaluationCase?: EvaluationCase | null
  loading?: boolean
  onSubmit: (payload: {
    input: string
    expected_behavior: string
    expected_tools: string[] | null
    expected_sources: string[] | null
  }) => Promise<void>
  onCancel: () => void
}

function formatOptionalList(items: string[] | null | undefined): string {
  return items?.join('\n') ?? ''
}

function parseOptionalList(value: string): string[] | null {
  const items = value
    .split(/[\n,]/)
    .map((item) => item.trim())
    .filter(Boolean)

  return items.length > 0 ? items : null
}

export function EvaluationCaseFormDialog({
  evaluationCase,
  loading = false,
  onSubmit,
  onCancel,
}: EvaluationCaseFormDialogProps) {
  const inputId = useId()
  const expectedBehaviorId = useId()
  const expectedToolsId = useId()
  const expectedSourcesId = useId()
  const [input, setInput] = useState(evaluationCase?.input ?? '')
  const [expectedBehavior, setExpectedBehavior] = useState(evaluationCase?.expected_behavior ?? '')
  const [expectedTools, setExpectedTools] = useState(
    formatOptionalList(evaluationCase?.expected_tools),
  )
  const [expectedSources, setExpectedSources] = useState(
    formatOptionalList(evaluationCase?.expected_sources),
  )
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFieldErrors({})
    setFormError(null)

    const nextFieldErrors: Record<string, string[]> = {}
    const inputError = required(input, 'Input')
    const expectedBehaviorError = required(expectedBehavior, 'Expected behavior')

    if (inputError) {
      nextFieldErrors.input = [inputError]
    }

    if (expectedBehaviorError) {
      nextFieldErrors.expected_behavior = [expectedBehaviorError]
    }

    if (Object.keys(nextFieldErrors).length > 0) {
      setFieldErrors(nextFieldErrors)
      return
    }

    try {
      await onSubmit({
        input: input.trim(),
        expected_behavior: expectedBehavior.trim(),
        expected_tools: parseOptionalList(expectedTools),
        expected_sources: parseOptionalList(expectedSources),
      })
    } catch (error) {
      if (isApiValidationError(error)) {
        setFieldErrors(error.fieldErrors)
        setFormError(error.message)
        return
      }

      setFormError(error instanceof Error ? error.message : 'Unable to save evaluation case.')
    }
  }

  return (
    <FormDialog
      open
      size="wide"
      title={evaluationCase ? 'Edit evaluation case' : 'Add evaluation case'}
      description="Define a prompt and the expected copilot behavior. Optional tool and source checks validate grounding and tool usage."
      submitLabel={evaluationCase ? 'Save changes' : 'Add case'}
      loading={loading}
      error={formError}
      onSubmit={handleSubmit}
      onCancel={onCancel}
    >
      <FormField
        label="Input"
        htmlFor={inputId}
        error={fieldError(fieldErrors, 'input')}
        hint="The user question sent to the copilot during the evaluation run."
      >
        <FormTextarea
          id={inputId}
          value={input}
          onChange={(event) => {
            setInput(event.target.value)
            if (fieldErrors.input) {
              setFieldErrors((current) => {
                const next = { ...current }
                delete next.input
                return next
              })
            }
          }}
          rows={4}
          className="form-textarea--md"
          autoFocus
        />
      </FormField>

      <FormField
        label="Expected behavior"
        htmlFor={expectedBehaviorId}
        error={fieldError(fieldErrors, 'expected_behavior')}
        hint="Describe the answer the copilot should provide, e.g. “Lists the connected integrations for the current deployment.”"
      >
        <FormTextarea
          id={expectedBehaviorId}
          value={expectedBehavior}
          onChange={(event) => {
            setExpectedBehavior(event.target.value)
            if (fieldErrors.expected_behavior) {
              setFieldErrors((current) => {
                const next = { ...current }
                delete next.expected_behavior
                return next
              })
            }
          }}
          rows={4}
          className="form-textarea--md"
        />
      </FormField>

      <div className="form-fields-grid">
        <FormField
          label="Expected tools"
          htmlFor={expectedToolsId}
          error={fieldError(fieldErrors, 'expected_tools')}
          hint="Optional. One tool name per line or comma-separated, e.g. list_deployment_integrations."
        >
          <FormTextarea
            id={expectedToolsId}
            value={expectedTools}
            onChange={(event) => {
              setExpectedTools(event.target.value)
              if (fieldErrors.expected_tools) {
                setFieldErrors((current) => {
                  const next = { ...current }
                  delete next.expected_tools
                  return next
                })
              }
            }}
            rows={3}
            className="form-textarea--sm"
          />
        </FormField>

        <FormField
          label="Expected sources"
          htmlFor={expectedSourcesId}
          error={fieldError(fieldErrors, 'expected_sources')}
          hint="Optional. Knowledge document filenames or source identifiers the answer should cite."
        >
          <FormTextarea
            id={expectedSourcesId}
            value={expectedSources}
            onChange={(event) => {
              setExpectedSources(event.target.value)
              if (fieldErrors.expected_sources) {
                setFieldErrors((current) => {
                  const next = { ...current }
                  delete next.expected_sources
                  return next
                })
              }
            }}
            rows={3}
            className="form-textarea--sm"
          />
        </FormField>
      </div>
    </FormDialog>
  )
}
