import type { ReactNode } from 'react'
import { Link, Outlet } from 'react-router-dom'
import { useMe } from '../api/queries'
import { ApiError } from '../api/client'


export function AdminDenied() {
  return (
    <section className="panel warn" role="alert">
      <h1>Admin access required</h1>
      <p>Your account is not an administrator. Ask an admin to promote it, or go back to the <Link to="/">overview</Link>.</p>
    </section>
  )
}


export function AdminError({ error, what }: { error: unknown; what: string }) {
  if (error instanceof ApiError && error.status === 403) return <AdminDenied />
  return <p role="alert">Could not load {what}.</p>
}


export function AdminGuard({ children }: { children?: ReactNode }) {
  const me = useMe()
  if (me.isPending) return <p>Loading…</p>
  if (me.isError) return <p role="alert">Could not check your account.</p>
  if (!me.data.data.admin) return <AdminDenied />
  return children ?? <Outlet />
}
