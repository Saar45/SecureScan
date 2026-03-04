import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { fetchGitHubRepos, type GitHubRepo } from '../api/auth';
import { createScan } from '../api/scans';

export default function SidebarRepos() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [showAll, setShowAll] = useState(false);
  const [scanningId, setScanningId] = useState<number | null>(null);

  const { data: repos, isError } = useQuery({
    queryKey: ['github-repos'],
    queryFn: fetchGitHubRepos,
    staleTime: 5 * 60 * 1000,
    retry: 1,
  });

  if (isError || !repos || repos.length === 0) return null;

  const filtered = search
    ? repos.filter((r) => r.name.toLowerCase().includes(search.toLowerCase()))
    : repos;

  const visible = showAll ? filtered : filtered.slice(0, 10);
  const hasMore = filtered.length > 10;

  async function handleScan(repo: GitHubRepo) {
    if (scanningId) return;
    setScanningId(repo.id);
    try {
      const result = await createScan(repo.cloneUrl);
      setScanningId(null);
      navigate(`/scan/${result.id}`);
    } catch {
      setScanningId(null);
    }
  }

  return (
    <div className="flex flex-col h-full">
      <div className="px-4 pt-3 pb-2">
        <h3 className="text-xs font-semibold uppercase tracking-wider text-[color:var(--ss-text-muted)]">
          Repositories
        </h3>
      </div>

      {repos.length > 5 && (
        <div className="px-4 pb-2">
          <input
            type="text"
            placeholder="Search repos..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full px-2.5 py-1.5 text-xs rounded-md bg-white/5 border border-[#1b2836] text-[#eaeff3] placeholder-[color:var(--ss-text-muted)] focus:outline-none focus:border-[#03e376]/50"
          />
        </div>
      )}

      <div className="flex-1 overflow-y-auto px-2 space-y-0.5">
        {visible.map((repo) => (
          <button
            key={repo.id}
            onClick={() => handleScan(repo)}
            disabled={scanningId !== null}
            className="w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-left text-xs transition-colors hover:bg-white/5 disabled:opacity-50 group"
          >
            {scanningId === repo.id ? (
              <svg className="w-3.5 h-3.5 shrink-0 animate-spin text-[#03e376]" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
            ) : repo.private ? (
              <svg className="w-3.5 h-3.5 shrink-0 text-[color:var(--ss-text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
              </svg>
            ) : (
              <svg className="w-3.5 h-3.5 shrink-0 text-[color:var(--ss-text-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776" />
              </svg>
            )}
            <span className="truncate text-[color:var(--ss-text-muted)] group-hover:text-[#eaeff3]">
              {repo.name}
            </span>
            {repo.language && (
              <span className="ml-auto text-[10px] text-[color:var(--ss-text-muted)] shrink-0">
                {repo.language}
              </span>
            )}
          </button>
        ))}

        {filtered.length === 0 && search && (
          <p className="px-2 py-2 text-xs text-[color:var(--ss-text-muted)]">No repos found</p>
        )}
      </div>

      {hasMore && !search && (
        <button
          onClick={() => setShowAll(!showAll)}
          className="px-4 py-2 text-xs text-[#03e376] hover:text-[#03e376]/80 transition-colors"
        >
          {showAll ? 'Show less' : `Show all (${repos.length})`}
        </button>
      )}
    </div>
  );
}
