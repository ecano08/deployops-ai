import { AlertCircle } from 'lucide-react'
import { Icon } from './Icon'

type ErrorStateProps = {
  title?: string
  message: string
  onRetry?: () => void
}

export function ErrorState({ title = 'Something went wrong', message, onRetry }: ErrorStateProps) {
  return (
    <div className="state state--error" role="alert">
      <div className="state__icon" aria-hidden="true">
        <Icon icon={AlertCircle} size="lg" />
      </div>
      <p className="state__title">{title}</p>
      <p className="state__description">{message}</p>
      {onRetry && (
        <div className="state__action">
          <button type="button" className="btn btn--secondary btn--sm" onClick={onRetry}>
            Try again
          </button>
        </div>
      )}
    </div>
  )
}
