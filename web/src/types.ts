export type User = {
  id: number
  name: string
  email: string
}

export type Workspace = {
  id: number
  name: string
  slug: string
  owner_id: number
  owner?: User
}

export type AuthResponse = {
  data: User
  token: string
}

export type UserResponse = {
  data: User
}

export type WorkspaceListResponse = {
  data: Workspace[]
}

export type WorkspaceResponse = {
  data: Workspace
}
