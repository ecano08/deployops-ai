import { LogOut, Menu } from 'lucide-react'
import { Button } from '../ui/Button'
import { Icon } from '../ui/Icon'

type TopBarProps = {
  userName: string
  userEmail: string
  apiStatus: string | null
  aiStatus: string | null
  onMenuToggle: () => void
  onLogout: () => void
}

function getInitials(name: string): string {
  return name
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
}

export function TopBar({
  userName,
  userEmail,
  apiStatus,
  aiStatus,
  onMenuToggle,
  onLogout,
}: TopBarProps) {
  const apiOk = apiStatus === 'ok'
  const aiOk = aiStatus === 'connected'

  return (
    <header className="topbar">
      <div className="topbar__left">
        <button
          type="button"
          className="topbar__menu"
          onClick={onMenuToggle}
          aria-label="Open navigation menu"
        >
          <Icon icon={Menu} size="sm" />
        </button>

        <div className="topbar__status" aria-label="Service health">
          <span className={`status-pill ${apiOk ? 'status-pill--ok' : 'status-pill--warn'}`}>
            <span className={`status-dot ${apiOk ? 'status-dot--ok' : 'status-dot--warn'}`} />
            API {apiStatus ?? '…'}
          </span>
          <span className={`status-pill ${aiOk ? 'status-pill--ok' : 'status-pill--warn'}`}>
            <span className={`status-dot ${aiOk ? 'status-dot--ok' : 'status-dot--warn'}`} />
            AI {aiStatus ?? '…'}
          </span>
        </div>
      </div>

      <div className="topbar__right">
        <div className="topbar__user">
          <span className="topbar__avatar" aria-hidden="true">
            {getInitials(userName)}
          </span>
          <div className="topbar__user-info">
            <span className="topbar__user-name">{userName}</span>
            <span className="topbar__user-email">{userEmail}</span>
          </div>
        </div>
        <Button variant="ghost" size="sm" onClick={onLogout}>
          <Icon icon={LogOut} size="xs" />
          Log out
        </Button>
      </div>
    </header>
  )
}
