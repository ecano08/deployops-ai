export type BadgeVariant =
  | 'default'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'neutral'
  | 'owner'
  | 'admin'
  | 'engineer'
  | 'viewer'

export function statusBadgeVariant(status: string): BadgeVariant {
  const normalized = status.toLowerCase()

  if (['connected', 'ready', 'deployed', 'passed', 'executed', 'resolved', 'ok'].includes(normalized)) {
    return 'success'
  }

  if (['pending', 'investigating', 'disconnected', 'processing'].includes(normalized)) {
    return 'warning'
  }

  if (['error', 'failed', 'rejected', 'critical', 'open'].includes(normalized)) {
    return normalized === 'open' ? 'warning' : 'danger'
  }

  if (['high', 'medium'].includes(normalized)) {
    return normalized === 'high' ? 'danger' : 'warning'
  }

  if (['low'].includes(normalized)) {
    return 'info'
  }

  return 'neutral'
}

export function roleBadgeVariant(role: string): BadgeVariant {
  switch (role.toLowerCase()) {
    case 'owner':
      return 'owner'
    case 'admin':
      return 'admin'
    case 'engineer':
      return 'engineer'
    case 'viewer':
      return 'viewer'
    default:
      return 'neutral'
  }
}
