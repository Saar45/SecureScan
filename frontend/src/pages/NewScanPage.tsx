import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { createScan, uploadScanArchive } from '../api/scans';

type Mode = 'url' | 'zip';

export default function NewScanPage() {
  const [mode, setMode] = useState<Mode>('url');
  const [repoUrl, setRepoUrl] = useState('');
  const [zipFile, setZipFile] = useState<File | null>(null);
  const [scanning, setScanning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const navigate = useNavigate();

  const handleSubmitUrl = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!repoUrl.trim()) return;

    setScanning(true);
    setError(null);

    try {
      const result = await createScan(repoUrl.trim());
      navigate(`/scan/${result.id}`);
    } catch (err: any) {
      setError(err.response?.data?.error || 'Échec du lancement de l\'analyse. Veuillez réessayer.');
      setScanning(false);
    }
  };

  const handleSubmitZip = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!zipFile) return;

    setScanning(true);
    setError(null);

    try {
      const result = await uploadScanArchive(zipFile);
      navigate(`/scan/${result.id}`);
    } catch (err: any) {
      setError(err.response?.data?.error || 'Échec du téléchargement et de l\'analyse. Veuillez réessayer.');
      setScanning(false);
    }
  };

  return (
    <div className="max-w-2xl mx-auto space-y-8">
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
        <h1 className="text-3xl font-bold text-[#eaeff3]">Nouvelle analyse</h1>
        <p className="text-[color:var(--ss-text-muted)] mt-1">Scannez un dépôt Git par URL ou téléchargez une archive ZIP de votre code.</p>
      </div>

      <div className="flex rounded-lg bg-[#0b1a1f]/80 border border-[#1b2836] p-1">
        <button
          type="button"
          onClick={() => { setMode('url'); setError(null); setZipFile(null); }}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors ${
            mode === 'url'
              ? 'bg-[#03e376] text-[#0a0f18] shadow-[0_0_20px_rgba(3,227,118,0.35)]'
              : 'text-[color:var(--ss-text-muted)] hover:text-[color:var(--ss-text-main)]'
          }`}
        >
          URL du dépôt
        </button>
        <button
          type="button"
          onClick={() => { setMode('zip'); setError(null); setRepoUrl(''); }}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors ${
            mode === 'zip'
              ? 'bg-[#03e376] text-[#0a0f18] shadow-[0_0_20px_rgba(3,227,118,0.35)]'
              : 'text-[color:var(--ss-text-muted)] hover:text-[color:var(--ss-text-main)]'
          }`}
        >
          Archive ZIP
        </button>
      </div>

      {mode === 'url' ? (
        <form onSubmit={handleSubmitUrl} className="space-y-6">
          <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-6 space-y-4">
            <label className="block">
              <span className="text-sm font-medium text-[#eaeff3]">URL du dépôt</span>
              <input
                type="url"
                value={repoUrl}
                onChange={(e) => setRepoUrl(e.target.value)}
                placeholder="https://github.com/owner/repo"
                disabled={scanning}
                className="mt-2 w-full px-4 py-3 bg-black/20 border border-[#1b2836] rounded-lg text-[#eaeff3] placeholder-[color:var(--ss-text-muted)] focus:outline-none focus:ring-2 focus:ring-[#03e376] focus:border-transparent disabled:opacity-50"
                required
              />
            </label>

            {error && (
              <div className="bg-red-500/10 border border-red-500/20 rounded-lg p-3 text-sm text-red-400">
                {error}
              </div>
            )}
          </div>

          <button
            type="submit"
            disabled={scanning || !repoUrl.trim()}
            className="w-full flex items-center justify-center gap-3 px-6 py-3.5 bg-[#03e376] text-[#0a0f18] font-semibold rounded-lg hover:bg-[#47e297] disabled:opacity-50 disabled:cursor-not-allowed transition-colors shadow-[0_0_25px_rgba(3,227,118,0.25)]"
          >
            {scanning ? (
              <>
                <div className="animate-spin rounded-full h-5 w-5 border-t-2 border-b-2 border-white" />
                Analyse en cours... Cela peut prendre quelques minutes
              </>
            ) : (
              <>
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                Démarrer l'analyse
              </>
            )}
          </button>
        </form>
      ) : (
        <form onSubmit={handleSubmitZip} className="space-y-6">
          <div className="bg-[#0b1a1f]/80 border border-[#1b2836] rounded-xl p-6 space-y-4">
            <label className="block">
              <span className="text-sm font-medium text-[#eaeff3]">Archive ZIP (max 50 Mo)</span>
              <input
                type="file"
                accept=".zip"
                onChange={(e) => setZipFile(e.target.files?.[0] ?? null)}
                disabled={scanning}
                className="mt-2 w-full px-4 py-3 bg-black/20 border border-[#1b2836] rounded-lg text-[#eaeff3] file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-[#03e376] file:text-[#0a0f18] file:text-sm file:font-medium disabled:opacity-50"
              />
            </label>
            {zipFile && (
              <p className="text-sm text-[color:var(--ss-text-muted)]">
                Sélectionné : {zipFile.name} ({(zipFile.size / 1024).toFixed(1)} Ko)
              </p>
            )}

            {error && (
              <div className="bg-red-500/10 border border-red-500/20 rounded-lg p-3 text-sm text-red-400">
                {error}
              </div>
            )}
          </div>

          <button
            type="submit"
            disabled={scanning || !zipFile}
            className="w-full flex items-center justify-center gap-3 px-6 py-3.5 bg-[#03e376] text-[#0a0f18] font-semibold rounded-lg hover:bg-[#47e297] disabled:opacity-50 disabled:cursor-not-allowed transition-colors shadow-[0_0_25px_rgba(3,227,118,0.25)]"
          >
            {scanning ? (
              <>
                <div className="animate-spin rounded-full h-5 w-5 border-t-2 border-b-2 border-white" />
                Téléchargement et numérisation en cours… Cela peut prendre quelques minutes.
              </>
            ) : (
              <>
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 012 2v10a2 2 0 01-2 2z" />
                  <path strokeLinecap="round" strokeLinejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                </svg>
                Télécharger et analyser
              </>
            )}
          </button>
        </form>
      )}

      <div className="bg-[#0b1a1f]/60 border border-[#1b2836] rounded-xl p-6">
        <h3 className="text-sm font-semibold text-[#eaeff3] mb-3">Qu'est-ce qui est scanné ?</h3>
        <ul className="space-y-2 text-sm text-[color:var(--ss-text-muted)]">
          <li className="flex items-start gap-2">
            <span className="text-[#03e376] mt-0.5">&#x2022;</span>
            <span><strong className="text-[#eaeff3]">Semgrep</strong> &mdash; Analyse statique des vulnérabilités du code (injection SQL, XSS, etc.)</span>
          </li>
          <li className="flex items-start gap-2">
            <span className="text-[#03e376] mt-0.5">&#x2022;</span>
            <span><strong className="text-[#eaeff3]">TruffleHog</strong> &mdash; Détection des secrets (clés API, jetons, mots de passe)</span>
          </li>
          <li className="flex items-start gap-2">
            <span className="text-[#03e376] mt-0.5">&#x2022;</span>
            <span><strong className="text-[#eaeff3]">Audit de dépendances</strong> &mdash; Analyse des vulnérabilités npm/composer</span>
          </li>
        </ul>
      </div>
    </div>
  );
}
