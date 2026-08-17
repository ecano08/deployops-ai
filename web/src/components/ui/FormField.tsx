import { cloneElement, useId, type ReactElement } from 'react'

type FormFieldChildProps = {
  id?: string
  'aria-invalid'?: boolean
  'aria-describedby'?: string
  className?: string
}

type FormFieldProps = {
  label: string
  error?: string | null
  hideLabel?: boolean
  className?: string
  children: ReactElement<FormFieldChildProps>
}

export function FormField({ label, error, hideLabel = false, className = '', children }: FormFieldProps) {
  const generatedId = useId()
  const fieldId = children.props.id ?? generatedId
  const errorId = `${fieldId}-error`
  const hasError = Boolean(error)

  const control = cloneElement(children, {
    id: fieldId,
    'aria-invalid': hasError || undefined,
    'aria-describedby': hasError ? errorId : undefined,
    className: [children.props.className, hasError ? 'is-invalid' : ''].filter(Boolean).join(' ') || undefined,
  })

  return (
    <div className={`form-field ${hasError ? 'form-field--invalid' : ''} ${className}`.trim()}>
      <label htmlFor={fieldId} className={hideLabel ? 'sr-only' : 'form-field__label'}>
        {label}
      </label>
      {control}
      {hasError && (
        <p id={errorId} className="form-field__error" role="alert">
          {error}
        </p>
      )}
    </div>
  )
}
