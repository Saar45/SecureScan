import client from './client';

export interface UserInfo {
  id: string;
  username: string;
  avatarUrl: string | null;
  githubId: number;
}

export async function fetchMe(): Promise<UserInfo> {
  const { data } = await client.get<UserInfo>('/auth/me');
  return data;
}

export async function logout(): Promise<void> {
  await client.post('/auth/logout');
}

export interface GitHubRepo {
  id: number;
  name: string;
  fullName: string;
  cloneUrl: string;
  description: string | null;
  language: string | null;
  private: boolean;
  pushedAt: string | null;
}

export async function fetchGitHubRepos(): Promise<GitHubRepo[]> {
  const { data } = await client.get<GitHubRepo[]>('/auth/github/repos');
  return data;
}
