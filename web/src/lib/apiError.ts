export class ApiValidationError extends Error {
  fieldErrors: Record<string, string[]>

  constructor(message: string, fieldErrors: Record<string, string[]>) {
    super(message)
    this.name = 'ApiValidationError'
    this.fieldErrors = fieldErrors
  }
}

export class ApiError extends Error {
  reference: string | null

  constructor(message: string, reference: string | null = null) {
    super(message)
    this.name = 'ApiError'
    this.reference = reference
  }
}

export function isApiValidationError(error: unknown): error is ApiValidationError {
  return error instanceof ApiValidationError
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

export function fieldError(
  fieldErrors: Record<string, string[]>,
  field: string,
): string | undefined {
  return fieldErrors[field]?.[0]
}

export function apiErrorReference(error: unknown): string | null {
  if (error instanceof ApiError && error.reference) {
    return error.reference
  }

  return null
}
