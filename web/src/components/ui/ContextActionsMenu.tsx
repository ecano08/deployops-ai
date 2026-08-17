import { useEffect, useId, useRef, useState } from 'react'
import { MoreVertical } from 'lucide-react'
import { Icon } from './Icon'

type ContextActionsMenuItem = {
  label: string
  onSelect: () => void
  destructive?: boolean
  disabled?: boolean
}

type ContextActionsMenuProps = {
  label: string
  items: ContextActionsMenuItem[]
  disabled?: boolean
}

export function ContextActionsMenu({ label, items, disabled = false }: ContextActionsMenuProps) {
  const menuId = useId()
  const rootRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)

  useEffect(() => {
    if (!open) {
      return
    }

    function handlePointerDown(event: MouseEvent) {
      if (!rootRef.current?.contains(event.target as Node)) {
        setOpen(false)
      }
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open])

  return (
    <div className="context-actions-menu" ref={rootRef}>
      <button
        type="button"
        className="context-actions-menu__trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={menuId}
        aria-label={label}
        disabled={disabled}
        onClick={() => setOpen((current) => !current)}
      >
        <Icon icon={MoreVertical} size="xs" />
      </button>

      {open && (
        <div id={menuId} className="context-actions-menu__panel" role="menu" aria-label={label}>
          {items.map((item) => (
            <button
              key={item.label}
              type="button"
              role="menuitem"
              className={`context-actions-menu__item${
                item.destructive ? ' context-actions-menu__item--danger' : ''
              }`}
              disabled={item.disabled}
              onClick={() => {
                setOpen(false)
                item.onSelect()
              }}
            >
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
