import client from './client';

export interface ProjectSummary {
  id: string;
  name: string;
  repositoryUrl: string;
  mainBranch: string;
  createdAt: string;
  scanCount: number;
}

export interface ProjectDetail extends Omit<ProjectSummary, 'scanCount'> {
  scans: {
    id: string;
    executedAt: string;
    globalScore: string | null;
    status: string;
    findingsCount: number;
  }[];
}

export async function fetchProjects(): Promise<ProjectSummary[]> {
  const { data } = await client.get<ProjectSummary[]>('/projects');
  return data;
}

export async function fetchProject(id: string): Promise<ProjectDetail> {
  const { data } = await client.get<ProjectDetail>(`/projects/${id}`);
  return data;
}

export async function createProject(name: string, repositoryUrl: string): Promise<ProjectSummary> {
  const { data } = await client.post<ProjectSummary>('/projects', { name, repositoryUrl });
  return data;
}
