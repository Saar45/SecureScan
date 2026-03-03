import client from './client';

export interface ScanSummary {
  id: string;
  project: {
    id: string;
    name: string;
    repositoryUrl: string;
  };
  executedAt: string;
  globalScore: string | null;
  status: string;
  findingsCount: number;
}

export interface Finding {
  id: string;
  toolSource: string;
  severity: string;
  owaspCategory: string | null;
  filePath: string;
  lineNumber: number | null;
  description: string;
  rawCode: string | null;
  remediation: {
    proposedFix: string | null;
    status: string;
    gitBranchName: string | null;
    prUrl: string | null;
  } | null;
}

export interface ScanDetail {
  id: string;
  project: {
    id: string;
    name: string;
    repositoryUrl: string;
  };
  executedAt: string;
  globalScore: string | null;
  status: string;
  findings: Finding[];
}

export interface ApplyFixesResult {
  branch: string | null;
  prUrl: string | null;
}

export async function createScan(repositoryUrl: string): Promise<{ id: string; projectId: string; status: string; globalScore: string | null; findingsCount: number }> {
  const { data } = await client.post('/scans', { repositoryUrl });
  return data;
}

export async function fetchScan(id: string): Promise<ScanDetail> {
  const { data } = await client.get<ScanDetail>(`/scans/${id}`);
  return data;
}

export async function fetchRecentScans(): Promise<ScanSummary[]> {
  const { data } = await client.get<ScanSummary[]>('/scans/recent');
  return data;
}

export async function applyFixes(scanId: string): Promise<ApplyFixesResult> {
  const { data } = await client.post<ApplyFixesResult>(`/scans/${scanId}/apply-fixes`);
  return data;
}

export async function fetchReport(scanId: string): Promise<{ url: string }> {
  const { data } = await client.get<{ url: string }>(`/scans/${scanId}/report`);
  return data;
}
