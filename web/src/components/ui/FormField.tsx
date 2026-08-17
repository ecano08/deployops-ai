import {
  cloneElement,
  isValidElement,
  useId,
  type InputHTMLAttributes,
  type ReactElement,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react'

type FormFieldChildProps = {
  id?: string
  'aria-invalid'?: boolean
  'aria-describedby'?: string
  className?: string
}

type FormFieldProps = {
  label: string
  htmlFor?: string
  error?: string | null
  hint?: string
  hideLabel?: boolean
  className?: string
  children: ReactNode
}

export function FormField({
  label,
  htmlFor,
  error,
  hint,
  hideLabel = false,
  className = '',
  children,
}: FormFieldProps) {
  const generatedId = useId()
  const hasError = Boolean(error)
  const invalidClass = hasError ? 'form-field--invalid form-field--error' : ''

  if (htmlFor) {
    const errorId = `${htmlFor}-error`

    return (
      <div className={`form-field ${invalidClass} ${className}`.trim()}>
        <label htmlFor={htmlFor} className={hideLabel ? 'sr-only' : 'form-field__label'}>
          {label}
        </label>
        {children}
        {hint && <span className="form-field__hint">{hint}</span>}
        {hasError && (
          <p id={errorId} className="form-field__error" role="alert">
            {error}
          </p>
        )}
      </div>
    )
  }

  if (!isValidElement(children)) {
    throw new Error('FormField child must be a single element when htmlFor is not provided.')
  }

  const child = children as ReactElement<FormFieldChildProps>
  const fieldId = child.props.id ?? generatedId
  const errorId = `${fieldId}-error`

  const control = cloneElement(child, {
    id: fieldId,
    'aria-invalid': hasError || undefined,
    'aria-describedby': hasError ? errorId : undefined,
    className: [child.props.className, hasError ? 'is-invalid' : ''].filter(Boolean).join(' ') || undefined,
  })

  return (
    <div className={`form-field ${invalidClass} ${className}`.trim()}>
      <label htmlFor={fieldId} className={hideLabel ? 'sr-only' : 'form-field__label'}>
        {label}
      </label>
      {control}
      {hint && <span className="form-field__hint">{hint}</span>}
      {hasError && (
        <p id={errorId} className="form-field__error" role="alert">
          {error}
        </p>
      )}
    </div>
  )
}

type FormInputProps = InputHTMLAttributes<HTMLInputElement> & {
  id: string
}

export function FormInput({ id, className = '', ...props }: FormInputProps) {
  return <input id={id} className={`form-field__control ${className}`.trim()} {...props} />
}

type FormTextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & {
  id: string
}

export function FormTextarea({ id, className = '', ...props }: FormTextareaProps) {
  return <textarea id={id} className={`form-field__control ${className}`.trim()} {...props} />
}

type FormSelectProps = SelectHTMLAttributes<HTMLSelectElement> & {
  id: string
}

export function FormSelect({ id, className = '', children, ...props }: FormSelectProps) {
  return (
    <select id={id} className={`form-field__control ${className}`.trim()} {...props}>
      {children}
    </select>
  )
}
