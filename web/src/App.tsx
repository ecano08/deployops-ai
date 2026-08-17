import { useEffect, useState } from 'react'

type HealthResponse = {
  status: string
  ai_service: string
  details: {
    status: string
    service: string
  }
}

function App() {
  const [health, setHealth] = useState<HealthResponse | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetch(`${import.meta.env.VITE_API_URL}/api/health/ai`)
      .then((response) => {
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`)
        }

        return response.json()
      })
      .then(setHealth)
      .catch((error: Error) => setError(error.message))
  }, [])

  return (
    <main>
      <h1>DeployOps AI</h1>

      {!health && !error && <p>Checking services...</p>}

      {error && <p>Error: {error}</p>}

      {health && (
        <>
          <p>Laravel API: {health.status}</p>
          <p>AI Service: {health.details.status}</p>
        </>
      )}
    </main>
  )
}

export default App