import type { BadgeVariant } from './badgeUtils'

type BadgeProps = {
  children: React.ReactNode
  variant?: BadgeVariant
  className?: string
}

const variantClass: Record<BadgeVariant, string> = {
  default: 'badge--default',
  success: 'badge--success',
  warning: 'badge--warning',
  danger: 'badge--danger',
  info: 'badge--info',
  neutral: 'badge--neutral',
  owner: 'badge--owner',
  admin: 'badge--admin',
  engineer: 'badge--engineer',
  viewer: 'badge--viewer',
}

export function Badge({ children, variant = 'default', className = '' }: BadgeProps) {
  return (
    <span className={`badge ${variantClass[variant]} ${className}`.trim()}>
      {children}
    </span>
  )
}
