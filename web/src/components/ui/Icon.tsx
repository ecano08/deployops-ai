import type { LucideIcon } from 'lucide-react'

type IconSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl'

const sizeMap: Record<IconSize, number> = {
  xs: 14,
  sm: 16,
  md: 18,
  lg: 20,
  xl: 24,
}

type IconProps = {
  icon: LucideIcon
  size?: IconSize
  className?: string
  'aria-hidden'?: boolean
}

export function Icon({ icon: LucideComponent, size = 'md', className = '', ...props }: IconProps) {
  return (
    <LucideComponent
      size={sizeMap[size]}
      strokeWidth={1.75}
      className={`icon ${className}`.trim()}
      aria-hidden={props['aria-hidden'] ?? true}
    />
  )
}
