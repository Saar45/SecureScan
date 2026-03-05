import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useParams } from 'react-router-dom';
import { fetchScan, applyFixes, createScan, type ScanDetail } from '../api/scans';
import { useToast } from '../components/Toast';

const SEVERITY_COLORS: Record<string, string> = {
  CRITICAL: 'bg-red-500/10 text-red-400 border-red-500/20',
  HIGH: 'bg-orange-500/10 text-orange-400 border-orange-500/20',
  MEDIUM: 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20',
  LOW: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
  INFO: 'bg-gray-500/10 text-gray-400 border-gray-500/20',
};

const SEVERITY_ORDER = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO'];

function ScanSkeleton() {
  return (
    <div className="space-y-8">
      <div>
        <div className="skeleton h-4 w-48 mb-3" />
        <div className="skeleton h-9 w-72 mb-2" />
        <div className="skeleton h-4 w-56" />
      </div>
      <div className="grid grid-cols-1 md:grid-cols-6 gap-6">
        <div className="md:col-span-2 bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-6 flex justify-center">
          <div className="skeleton w-32 h-32 rounded-full" />
        </div>
        <div className="md:col-span-4 grid grid-cols-2 sm:grid-cols-5 gap-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-4">
              <div className="skeleton h-8 w-12 mx-auto mb-2" />
              <div className="skeleton h-3 w-16 mx-auto" />
            </div>
          ))}
        </div>
      </div>
      <div className="skeleton h-10 w-full" />
      <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl overflow-hidden">
        {Array.from({ length: 6 }).map((_, i) => (
          <div key={i} className="flex gap-4 px-4 py-3 border-b border-[#1b2836]/60">
            <div className="skeleton h-5 w-20" />
            <div className="skeleton h-5 w-24" />
            <div className="skeleton h-5 w-40" />
            <div className="skeleton h-5 w-20" />
            <div className="skeleton h-5 flex-1" />
          </div>
        ))}
      </div>
    </div>
  );
}

