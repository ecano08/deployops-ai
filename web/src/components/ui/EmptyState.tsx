import type { LucideIcon } from 'lucide-react'
import { Inbox } from 'lucide-react'
import { Icon } from './Icon'

type EmptyStateProps = {
  title: string
  description?: string
  action?: React.ReactNode
  icon?: LucideIcon
  compact?: boolean
}

export function EmptyState({
  title,
  description,
  action,
  icon: EmptyIcon = Inbox,
  compact = false,
}: EmptyStateProps) {
  return (
    <div
      className={`state state--empty ${compact ? 'state--compact' : ''}`.trim()}
      role="status"
    >
      <div className="state__icon" aria-hidden="true">
        <Icon icon={EmptyIcon} size={compact ? 'sm' : 'lg'} />
      </div>
      <div className="state__body">
        <p className="state__title">{title}</p>
        {description && <p className="state__description">{description}</p>}
        {action && <div className="state__action">{action}</div>}
      </div>
    </div>
  )
}
