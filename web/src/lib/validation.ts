export function required(value: string, label = 'This field'): string | null {
  return value.trim() ? null : `${label} is required`
}

export function email(value: string): string | null {
  const trimmed = value.trim()

  if (!trimmed) {
    return 'Email is required'
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
    return 'Enter a valid email address'
  }

  return null
}

export function minLength(value: string, min: number, label = 'Password'): string | null {
  if (!value) {
    return `${label} is required`
  }

  if (value.length < min) {
    return `${label} must be at least ${min} characters`
  }

  return null
}

export function matches(value: string, other: string, message = 'Passwords do not match'): string | null {
  return value === other ? null : message
}
