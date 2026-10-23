'use client'

import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from './api'

interface QueryState<T> {
  data: T | undefined
  error: ApiError | undefined
  loading: boolean
  refresh: () => Promise<void>
}

/** Minimal data-fetching hook: refetches when `key` changes, ignores stale responses. */
export function useQuery<T>(key: string | null, fetcher: () => Promise<T>): QueryState<T> {
  const [data, setData] = useState<T>()
  const [error, setError] = useState<ApiError>()
  const [loading, setLoading] = useState(key !== null)
  const fetcherRef = useRef(fetcher)
  const latest = useRef(0)

  useEffect(() => {
    fetcherRef.current = fetcher
  })

  const run = useCallback(async () => {
    if (key === null) return
    const id = ++latest.current
    setLoading(true)
    try {
      const result = await fetcherRef.current()
      if (id === latest.current) {
        setData(result)
        setError(undefined)
      }
    }
    catch (e) {
      if (id === latest.current) setError(e instanceof ApiError ? e : new ApiError(0, { message: String(e) }))
    }
    finally {
      if (id === latest.current) setLoading(false)
    }
  }, [key])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetching on key change is the purpose of this hook
    void run()
  }, [run])

  return { data, error, loading, refresh: run }
}
