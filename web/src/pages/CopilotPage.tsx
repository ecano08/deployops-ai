import { useState, type FormEvent } from 'react'
import { Bot, Layers, Send, Sparkles } from 'lucide-react'
import { Alert } from '../components/ui/Alert'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import { FormField } from '../components/ui/FormField'
import { Icon } from '../components/ui/Icon'
import { required } from '../lib/validation'
import type { Deployment } from '../types'

type CopilotPageProps = {
  deployment: Deployment | null
  question: string
  answer: string | null
  toolsUsed: string[]
  error: string | null
  errorReference?: string | null
  loading: boolean
  onQuestionChange: (value: string) => void
  onSubmit: (event: FormEvent<HTMLFormElement>) => Promise<void>
}

export function CopilotPage({
  deployment,
  question,
  answer,
  toolsUsed,
  error,
  errorReference = null,
  loading,
  onQuestionChange,
  onSubmit,
}: CopilotPageProps) {
  const [suggestions] = useState([
    'What integrations are connected to this deployment?',
    'Summarize recent incidents and their severity.',
    'How healthy is the AI copilot for this deployment?',
  ])
  const [questionError, setQuestionError] = useState<string | null>(null)

  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="The copilot needs deployment context to answer operational questions."
        icon={Layers}
      />
    )
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const validationError = required(question, 'Question')

    if (validationError) {
      setQuestionError(validationError)
      return
    }

    setQuestionError(null)
    await onSubmit(event)
  }

  return (
    <div className="page-stack">
      <Card
        title="Ask the copilot"
        description={`Deployment context: ${deployment.name} (${deployment.stage})`}
      >
        <form className="form form--wide" onSubmit={handleSubmit} noValidate>
          <FormField label="Your question" error={questionError}>
            <textarea
              value={question}
              onChange={(event) => {
                onQuestionChange(event.target.value)
                if (questionError) {
                  setQuestionError(null)
                }
              }}
              rows={4}
              placeholder="Ask about deployments, integrations, incidents, or knowledge…"
            />
          </FormField>

          <div className="suggestion-row">
            {suggestions.map((suggestion) => (
              <button
                key={suggestion}
                type="button"
                className="suggestion-chip"
                onClick={() => {
                  onQuestionChange(suggestion)
                  if (questionError) {
                    setQuestionError(null)
                  }
                }}
              >
                <Icon icon={Sparkles} size="xs" />
                {suggestion}
              </button>
            ))}
          </div>

          <Button type="submit" variant="primary" loading={loading}>
            <Icon icon={Send} size="xs" />
            Ask copilot
          </Button>
        </form>

        {error && (
          <Alert variant="error">
            <div className="alert__stack">
              <span>{error}</span>
              {errorReference && (
                <span className="alert__meta">Error reference: #{errorReference}</span>
              )}
            </div>
          </Alert>
        )}
      </Card>

      {answer && (
        <Card title="Response">
          <div className="copilot-answer">
            <p>{answer}</p>
          </div>
          {toolsUsed.length > 0 && (
            <div className="tool-tags">
              <span className="tool-tags__label">Tools used:</span>
              {toolsUsed.map((tool) => (
                <Badge key={tool} variant="info">
                  {tool}
                </Badge>
              ))}
            </div>
          )}
        </Card>
      )}

      {!answer && !loading && (
        <EmptyState
          title="Ready to assist"
          description="Ask a question about this deployment to get AI-powered operational insights."
          icon={Bot}
        />
      )}
    </div>
  )
}
