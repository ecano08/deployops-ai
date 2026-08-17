export class ApiValidationError extends Error {
  fieldErrors: Record<string, string[]>

  constructor(message: string, fieldErrors: Record<string, string[]>) {
    super(message)
    this.name = 'ApiValidationError'
    this.fieldErrors = fieldErrors
  }
}

export function isApiValidationError(error: unknown): error is ApiValidationError {
  return error instanceof ApiValidationError
}

export function fieldError(
  fieldErrors: Record<string, string[]>,
  field: string,
): string | undefined {
  return fieldErrors[field]?.[0]
}
