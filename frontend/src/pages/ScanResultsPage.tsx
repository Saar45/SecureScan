import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { fetchScan, applyFixes, fetchReport, type ScanDetail, type Finding } from '../api/scans';

const SEVERITY_COLORS: Record<string, string> = {
  CRITICAL: 'bg-red-500/10 text-red-400 border-red-500/20',
  HIGH: 'bg-orange-500/10 text-orange-400 border-orange-500/20',
  MEDIUM: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
  LOW: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
  INFO: 'bg-gray-500/10 text-gray-400 border-gray-500/20',
};

const SEVERITY_ORDER = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO'];

export default function ScanResultsPage() {
  const { id } = useParams<{ id: string }>();
  const [scan, setScan] = useState<ScanDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [filterSeverity, setFilterSeverity] = useState('');
  const [filterTool, setFilterTool] = useState('');
  const [filterOwasp, setFilterOwasp] = useState('');
  const [sortBy, setSortBy] = useState<'severity' | 'file'>('severity');
  const [applyingFixes, setApplyingFixes] = useState(false);
  const [fixResult, setFixResult] = useState<{ branch: string | null; prUrl: string | null } | null>(null);
  const [fixError, setFixError] = useState<string | null>(null);
  const [downloadingReport, setDownloadingReport] = useState(false);

  useEffect(() => {
    if (!id) return;
    fetchScan(id)
      .then(setScan)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-indigo-500" />
      </div>
    );
  }

  if (!scan) {
    return <p className="text-gray-400">Scan not found.</p>;
  }

  const score = parseFloat(scan.globalScore || '0');
  const scoreColor = score >= 70 ? 'text-green-400' : score >= 40 ? 'text-yellow-400' : 'text-red-400';
  const scoreRingColor = score >= 70 ? 'stroke-green-500' : score >= 40 ? 'stroke-yellow-500' : 'stroke-red-500';

  // Get unique tools and owasp categories for filters
  const tools = [...new Set(scan.findings.map((f) => f.toolSource))];
  const owaspCategories = [...new Set(scan.findings.map((f) => f.owaspCategory).filter(Boolean))] as string[];

  // Filter & sort findings
  let filtered = scan.findings;
  if (filterSeverity) filtered = filtered.filter((f) => f.severity === filterSeverity);
  if (filterTool) filtered = filtered.filter((f) => f.toolSource === filterTool);
  if (filterOwasp) filtered = filtered.filter((f) => f.owaspCategory === filterOwasp);

  if (sortBy === 'severity') {
    filtered = [...filtered].sort((a, b) => SEVERITY_ORDER.indexOf(a.severity) - SEVERITY_ORDER.indexOf(b.severity));
  } else {
    filtered = [...filtered].sort((a, b) => a.filePath.localeCompare(b.filePath));
  }

  // Severity counts
  const severityCounts: Record<string, number> = {};
  scan.findings.forEach((f) => {
    severityCounts[f.severity] = (severityCounts[f.severity] || 0) + 1;
  });

  const handleApplyFixes = async () => {
    if (!id) return;
    setApplyingFixes(true);
    setFixError(null);
    try {
      const result = await applyFixes(id);
      setFixResult(result);
    } catch (err: any) {
      setFixError(err.response?.data?.error || 'Failed to apply fixes');
    } finally {
      setApplyingFixes(false);
    }
  };

  const handleDownloadReport = () => {
    if (!id) return;
    window.open(`/api/scans/${id}/report`, '_blank');
  };

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-3xl font-bold text-white">{scan.project.name}</h1>
          <p className="text-gray-500 mt-1 text-sm">
            {new Date(scan.executedAt).toLocaleString()} &middot; {scan.findings.length} findings
          </p>
        </div>
        <div className="flex gap-3">
          <button
            onClick={handleDownloadReport}
            disabled={downloadingReport}
            className="px-4 py-2 bg-gray-800 text-gray-300 border border-gray-700 rounded-lg hover:bg-gray-700 transition-colors text-sm font-medium disabled:opacity-50"
          >
            {downloadingReport ? 'Generating...' : 'Download PDF'}
          </button>
          {!scan.project.repositoryUrl.startsWith('upload:') && (
            <button
              onClick={handleApplyFixes}
              disabled={applyingFixes || fixResult !== null}
              className="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-500 transition-colors text-sm font-medium disabled:opacity-50"
            >
              {applyingFixes ? 'Applying...' : fixResult ? 'Fixes Applied' : 'Apply Fixes & Create PR'}
            </button>
          )}
        </div>
      </div>

      {/* Fix Result */}
      {fixResult && fixResult.prUrl && (
        <div className="bg-green-500/10 border border-green-500/20 rounded-xl p-4 flex items-center justify-between">
          <div>
            <p className="text-green-400 font-medium">Pull Request created successfully!</p>
            {fixResult.branch && (
              <p className="text-green-500/70 text-sm mt-0.5">Branch: {fixResult.branch}</p>
            )}
          </div>
          <a
            href={fixResult.prUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-500 transition-colors text-sm font-medium"
          >
            View PR on GitHub
          </a>
        </div>
      )}

      {fixError && (
        <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-4 text-sm text-red-400">
          {fixError}
        </div>
      )}

      {/* Score + Severity Stats */}
      <div className="grid grid-cols-1 md:grid-cols-6 gap-6">
        {/* Score Gauge */}
        <div className="md:col-span-2 bg-gray-900 border border-gray-800 rounded-xl p-6 flex flex-col items-center justify-center">
          <div className="relative w-32 h-32">
            <svg className="w-32 h-32 -rotate-90" viewBox="0 0 120 120">
              <circle cx="60" cy="60" r="54" fill="none" stroke="#1f2937" strokeWidth="8" />
              <circle
                cx="60" cy="60" r="54" fill="none"
                className={scoreRingColor}
                strokeWidth="8"
                strokeLinecap="round"
                strokeDasharray={`${(score / 100) * 339.3} 339.3`}
              />
            </svg>
            <div className="absolute inset-0 flex items-center justify-center">
              <span className={`text-3xl font-bold ${scoreColor}`}>{score.toFixed(0)}</span>
            </div>
          </div>
          <p className="text-gray-500 text-sm mt-3">Security Score</p>
        </div>

        {/* Severity Breakdown */}
        <div className="md:col-span-4 grid grid-cols-2 sm:grid-cols-5 gap-3">
          {SEVERITY_ORDER.map((sev) => (
            <div key={sev} className="bg-gray-900 border border-gray-800 rounded-xl p-4 text-center">
              <p className={`text-2xl font-bold ${SEVERITY_COLORS[sev]?.split(' ')[1] || 'text-gray-400'}`}>
                {severityCounts[sev] || 0}
              </p>
              <p className="text-xs text-gray-500 mt-1">{sev}</p>
            </div>
          ))}
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3">
        <select
          value={filterSeverity}
          onChange={(e) => setFilterSeverity(e.target.value)}
          className="px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="">All Severities</option>
          {SEVERITY_ORDER.map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </select>

        <select
          value={filterTool}
          onChange={(e) => setFilterTool(e.target.value)}
          className="px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="">All Tools</option>
          {tools.map((t) => (
            <option key={t} value={t}>{t}</option>
          ))}
        </select>

        <select
          value={filterOwasp}
          onChange={(e) => setFilterOwasp(e.target.value)}
          className="px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="">All OWASP</option>
          {owaspCategories.map((c) => (
            <option key={c} value={c}>{c}</option>
          ))}
        </select>

        <select
          value={sortBy}
          onChange={(e) => setSortBy(e.target.value as 'severity' | 'file')}
          className="px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="severity">Sort by Severity</option>
          <option value="file">Sort by File</option>
        </select>

        <span className="px-3 py-2 text-sm text-gray-500">
          {filtered.length} of {scan.findings.length} findings
        </span>
      </div>

      {/* Findings Table */}
      <div className="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-gray-800 text-left">
              <th className="px-4 py-3 text-gray-400 font-medium">Severity</th>
              <th className="px-4 py-3 text-gray-400 font-medium">Tool</th>
              <th className="px-4 py-3 text-gray-400 font-medium">File</th>
              <th className="px-4 py-3 text-gray-400 font-medium">OWASP</th>
              <th className="px-4 py-3 text-gray-400 font-medium">Description</th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((finding) => (
              <tr key={finding.id} className="border-b border-gray-800/50 hover:bg-gray-800/30">
                <td className="px-4 py-3">
                  <span className={`px-2 py-0.5 rounded text-xs font-medium border ${SEVERITY_COLORS[finding.severity] || ''}`}>
                    {finding.severity}
                  </span>
                </td>
                <td className="px-4 py-3 text-gray-400">{finding.toolSource}</td>
                <td className="px-4 py-3 text-gray-300 font-mono text-xs">
                  {finding.filePath}
                  {finding.lineNumber && <span className="text-gray-500">:{finding.lineNumber}</span>}
                </td>
                <td className="px-4 py-3 text-gray-400">{finding.owaspCategory || '—'}</td>
                <td className="px-4 py-3 text-gray-300 max-w-md truncate">{finding.description}</td>
              </tr>
            ))}
            {filtered.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-gray-500">
                  No findings match the selected filters.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
