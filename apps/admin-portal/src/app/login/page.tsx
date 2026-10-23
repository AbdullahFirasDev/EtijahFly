'use client'

import { useRouter, useSearchParams } from 'next/navigation'
import { Suspense, useState } from 'react'
import type { FormEvent } from 'react'
import { Alert, Button, Field, Input } from '@/components/ui'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'

function LoginForm() {
  const { t, locale, setLocale } = useI18n()
  const { login } = useAuth()
  const router = useRouter()
  const params = useSearchParams()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      await login(email, password)
      const redirect = params.get('redirect')
      router.replace(redirect && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/')
    }
    catch (err) {
      setError(err instanceof ApiError ? err.message : t('common.error'))
    }
    finally {
      setLoading(false)
    }
  }

  return (
    <div className="relative flex min-h-screen items-center justify-center px-4 py-12">
      <button type="button" className="absolute end-4 top-4 rounded-md px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-200" onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}>
        {locale === 'ar' ? 'English' : 'العربية'}
      </button>
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <h1 className="font-display text-4xl font-semibold leading-tight">{t('app.name')}</h1>
          <div className="mx-auto mt-4 h-0.5 w-12 rounded-full bg-brand-600" aria-hidden />
          <p className="mt-4 text-slate-500">{t('app.subtitle')}</p>
        </div>
        <form onSubmit={submit} className="space-y-5 rounded-[10px] border border-slate-200 bg-white p-6 sm:p-7" data-testid="login-form">
          <h2 className="text-lg font-semibold">{t('auth.title')}</h2>
          {error && <Alert tone="error">{error}</Alert>}
          <Field label={t('auth.email')} required>
            {id => <Input id={id} type="email" dir="ltr" autoComplete="username" required value={email} onChange={e => setEmail(e.target.value)} data-testid="email" />}
          </Field>
          <Field label={t('auth.password')} required>
            {id => <Input id={id} type="password" dir="ltr" autoComplete="current-password" required value={password} onChange={e => setPassword(e.target.value)} data-testid="password" />}
          </Field>
          <Button type="submit" className="w-full" loading={loading} data-testid="login-submit">{t('auth.submit')}</Button>
          <p className="text-center text-xs text-slate-500">{t('auth.staffOnly')}</p>
        </form>
      </div>
    </div>
  )
}

export default function LoginPage() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  )
}
