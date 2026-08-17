import type { LucideIcon } from 'lucide-react'
import { AlertCircle, CheckCircle2, Info, TriangleAlert, X } from 'lucide-react'
import { Icon } from './Icon'

type AlertVariant = 'info' | 'success' | 'warning' | 'error'

type AlertProps = {
  variant?: AlertVariant
  children: React.ReactNode
  onDismiss?: () => void
}

const variantClass: Record<AlertVariant, string> = {
  info: 'alert--info',
  success: 'alert--success',
  warning: 'alert--warning',
  error: 'alert--error',
}

const variantIcon: Record<AlertVariant, LucideIcon> = {
  info: Info,
  success: CheckCircle2,
  warning: TriangleAlert,
  error: AlertCircle,
}

export function Alert({ variant = 'info', children, onDismiss }: AlertProps) {
  const AlertIcon = variantIcon[variant]

  return (
    <div className={`alert ${variantClass[variant]}`} role={variant === 'error' ? 'alert' : 'status'}>
      <span className="alert__icon" aria-hidden="true">
        <Icon icon={AlertIcon} size="sm" />
      </span>
      <span className="alert__content">{children}</span>
      {onDismiss && (
        <button type="button" className="alert__dismiss" onClick={onDismiss} aria-label="Dismiss">
          <Icon icon={X} size="sm" />
        </button>
      )}
    </div>
  )
}
