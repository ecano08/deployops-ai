export type AppView =
  | 'dashboard'
  | 'integrations'
  | 'copilot'
  | 'knowledge'
  | 'evals'
  | 'approvals'
  | 'observability'

type NavItem = {
  id: AppView
  label: string
  icon: string
}

const navItems: NavItem[] = [
  { id: 'dashboard', label: 'Dashboard', icon: '◫' },
  { id: 'integrations', label: 'Integrations', icon: '⎔' },
  { id: 'copilot', label: 'Copilot', icon: '✦' },
  { id: 'knowledge', label: 'Knowledge', icon: '▤' },
  { id: 'evals', label: 'Evaluations', icon: '◎' },
  { id: 'approvals', label: 'Approvals', icon: '✓' },
  { id: 'observability', label: 'Observability', icon: '◉' },
]

type SidebarProps = {
  activeView: AppView
  onNavigate: (view: AppView) => void
  pendingApprovals: number
  open: boolean
  onClose: () => void
}

export function Sidebar({ activeView, onNavigate, pendingApprovals, open, onClose }: SidebarProps) {
  return (
    <>
      <div
        className={`sidebar-backdrop ${open ? 'sidebar-backdrop--open' : ''}`}
        onClick={onClose}
        aria-hidden="true"
      />
      <nav className={`sidebar ${open ? 'sidebar--open' : ''}`} aria-label="Main navigation">
        <div className="sidebar__brand">
          <span className="sidebar__logo" aria-hidden="true">
            ◈
          </span>
          <div>
            <p className="sidebar__title">DeployOps AI</p>
            <p className="sidebar__subtitle">FDE Platform</p>
          </div>
        </div>

        <ul className="sidebar__nav">
          {navItems.map((item) => (
            <li key={item.id}>
              <button
                type="button"
                className={`sidebar__link ${activeView === item.id ? 'sidebar__link--active' : ''}`}
                aria-current={activeView === item.id ? 'page' : undefined}
                onClick={() => {
                  onNavigate(item.id)
                  onClose()
                }}
              >
                <span className="sidebar__icon" aria-hidden="true">
                  {item.icon}
                </span>
                <span>{item.label}</span>
                {item.id === 'approvals' && pendingApprovals > 0 && (
                  <span className="sidebar__badge">{pendingApprovals}</span>
                )}
              </button>
            </li>
          ))}
        </ul>
      </nav>
    </>
  )
}
