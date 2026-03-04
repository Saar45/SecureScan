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
