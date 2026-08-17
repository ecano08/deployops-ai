import { useState, type FormEvent } from 'react'
import { Alert } from '../components/ui/Alert'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { EmptyState } from '../components/ui/EmptyState'
import type { Deployment } from '../types'

type CopilotPageProps = {
  deployment: Deployment | null
  question: string
  answer: string | null
  toolsUsed: string[]
  error: string | null
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
  loading,
  onQuestionChange,
  onSubmit,
}: CopilotPageProps) {
  const [suggestions] = useState([
    'What integrations are connected to this deployment?',
    'Summarize recent incidents and their severity.',
    'How healthy is the AI copilot for this deployment?',
  ])

  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="The copilot needs deployment context to answer operational questions."
      />
    )
  }

  return (
    <div className="page-stack">
      <Card
        title="Ask the copilot"
        description={`Deployment context: ${deployment.name} (${deployment.stage})`}
      >
        <form className="form form--wide" onSubmit={onSubmit}>
          <label>
            Your question
            <textarea
              value={question}
              onChange={(event) => onQuestionChange(event.target.value)}
              rows={4}
              placeholder="Ask about deployments, integrations, incidents, or knowledge…"
              required
            />
          </label>

          <div className="suggestion-row">
            {suggestions.map((suggestion) => (
              <button
                key={suggestion}
                type="button"
                className="suggestion-chip"
                onClick={() => onQuestionChange(suggestion)}
              >
                {suggestion}
              </button>
            ))}
          </div>

          <Button type="submit" variant="primary" loading={loading}>
            Ask copilot
          </Button>
        </form>

        {error && <Alert variant="error">{error}</Alert>}
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
    </div>
  )
}
