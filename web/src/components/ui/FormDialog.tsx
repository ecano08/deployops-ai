import { useEffect, useId, useRef, type FormEvent, type ReactNode } from 'react'
import { Button } from './Button'

type FormDialogProps = {
  open: boolean
  title: string
  description?: string
  submitLabel?: string
  cancelLabel?: string
  loading?: boolean
  error?: string | null
  size?: 'default' | 'wide'
  children: ReactNode
  onSubmit: (event: FormEvent<HTMLFormElement>) => void
  onCancel: () => void
}

export function FormDialog({
  open,
  title,
  description,
  submitLabel = 'Save',
  cancelLabel = 'Cancel',
  loading = false,
  error,
  size = 'default',
  children,
  onSubmit,
  onCancel,
}: FormDialogProps) {
  const titleId = useId()
  const descriptionId = useId()
  const cancelRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!open) {
      return
    }

    cancelRef.current?.focus()

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        onCancel()
      }
    }

    document.addEventListener('keydown', onKeyDown)

    return () => document.removeEventListener('keydown', onKeyDown)
  }, [open, onCancel])

  if (!open) {
    return null
  }

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onCancel}>
      <div
        className={`dialog dialog--form${size === 'wide' ? ' dialog--wide' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="dialog__header">
          <h2 id={titleId} className="dialog__title">
            {title}
          </h2>
          {description && (
            <p id={descriptionId} className="dialog__description">
              {description}
            </p>
          )}
        </div>

        <form className="form dialog__form" onSubmit={onSubmit} noValidate>
          <div className="dialog__body">
            {children}

            {error && (
              <p className="form__error" role="alert">
                {error}
              </p>
            )}
          </div>

          <div className="dialog__actions">
            <Button
              ref={cancelRef}
              type="button"
              variant="ghost"
              size="sm"
              onClick={onCancel}
              disabled={loading}
            >
              {cancelLabel}
            </Button>
            <Button type="submit" variant="primary" size="sm" loading={loading}>
              {submitLabel}
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
