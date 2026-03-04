import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import SidebarRepos from './SidebarRepos';

const navItems = [
  { path: '/dashboard', label: 'Dashboard', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1' },
  { path: '/scan/new', label: 'Nouvelle analyse', icon: 'M12 4v16m8-8H4' },
];

export default function Layout({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  const location = useLocation();

  return (
    <div className="min-h-screen app-bg flex text-[color:var(--ss-text-main)]">
      {/* Sidebar */}
      <aside className="w-64 bg-black/20 border-r border-[#1b2836] flex flex-col backdrop-blur-xl">
        <div className="p-6 border-b border-[#1b2836]">
          <Link to="/dashboard" className="text-2xl font-bold tracking-tight">
            <span className="text-[#eaeff3]">Secure</span>
            <span className="text-[#03e376]">Scan</span>
          </Link>
        </div>

        <nav className="px-4 py-4 space-y-1">
          {navItems.map((item) => {
            const active = location.pathname === item.path;
            return (
              <Link
                key={item.path}
                to={item.path}
                className={`flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors border border-transparent ${
                  active
                    ? 'bg-[#0b1a1f] text-[#03e376] border-[#1f3b33] shadow-[0_0_25px_rgba(3,227,118,0.25)]'
                    : 'text-[color:var(--ss-text-muted)] hover:text-[color:var(--ss-text-main)] hover:bg-white/5 hover:border-white/5'
                }`}
              >
                <svg className="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                  <path strokeLinecap="round" strokeLinejoin="round" d={item.icon} />
                </svg>
                {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="flex-1 min-h-0 border-t border-[#1b2836]">
          <SidebarRepos />
        </div>

        {/* User */}
        {user && (
          <div className="p-4 border-t border-[#1b2836]">
            <div className="flex items-center gap-3">
              {user.avatarUrl ? (
                <img src={user.avatarUrl} alt="" className="w-8 h-8 rounded-full" />
              ) : (
                <div className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-sm text-[#eaeff3] font-medium">
                  {user.username[0]?.toUpperCase()}
                </div>
              )}
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-[#eaeff3] truncate">{user.username}</p>
              </div>
              <button
                onClick={logout}
                className="text-[color:var(--ss-text-muted)] hover:text-red-400 transition-colors"
                title="Logout"
              >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3-3h-9m9 0l-3-3m3 3l-3 3" />
                </svg>
              </button>
            </div>
          </div>
        )}
      </aside>

      {/* Main content */}
      <main className="flex-1 overflow-auto">
        <div className="p-8">
          {children}
        </div>
      </main>
    </div>
  );
}
