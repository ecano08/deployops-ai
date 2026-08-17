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

export function Alert({ variant = 'info', children, onDismiss }: AlertProps) {
  return (
    <div className={`alert ${variantClass[variant]}`} role={variant === 'error' ? 'alert' : 'status'}>
      <span>{children}</span>
      {onDismiss && (
        <button type="button" className="alert__dismiss" onClick={onDismiss} aria-label="Dismiss">
          ×
        </button>
      )}
    </div>
  )
}
