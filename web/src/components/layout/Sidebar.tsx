import type { LucideIcon } from 'lucide-react'
import {
  Activity,
  BookOpen,
  Bot,
  CheckSquare,
  LayoutDashboard,
  Plug,
  Radar,
  Users,
} from 'lucide-react'
import { Icon } from '../ui/Icon'

export type AppView =
  | 'dashboard'
  | 'team'
  | 'integrations'
  | 'copilot'
  | 'knowledge'
  | 'evals'
  | 'approvals'
  | 'observability'

type NavItem = {
  id: AppView
  label: string
  icon: LucideIcon
}

const navItems: NavItem[] = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { id: 'team', label: 'Team', icon: Users },
  { id: 'integrations', label: 'Integrations', icon: Plug },
  { id: 'copilot', label: 'Copilot', icon: Bot },
  { id: 'knowledge', label: 'Documentation', icon: BookOpen },
  { id: 'evals', label: 'Evaluations', icon: Activity },
  { id: 'approvals', label: 'Approvals', icon: CheckSquare },
  { id: 'observability', label: 'Observability', icon: Radar },
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
            <Icon icon={Radar} size="sm" />
          </span>
          <div>
            <p className="sidebar__title">DeployOps AI</p>
            <p className="sidebar__subtitle">FDE Platform</p>
          </div>
        </div>

        <p className="sidebar__section-label">Platform</p>

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
                  <Icon icon={item.icon} size="sm" />
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
