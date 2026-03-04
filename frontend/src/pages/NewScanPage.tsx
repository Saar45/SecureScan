import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
      setError(err.response?.data?.error || 'Failed to start scan. Please try again.');
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
      setError(err.response?.data?.error || 'Failed to upload and scan. Please try again.');
      setScanning(false);
    }
  };

  return (
    <div className="max-w-2xl mx-auto space-y-8">
      <div>
        <h1 className="text-3xl font-bold text-white">New Scan</h1>
        <p className="text-gray-400 mt-1">Scan a Git repository by URL or upload a ZIP archive of your code</p>
      </div>

      <div className="flex rounded-lg bg-gray-900 border border-gray-800 p-1">
        <button
          type="button"
          onClick={() => { setMode('url'); setError(null); setZipFile(null); }}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors ${mode === 'url' ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white'}`}
        >
          Repository URL
        </button>
        <button
          type="button"
          onClick={() => { setMode('zip'); setError(null); setRepoUrl(''); }}
          className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors ${mode === 'zip' ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white'}`}
        >
          Upload ZIP
        </button>
      </div>

      {mode === 'url' ? (
        <form onSubmit={handleSubmitUrl} className="space-y-6">
          <div className="bg-gray-900 border border-gray-800 rounded-xl p-6 space-y-4">
            <label className="block">
              <span className="text-sm font-medium text-gray-300">Repository URL</span>
              <input
                type="url"
                value={repoUrl}
                onChange={(e) => setRepoUrl(e.target.value)}
                placeholder="https://github.com/owner/repo"
                disabled={scanning}
                className="mt-2 w-full px-4 py-3 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent disabled:opacity-50"
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
            className="w-full flex items-center justify-center gap-3 px-6 py-3.5 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {scanning ? (
              <>
                <div className="animate-spin rounded-full h-5 w-5 border-t-2 border-b-2 border-white" />
                Scanning... This may take a few minutes
              </>
            ) : (
              <>
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                Start Scan
              </>
            )}
          </button>
        </form>
      ) : (
        <form onSubmit={handleSubmitZip} className="space-y-6">
          <div className="bg-gray-900 border border-gray-800 rounded-xl p-6 space-y-4">
            <label className="block">
              <span className="text-sm font-medium text-gray-300">ZIP archive (max 50 MB)</span>
              <input
                type="file"
                accept=".zip"
                onChange={(e) => setZipFile(e.target.files?.[0] ?? null)}
                disabled={scanning}
                className="mt-2 w-full px-4 py-3 bg-gray-800 border border-gray-700 rounded-lg text-white file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-indigo-600 file:text-white file:text-sm file:font-medium disabled:opacity-50"
              />
            </label>
            {zipFile && (
              <p className="text-sm text-gray-500">
                Selected: {zipFile.name} ({(zipFile.size / 1024).toFixed(1)} KB)
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
            className="w-full flex items-center justify-center gap-3 px-6 py-3.5 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {scanning ? (
              <>
                <div className="animate-spin rounded-full h-5 w-5 border-t-2 border-b-2 border-white" />
                Uploading & scanning... This may take a few minutes
              </>
            ) : (
              <>
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 012 2v10a2 2 0 01-2 2z" />
                  <path strokeLinecap="round" strokeLinejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                </svg>
                Upload & Scan
              </>
            )}
          </button>
        </form>
      )}

      <div className="bg-gray-900/50 border border-gray-800 rounded-xl p-6">
        <h3 className="text-sm font-semibold text-gray-300 mb-3">What gets scanned?</h3>
        <ul className="space-y-2 text-sm text-gray-500">
          <li className="flex items-start gap-2">
            <span className="text-indigo-400 mt-0.5">&#x2022;</span>
            <span><strong className="text-gray-400">Semgrep</strong> &mdash; Static analysis for code vulnerabilities (SQL injection, XSS, etc.)</span>
          </li>
          <li className="flex items-start gap-2">
            <span className="text-indigo-400 mt-0.5">&#x2022;</span>
            <span><strong className="text-gray-400">TruffleHog</strong> &mdash; Secret detection (API keys, tokens, passwords)</span>
          </li>
          <li className="flex items-start gap-2">
            <span className="text-indigo-400 mt-0.5">&#x2022;</span>
            <span><strong className="text-gray-400">Dependency Audit</strong> &mdash; npm/composer vulnerability scanning</span>
          </li>
        </ul>
      </div>
    </div>
  );
}
