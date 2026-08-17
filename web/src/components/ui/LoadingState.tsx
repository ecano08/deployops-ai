import { Loader2 } from 'lucide-react'
import { Icon } from './Icon'

type LoadingStateProps = {
  label?: string
}

export function LoadingState({ label = 'Loading…' }: LoadingStateProps) {
  return (
    <div className="state state--loading" role="status" aria-live="polite">
      <Icon icon={Loader2} size="sm" className="spinner-icon" />
      <span>{label}</span>
    </div>
  )
}
