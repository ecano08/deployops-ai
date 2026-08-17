import type { AuthResponse, UserResponse, WorkspaceListResponse, WorkspaceResponse } from './types'

const TOKEN_KEY = 'deployops_token'

type ApiError = {
  message?: string
  errors?: Record<string, string[]>
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')

  if (options.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  const token = getToken()

  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`${import.meta.env.VITE_API_URL}${path}`, {
    ...options,
    headers,
  })

  const payload = (await response.json().catch(() => ({}))) as ApiError & T

  if (!response.ok) {
    const firstError = payload.errors
      ? Object.values(payload.errors)[0]?.[0]
      : undefined

    throw new Error(firstError ?? payload.message ?? `HTTP ${response.status}`)
  }

  return payload
}

export function register(name: string, email: string, password: string, passwordConfirmation: string) {
  return request<AuthResponse>('/api/register', {
    method: 'POST',
    body: JSON.stringify({
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    }),
  })
}

export function login(email: string, password: string) {
  return request<AuthResponse>('/api/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
}

export function logout() {
  return request<{ message: string }>('/api/logout', { method: 'POST' })
}

export function fetchCurrentUser() {
  return request<UserResponse>('/api/user')
}

export function fetchWorkspaces() {
  return request<WorkspaceListResponse>('/api/workspaces')
}

export function createWorkspace(name: string) {
  return request<WorkspaceResponse>('/api/workspaces', {
    method: 'POST',
    body: JSON.stringify({ name }),
  })
}