export default function ScanResultsPage() {
  const { id } = useParams<{ id: string }>();
  const { toast } = useToast();
  const [scan, setScan] = useState<ScanDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [filterSeverity, setFilterSeverity] = useState('');
  const [filterTool, setFilterTool] = useState('');
  const [filterOwasp, setFilterOwasp] = useState('');
  const [searchText, setSearchText] = useState('');
  const [sortBy, setSortBy] = useState<'severity' | 'file'>('severity');
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [applyingFixes, setApplyingFixes] = useState(false);
  const [fixResult, setFixResult] = useState<{ branch: string | null; prUrl: string | null } | null>(null);
  const [fixError, setFixError] = useState<string | null>(null);
  const [showFixConfirm, setShowFixConfirm] = useState(false);
  const [retrying, setRetrying] = useState(false);

  useEffect(() => {
    if (!id) return;
    fetchScan(id)
      .then(setScan)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) return <ScanSkeleton />;

  if (!scan) {
    return <p className="text-gray-400">Analyse introuvable.</p>;
  }

  const score = parseFloat(scan.globalScore || '0');
  const scoreColor = score >= 70 ? 'text-green-400' : score >= 40 ? 'text-yellow-400' : 'text-red-400';
  const scoreRingColor = score >= 70 ? 'stroke-green-500' : score >= 40 ? 'stroke-yellow-500' : 'stroke-red-500';

  const tools = [...new Set(scan.findings.map((f) => f.toolSource))];
  const owaspCategories = [...new Set(scan.findings.map((f) => f.owaspCategory).filter(Boolean))] as string[];

  let filtered = scan.findings;
  if (filterSeverity) filtered = filtered.filter((f) => f.severity === filterSeverity);
  if (filterTool) filtered = filtered.filter((f) => f.toolSource === filterTool);
  if (filterOwasp) filtered = filtered.filter((f) => f.owaspCategory === filterOwasp);
  if (searchText) {
    const q = searchText.toLowerCase();
    filtered = filtered.filter(
      (f) =>
        f.filePath.toLowerCase().includes(q) ||
        f.description.toLowerCase().includes(q) ||
        (f.rawCode && f.rawCode.toLowerCase().includes(q)),
    );
  }

  if (sortBy === 'severity') {
    filtered = [...filtered].sort((a, b) => SEVERITY_ORDER.indexOf(a.severity) - SEVERITY_ORDER.indexOf(b.severity));
  } else {
    filtered = [...filtered].sort((a, b) => a.filePath.localeCompare(b.filePath));
  }

  const severityCounts: Record<string, number> = {};
  scan.findings.forEach((f) => {
    severityCounts[f.severity] = (severityCounts[f.severity] || 0) + 1;
  });

  const handleApplyFixes = async () => {
    if (!id) return;
    setShowFixConfirm(false);
    setApplyingFixes(true);
    setFixError(null);
    try {
      const result = await applyFixes(id);
      setFixResult(result);
      toast('Pull Request créée avec succès !', 'success');
    } catch (err: any) {
      const msg = err.response?.data?.error || 'Échec de l\'application des correctifs';
      setFixError(msg);
      toast(msg, 'error');
    } finally {
      setApplyingFixes(false);
    }
  };

  const handleDownloadReport = () => {
    if (!id) return;
    window.open(`/api/scans/${id}/report`, '_blank');
    toast('Téléchargement du rapport en cours...', 'info');
  };

  const handleRetry = async () => {
    if (!scan) return;
    setRetrying(true);
    try {
      const result = await createScan(scan.project.repositoryUrl);
      toast('Nouvelle analyse lancée', 'success');
      window.location.href = `/scan/${result.id}`;
    } catch {
      toast('Échec du lancement de l\'analyse', 'error');
      setRetrying(false);
    }
  };

  const isFailed = scan.status === 'failed';
  const hasSemgrepFindings = scan.findings.some((f) => f.toolSource === 'semgrep');
  const isGitRepo = !scan.project.repositoryUrl.startsWith('upload:');

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <Link
            to="/dashboard"
            className="inline-flex items-center gap-1.5 text-sm text-[color:var(--ss-text-muted)] hover:text-[#03e376] transition-colors mb-3"
          >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
            </svg>
            Retour au tableau de bord
          </Link>
          <h1 className="text-3xl font-bold text-[#eaeff3]">{scan.project.name}</h1>
          <p className="text-[color:var(--ss-text-muted)] mt-1 text-sm">
            {new Date(scan.executedAt).toLocaleString()} &middot; {scan.findings.length} erreurs
          </p>
        </div>
        <div className="flex gap-3">
          {isFailed && (
            <button
              onClick={handleRetry}
              disabled={retrying}
              className="px-4 py-2 bg-orange-500/10 text-orange-400 border border-orange-500/20 rounded-lg hover:bg-orange-500/20 transition-colors text-sm font-medium disabled:opacity-50"
            >
              {retrying ? 'Relance en cours...' : 'Relancer l\'analyse'}
            </button>
          )}
          <button
            onClick={handleDownloadReport}
            className="px-4 py-2 bg-black/20 text-[color:var(--ss-text-main)] border border-[#1b2836] rounded-lg hover:bg-white/5 transition-colors text-sm font-medium disabled:opacity-50"
          >
            Télécharger le PDF
          </button>
          {isGitRepo && !isFailed && hasSemgrepFindings && (
            <button
              onClick={() => setShowFixConfirm(true)}
              disabled={applyingFixes || fixResult !== null}
              className="px-4 py-2 bg-[#03e376] text-[#0a0f18] rounded-lg hover:bg-[#47e297] transition-colors text-sm font-medium disabled:opacity-50 shadow-[0_0_25px_rgba(3,227,118,0.25)]"
            >
              {applyingFixes ? 'Application des correctifs en cours...' : fixResult ? 'Correctifs appliqués' : 'Appliquer les correctifs & Créer PR'}
            </button>
          )}
        </div>
      </div>

      {/* Fix Result */}
      {fixResult && fixResult.prUrl && (
        <div className="bg-[#0b1a1f]/80 border border-[#1f3b33] rounded-xl p-4 flex items-center justify-between">
          <div>
            <p className="text-[#03e376] font-medium">Pull Request créée avec succès !</p>
            {fixResult.branch && (
              <p className="text-[color:var(--ss-text-muted)] text-sm mt-0.5">Branche : {fixResult.branch}</p>
            )}
          </div>
          <a
            href={fixResult.prUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="px-4 py-2 bg-[#03e376] text-[#0a0f18] rounded-lg hover:bg-[#47e297] transition-colors text-sm font-medium"
          >
            Voir la PR sur GitHub
          </a>
        </div>
      )}

      {fixError && (
        <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-4 text-sm text-red-400">
          {fixError}
        </div>
      )}

      {/* No semgrep findings info */}
      {isGitRepo && !isFailed && !hasSemgrepFindings && scan.findings.length > 0 && (
        <div className="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-start gap-3">
          <svg className="w-5 h-5 text-blue-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
          </svg>
          <div>
            <p className="text-blue-400 font-medium text-sm">Correction automatique non disponible</p>
            <p className="text-[color:var(--ss-text-muted)] text-sm mt-0.5">
              Les vulnérabilités détectées proviennent uniquement de {tools.join(', ')}. Seules les vulnérabilités de code source (Semgrep) peuvent être corrigées automatiquement via une Pull Request. Les secrets exposés et les dépendances vulnérables nécessitent une correction manuelle.
            </p>
          </div>
        </div>
      )}

      {/* Failed scan banner */}
      {isFailed && (
        <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-4 flex items-center justify-between">
          <div>
            <p className="text-red-400 font-medium">Cette analyse a échoué</p>
            <p className="text-[color:var(--ss-text-muted)] text-sm mt-0.5">Une erreur est survenue lors du scan. Vous pouvez relancer l'analyse.</p>
          </div>
        </div>
      )}

      {/* Score + Severity Stats */}
      {!isFailed && (
        <>
          <div className="grid grid-cols-1 md:grid-cols-6 gap-6">
            <div className="md:col-span-2 bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-6 flex flex-col items-center justify-center">
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
              <p className="text-[color:var(--ss-text-muted)] text-sm mt-3">Score de Sécurité</p>
            </div>

            <div className="md:col-span-4 grid grid-cols-2 sm:grid-cols-5 gap-3">
              {SEVERITY_ORDER.map((sev) => (
                <div key={sev} className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-4 text-center">
                  <p className={`text-2xl font-bold ${SEVERITY_COLORS[sev]?.split(' ')[1] || 'text-gray-400'}`}>
                    {severityCounts[sev] || 0}
                  </p>
                  <p className="text-xs text-[color:var(--ss-text-muted)] mt-1">{sev}</p>
                </div>
              ))}
            </div>
          </div>

          {/* Filters */}
          <div className="flex flex-wrap gap-3">
            <input
              type="text"
              placeholder="Rechercher (fichier, description)..."
              value={searchText}
              onChange={(e) => setSearchText(e.target.value)}
              className="px-3 py-2 bg-black/20 border border-[#1b2836] rounded-lg text-sm text-[color:var(--ss-text-main)] placeholder-[color:var(--ss-text-muted)] focus:outline-none focus:ring-2 focus:ring-[#03e376] w-64"
            />
            <select
              value={filterSeverity}
              onChange={(e) => setFilterSeverity(e.target.value)}
              className="px-3 py-2 bg-black/20 border border-[#1b2836] rounded-lg text-sm text-[color:var(--ss-text-main)] focus:outline-none focus:ring-2 focus:ring-[#03e376]"
            >
              <option value="">Toutes les sévérités</option>
              {SEVERITY_ORDER.map((s) => (
                <option key={s} value={s}>{s}</option>
              ))}
            </select>

            <select
              value={filterTool}
              onChange={(e) => setFilterTool(e.target.value)}
              className="px-3 py-2 bg-black/20 border border-[#1b2836] rounded-lg text-sm text-[color:var(--ss-text-main)] focus:outline-none focus:ring-2 focus:ring-[#03e376]"
            >
              <option value="">Tous les outils</option>
              {tools.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
            </select>

            <select
              value={filterOwasp}
              onChange={(e) => setFilterOwasp(e.target.value)}
              className="px-3 py-2 bg-black/20 border border-[#1b2836] rounded-lg text-sm text-[color:var(--ss-text-main)] focus:outline-none focus:ring-2 focus:ring-[#03e376]"
            >
              <option value="">Tout OWASP</option>
              {owaspCategories.map((c) => (
                <option key={c} value={c}>{c}</option>
              ))}
            </select>

            <select
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value as 'severity' | 'file')}
              className="px-3 py-2 bg-black/20 border border-[#1b2836] rounded-lg text-sm text-[color:var(--ss-text-main)] focus:outline-none focus:ring-2 focus:ring-[#03e376]"
            >
              <option value="severity">Trier par gravité</option>
              <option value="file">Trier par fichier</option>
            </select>

            <span className="px-3 py-2 text-sm text-[color:var(--ss-text-muted)]">
              {filtered.length} sur {scan.findings.length} erreurs
            </span>
          </div>

          {/* Findings Table */}
          <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl overflow-hidden">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[#1b2836] text-left">
                  <th className="px-4 py-3 text-[color:var(--ss-text-muted)] font-medium">Sévérité</th>
                  <th className="px-4 py-3 text-[color:var(--ss-text-muted)] font-medium">Outil</th>
                  <th className="px-4 py-3 text-[color:var(--ss-text-muted)] font-medium">Fichier</th>
                  <th className="px-4 py-3 text-[color:var(--ss-text-muted)] font-medium">OWASP</th>
                  <th className="px-4 py-3 text-[color:var(--ss-text-muted)] font-medium">Description</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((finding) => (
                  <tr
                    key={finding.id}
                    className="border-b border-[#1b2836]/60 hover:bg-white/5 cursor-pointer"
                    onClick={() => setExpandedId(expandedId === finding.id ? null : finding.id)}
                  >
                    <td className="px-4 py-3 align-top">
                      <span className={`px-2 py-0.5 rounded text-xs font-medium border ${SEVERITY_COLORS[finding.severity] || ''}`}>
                        {finding.severity}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-[color:var(--ss-text-muted)] align-top">{finding.toolSource}</td>
                    <td className="px-4 py-3 text-[#eaeff3] font-mono text-xs align-top">
                      {finding.filePath}
                      {finding.lineNumber && <span className="text-[color:var(--ss-text-muted)]">:{finding.lineNumber}</span>}
                    </td>
                    <td className="px-4 py-3 text-[color:var(--ss-text-muted)] align-top">{finding.owaspCategory || '—'}</td>
                    <td className="px-4 py-3 text-[#eaeff3] align-top">
                      <div className={expandedId === finding.id ? '' : 'max-w-md truncate'}>{finding.description}</div>
                      {expandedId === finding.id && (
                        <div className="mt-3 space-y-3">
                          {finding.rawCode && (
                            <div>
                              <p className="text-xs text-[color:var(--ss-text-muted)] mb-1">Code vulnérable :</p>
                              <pre className="bg-black/30 border border-[#1b2836] rounded-lg p-3 text-xs text-red-300 overflow-x-auto whitespace-pre-wrap">{finding.rawCode}</pre>
                            </div>
                          )}
                          {finding.remediation?.proposedFix && (
                            <div>
                              <p className="text-xs text-[color:var(--ss-text-muted)] mb-1">Correctif proposé :</p>
                              <pre className="bg-black/30 border border-[#1f3b33] rounded-lg p-3 text-xs text-[#03e376] overflow-x-auto whitespace-pre-wrap">{finding.remediation.proposedFix}</pre>
                            </div>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
                {filtered.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-4 py-8 text-center text-gray-500">
                      Aucune vulnérabilité ne correspond aux filtres sélectionnés.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </>
      )}

      {/* Fix Confirmation Modal */}
      {showFixConfirm && createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60" onClick={() => setShowFixConfirm(false)}>
          <div className="bg-[#0f1f28] border border-[#1b2836] rounded-xl p-6 w-full max-w-sm mx-4 shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <h3 className="text-lg font-semibold text-[#eaeff3]">Appliquer les correctifs ?</h3>
            <p className="text-sm text-[color:var(--ss-text-muted)] mt-2">
              Cette action va créer une branche et ouvrir une Pull Request sur GitHub avec les correctifs IA.
            </p>
            <div className="flex gap-3 mt-5">
              <button
                onClick={() => setShowFixConfirm(false)}
                className="flex-1 px-4 py-2 text-sm font-medium text-[color:var(--ss-text-main)] bg-white/5 border border-[#1b2836] rounded-lg hover:bg-white/10 transition-colors"
              >
                Annuler
              </button>
              <button
                onClick={handleApplyFixes}
                className="flex-1 px-4 py-2 text-sm font-medium text-[#0a0f18] bg-[#03e376] rounded-lg hover:bg-[#47e297] transition-colors shadow-[0_0_20px_rgba(3,227,118,0.25)]"
              >
                Confirmer
              </button>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </div>
  );
}
