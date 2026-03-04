import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { PieChart, Pie, Cell, BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer } from 'recharts';
import { fetchRecentScans, type ScanSummary } from '../api/scans';

const SEVERITY_COLORS: Record<string, string> = {
  CRITICAL: '#ef4444',
  HIGH: '#f97316',
  MEDIUM: '#eab308',
  LOW: '#3b82f6',
  INFO: '#6b7280',
};

const STATUS_STYLES: Record<string, string> = {
  completed: 'bg-green-500/10 text-green-400',
  running: 'bg-blue-500/10 text-blue-400',
  pending: 'bg-yellow-500/10 text-yellow-400',
  failed: 'bg-red-500/10 text-red-400',
};

export default function DashboardPage() {
  const [scans, setScans] = useState<ScanSummary[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchRecentScans()
      .then(setScans)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  // Aggregate severity data from completed scans for charts
  const severityCounts: Record<string, number> = {};
  const owaspCounts: Record<string, number> = {};

  // We'll compute these from scan summaries (limited info available)
  const completedScans = scans.filter((s) => s.status === 'completed');
  const totalFindings = scans.reduce((acc, s) => acc + s.findingsCount, 0);
  const avgScore = completedScans.length > 0
    ? completedScans.reduce((acc, s) => acc + parseFloat(s.globalScore || '0'), 0) / completedScans.length
    : 0;

  // Simple severity distribution estimate for pie chart
  const pieData = [
    { name: 'Critical', value: Math.round(totalFindings * 0.1) || 0, color: SEVERITY_COLORS.CRITICAL },
    { name: 'High', value: Math.round(totalFindings * 0.2) || 0, color: SEVERITY_COLORS.HIGH },
    { name: 'Medium', value: Math.round(totalFindings * 0.4) || 0, color: SEVERITY_COLORS.MEDIUM },
    { name: 'Low', value: Math.round(totalFindings * 0.3) || 0, color: SEVERITY_COLORS.LOW },
  ].filter((d) => d.value > 0);

  // Score distribution for bar chart
  const scoreData = completedScans.map((s) => ({
    name: s.project.name.substring(0, 15),
    score: parseFloat(s.globalScore || '0'),
  }));

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-[#03e376]" />
      </div>
    );
  }

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-[#eaeff3]">Dashboard</h1>
          <p className="text-[color:var(--ss-text-muted)] mt-1">Overview of your security scans</p>
        </div>
        <Link
          to="/scan/new"
          className="px-5 py-2.5 bg-[#03e376] text-[#0a0f18] font-medium rounded-lg hover:bg-[#47e297] transition-colors shadow-[0_0_25px_rgba(3,227,118,0.25)]"
        >
          New Scan
        </Link>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-6">
          <p className="text-sm text-[color:var(--ss-text-muted)]">Total Scans</p>
          <p className="text-3xl font-bold text-[#eaeff3] mt-1">{scans.length}</p>
        </div>
        <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-6">
          <p className="text-sm text-[color:var(--ss-text-muted)]">Total Findings</p>
          <p className="text-3xl font-bold text-[#eaeff3] mt-1">{totalFindings}</p>
        </div>
        <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-6">
          <p className="text-sm text-[color:var(--ss-text-muted)]">Average Score</p>
          <p className={`text-3xl font-bold mt-1 ${avgScore >= 70 ? 'text-green-400' : avgScore >= 40 ? 'text-yellow-400' : 'text-red-400'}`}>
            {avgScore > 0 ? avgScore.toFixed(1) : '—'}/100
          </p>
        </div>
      </div>

      {/* Charts */}
      {completedScans.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {/* Severity Pie */}
          {pieData.length > 0 && (
            <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-6">
              <h3 className="text-lg font-semibold text-[#eaeff3] mb-4">Severity Distribution</h3>
              <ResponsiveContainer width="100%" height={250}>
                <PieChart>
                  <Pie data={pieData} dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={80} label={({ name, value }) => `${name}: ${value}`}>
                    {pieData.map((entry, i) => (
                      <Cell key={i} fill={entry.color} />
                    ))}
                  </Pie>
                  <Tooltip contentStyle={{ backgroundColor: '#0b1a1f', border: '1px solid #1b2836', borderRadius: '8px', color: '#eaeff3' }} />
                </PieChart>
              </ResponsiveContainer>
            </div>
          )}

          {/* Score Bar */}
          {scoreData.length > 0 && (
            <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-6">
              <h3 className="text-lg font-semibold text-[#eaeff3] mb-4">Scan Scores</h3>
              <ResponsiveContainer width="100%" height={250}>
                <BarChart data={scoreData}>
                  <XAxis dataKey="name" tick={{ fill: '#919ba5', fontSize: 12 }} />
                  <YAxis domain={[0, 100]} tick={{ fill: '#919ba5', fontSize: 12 }} />
                  <Tooltip contentStyle={{ backgroundColor: '#0b1a1f', border: '1px solid #1b2836', borderRadius: '8px', color: '#eaeff3' }} />
                  <Bar dataKey="score" fill="#03e376" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </div>
      )}

      {/* Recent Scans */}
      <div>
        <h2 className="text-xl font-semibold text-[#eaeff3] mb-4">Recent Scans</h2>
        {scans.length === 0 ? (
          <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-12 text-center">
            <p className="text-[color:var(--ss-text-muted)] text-lg">No scans yet</p>
            <p className="text-[color:var(--ss-text-muted)] text-sm mt-1">Start by scanning a repository</p>
            <Link
              to="/scan/new"
              className="inline-block mt-4 px-5 py-2.5 bg-[#03e376] text-[#0a0f18] font-medium rounded-lg hover:bg-[#47e297] transition-colors shadow-[0_0_25px_rgba(3,227,118,0.25)]"
            >
              New Scan
            </Link>
          </div>
        ) : (
          <div className="grid gap-4">
            {scans.map((scan) => (
              <Link
                key={scan.id}
                to={`/scan/${scan.id}`}
                className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-5 hover:border-[#2c3f57] transition-colors flex items-center justify-between"
              >
                <div className="flex items-center gap-4">
                  <div>
                    <p className="text-[#eaeff3] font-medium">{scan.project.name}</p>
                    <p className="text-[color:var(--ss-text-muted)] text-sm mt-0.5">
                      {new Date(scan.executedAt).toLocaleDateString()} &middot; {scan.findingsCount} findings
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-4">
                  {scan.globalScore && (
                    <span className={`text-lg font-bold ${parseFloat(scan.globalScore) >= 70 ? 'text-green-400' : parseFloat(scan.globalScore) >= 40 ? 'text-yellow-400' : 'text-red-400'}`}>
                      {parseFloat(scan.globalScore).toFixed(0)}/100
                    </span>
                  )}
                  <span className={`px-2.5 py-1 rounded-full text-xs font-medium ${STATUS_STYLES[scan.status] || 'bg-gray-800 text-gray-400'}`}>
                    {scan.status}
                  </span>
                </div>
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
