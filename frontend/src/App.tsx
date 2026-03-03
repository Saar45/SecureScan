import React, { useState, useEffect } from 'react';

function App() {
  const [view, setView] = useState('input');
  const [url, setUrl] = useState('');
  const [showModal, setShowModal] = useState(false);

  useEffect(() => {
    if (view === 'loading') {
      const timer = setTimeout(() => setView('results'), 10000);
      return () => clearTimeout(timer);
    }
  }, [view]);

  const handleStart = () => url && setView('loading');

  return (
    <div className="min-h-screen flex flex-col font-sans bg-[#0a0e14] text-white">
      
      {/* Header avec Sign In à droite */}
      <header className="flex justify-between items-center bg-[#0d1117] border-b border-gray-800 p-4 px-8 text-[10px] tracking-widest text-gray-500 uppercase relative z-10">
        <div className="flex items-center gap-2">
          <span className="w-2 h-2 bg-[#4ade80] rounded-full animate-pulse"></span>
          SecureScan | Hackathon 2026
        </div>
      </header>

      {/* Section Titre */}
      <div className="py-10 flex flex-col items-center">
        <h1 className="text-5xl font-bold tracking-tighter">Secure<span className="text-[#4ade80]">Scan</span></h1>
        <div className="h-1 w-20 bg-[#4ade80] mt-2 rounded-full"></div>
        <p className="text-[#94a3b8] text-lg font-light tracking-wide">
          Plateforme d'Analyse de Qualité & Sécurité de Code
        </p>
      </div>

      <main className="flex-grow flex flex-col items-center px-4">
        
        {/* VUE 1 : INPUT */}
        {view === 'input' && (
          <div className="w-full max-w-xl flex flex-col items-center gap-8 mt-10">
            <input
              type="text"
              placeholder="URL du dépôt GitHub..."
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              className="w-full p-5 bg-[#111827] border border-gray-700 rounded-xl text-center outline-none focus:border-[#4ade80]"
            />
            <button onClick={handleStart} className="bg-[#4ade80] text-[#0a0e14] px-12 py-4 rounded-lg font-black uppercase">
              DÉMARRER LE SCAN
            </button>
          </div>
        )}

        {/* VUE 2 : LOADING */}
        {view === 'loading' && (
          <div className="flex flex-col items-center gap-10 mt-10">
            <div className="w-24 h-24 border-4 border-gray-800 border-t-[#4ade80] rounded-full animate-spin"></div>
            <p className="text-[#4ade80] animate-pulse uppercase tracking-widest">Analyse en cours...</p>
          </div>
        )}

        {/* VUE 3 : RÉSULTATS (Réintégrée ici) */}
        {view === 'results' && (
          <div className="w-full max-w-4xl flex flex-col items-center gap-8 animate-fadeIn pb-10">
            
            {/* Score Global */}
            <div className="flex flex-col items-center gap-2">
              <div className="w-28 h-28 rounded-full border-4 border-[#4ade80] flex items-center justify-center bg-[#4ade80]/10 shadow-[0_0_20px_rgba(74,222,128,0.2)]">
                <span className="text-4xl font-black text-[#4ade80]">85</span>
              </div>
              <p className="text-[10px] font-bold uppercase tracking-widest text-gray-500">Score Global</p>
            </div>

            {/* Zone Graphique */}
            <div className="w-full h-48 bg-[#111827] border border-gray-800 rounded-xl flex items-center justify-center relative overflow-hidden">
               <div className="absolute inset-0 opacity-10 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:24px_24px]"></div>
               <p className="text-gray-600 font-mono uppercase text-xs tracking-[0.5em]">Graphique d'analyse</p>
            </div>

            {/* Liste détaillée avec Filtre */}
            <div className="w-full bg-[#111827] border border-gray-800 rounded-xl overflow-hidden shadow-2xl">
              <div className="bg-[#1f2937] p-4 flex justify-between items-center border-b border-gray-700">
                <h3 className="text-xs font-bold uppercase tracking-widest text-[#4ade80]">Findings Détaillés</h3>
                <button className="bg-[#374151] px-4 py-1.5 rounded text-[10px] hover:bg-[#4ade80] hover:text-[#0a0e14] transition-all uppercase font-black tracking-tighter">
                  Filtre
                </button>
              </div>
              
              {/* Contenu de la liste */}
              <div className="p-10 flex flex-col gap-4">
                <div className="border-l-2 border-[#4ade80] bg-[#0a0e14] p-4 flex justify-between items-center">
                   <span className="text-xs font-mono">SQL Injection vulnerability in /api/user</span>
                   <span className="text-[10px] bg-red-900/30 text-red-500 px-2 py-1 rounded">CRITICAL</span>
                </div>
                <div className="border-l-2 border-yellow-500 bg-[#0a0e14] p-4 flex justify-between items-center opacity-70">
                   <span className="text-xs font-mono">Outdated library: lodash v4.17.15</span>
                   <span className="text-[10px] bg-yellow-900/30 text-yellow-500 px-2 py-1 rounded">MEDIUM</span>
                </div>
              </div>
            </div>

            <button onClick={() => setView('input')} className="text-[#4ade80] text-[10px] uppercase border-b border-[#4ade80] pb-1 hover:text-white transition-all">
              Nouvelle Analyse
            </button>
          </div>
        )}
      </main>

      <footer className="h-16 w-full flex items-center justify-center border-t border-gray-900 bg-[#0d1117] mt-auto">
        <p className="text-[9px] text-gray-600 tracking-[0.8em] uppercase">IPSSI 2026 - Secure Protocol</p>
      </footer>
    </div>
  );
}

export default App;