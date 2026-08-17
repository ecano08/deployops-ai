import { Button } from '../ui/Button'

type TopBarProps = {
  userName: string
  userEmail: string
  apiStatus: string | null
  aiStatus: string | null
  onMenuToggle: () => void
  onLogout: () => void
}

export function TopBar({
  userName,
  userEmail,
  apiStatus,
  aiStatus,
  onMenuToggle,
  onLogout,
}: TopBarProps) {
  return (
    <header className="topbar">
      <div className="topbar__left">
        <button
          type="button"
          className="topbar__menu"
          onClick={onMenuToggle}
          aria-label="Open navigation menu"
        >
          ☰
        </button>
        <div className="topbar__status" aria-label="Service health">
          <span className={`status-dot ${apiStatus === 'ok' ? 'status-dot--ok' : 'status-dot--warn'}`} />
          <span className="topbar__status-label">API {apiStatus ?? '…'}</span>
          <span className={`status-dot ${aiStatus === 'connected' ? 'status-dot--ok' : 'status-dot--warn'}`} />
          <span className="topbar__status-label">AI {aiStatus ?? '…'}</span>
        </div>
      </div>

      <div className="topbar__right">
        <div className="topbar__user">
          <span className="topbar__user-name">{userName}</span>
          <span className="topbar__user-email">{userEmail}</span>
        </div>
        <Button variant="ghost" size="sm" onClick={onLogout}>
          Log out
        </Button>
      </div>
    </header>
  )
}
