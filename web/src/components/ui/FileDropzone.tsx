import { useRef, useState, type ChangeEvent, type DragEvent, type KeyboardEvent } from 'react'
import { FileText, Upload, X } from 'lucide-react'
import { Button } from './Button'
import { Icon } from './Icon'

type FileDropzoneProps = {
  id: string
  accept: string
  value: File | null
  onChange: (file: File | null) => void
  acceptLabel?: string
  disabled?: boolean
  className?: string
  'aria-invalid'?: boolean
  'aria-describedby'?: string
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`
  }

  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function FileDropzone({
  id,
  accept,
  value,
  onChange,
  acceptLabel = 'PDF, TXT, MD',
  disabled = false,
  className = '',
  'aria-invalid': ariaInvalid,
  'aria-describedby': ariaDescribedBy,
}: FileDropzoneProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const dragCounterRef = useRef(0)
  const [isDragOver, setIsDragOver] = useState(false)

  function openFilePicker() {
    if (disabled) {
      return
    }

    inputRef.current?.click()
  }

  function handleInputChange(event: ChangeEvent<HTMLInputElement>) {
    onChange(event.target.files?.[0] ?? null)
    event.target.value = ''
  }

  function handleRemove() {
    onChange(null)

    if (inputRef.current) {
      inputRef.current.value = ''
    }
  }

  function handleDragEnter(event: DragEvent<HTMLDivElement>) {
    event.preventDefault()
    event.stopPropagation()

    if (disabled) {
      return
    }

    dragCounterRef.current += 1
    setIsDragOver(true)
  }

  function handleDragOver(event: DragEvent<HTMLDivElement>) {
    event.preventDefault()
    event.stopPropagation()

    if (disabled) {
      return
    }

    event.dataTransfer.dropEffect = 'copy'
    setIsDragOver(true)
  }

  function handleDragLeave(event: DragEvent<HTMLDivElement>) {
    event.preventDefault()
    event.stopPropagation()

    dragCounterRef.current -= 1

    if (dragCounterRef.current <= 0) {
      dragCounterRef.current = 0
      setIsDragOver(false)
    }
  }

  function handleDrop(event: DragEvent<HTMLDivElement>) {
    event.preventDefault()
    event.stopPropagation()
    dragCounterRef.current = 0
    setIsDragOver(false)

    if (disabled) {
      return
    }

    const droppedFile = event.dataTransfer.files[0]

    if (droppedFile) {
      onChange(droppedFile)
    }
  }

  function handleKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (disabled) {
      return
    }

    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      openFilePicker()
    }
  }

  const dropzoneClassName = [
    'file-dropzone',
    isDragOver ? 'file-dropzone--drag-over' : '',
    disabled ? 'file-dropzone--disabled' : '',
    className,
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <div className="file-dropzone__wrapper">
      <input
        ref={inputRef}
        id={id}
        type="file"
        className="sr-only"
        accept={accept}
        disabled={disabled}
        onChange={handleInputChange}
        tabIndex={-1}
        aria-invalid={ariaInvalid}
        aria-describedby={ariaDescribedBy}
      />

      {value ? (
        <div
          className={`${dropzoneClassName} file-dropzone--selected`}
          onDragEnter={handleDragEnter}
          onDragOver={handleDragOver}
          onDragLeave={handleDragLeave}
          onDrop={handleDrop}
        >
          <div className="file-dropzone__selected">
            <div className="file-dropzone__file">
              <Icon icon={FileText} size="md" className="file-dropzone__file-icon" />
              <div className="file-dropzone__file-meta">
                <span className="file-dropzone__file-name">{value.name}</span>
                <span className="file-dropzone__file-size">{formatBytes(value.size)}</span>
              </div>
            </div>
            <div className="file-dropzone__actions">
              <Button type="button" variant="ghost" size="sm" disabled={disabled} onClick={openFilePicker}>
                Replace
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                disabled={disabled}
                onClick={handleRemove}
                aria-label={`Remove ${value.name}`}
              >
                <Icon icon={X} size="xs" />
                Remove
              </Button>
            </div>
          </div>
        </div>
      ) : (
        <div
          className={dropzoneClassName}
          role="button"
          tabIndex={disabled ? -1 : 0}
          aria-disabled={disabled || undefined}
          aria-describedby={ariaDescribedBy}
          aria-invalid={ariaInvalid}
          onClick={openFilePicker}
          onKeyDown={handleKeyDown}
          onDragEnter={handleDragEnter}
          onDragOver={handleDragOver}
          onDragLeave={handleDragLeave}
          onDrop={handleDrop}
        >
          <Icon icon={Upload} size="lg" className="file-dropzone__icon" />
          <p className="file-dropzone__title">
            <span className="file-dropzone__title-action">Click to browse</span> or drag and drop
          </p>
          <p className="file-dropzone__hint">Accepted formats: {acceptLabel}</p>
        </div>
      )}
    </div>
  )
}
