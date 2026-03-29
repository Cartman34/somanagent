import axios from 'axios'
import { reportApiFailure } from '@/lib/observability'

const apiClient = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 30_000,
})

// Response interceptor: normalize errors
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.code === 'ERR_CANCELED') {
      return Promise.reject(error)
    }

    const requestUrl = typeof error.config?.url === 'string' ? error.config.url : undefined
    const isObservabilityRequest = requestUrl?.includes('/logs/events') === true
    const message =
      error.response?.data?.message ||
      error.response?.data?.error ||
      error.message ||
      'An error occurred'

    if (!isObservabilityRequest) {
      reportApiFailure({
        method: typeof error.config?.method === 'string' ? error.config.method.toUpperCase() : undefined,
        url: requestUrl,
        status: typeof error.response?.status === 'number' ? error.response.status : undefined,
        responseMessage: message,
        requestId: typeof error.response?.headers?.['x-request-id'] === 'string' ? error.response.headers['x-request-id'] : undefined,
      })
    }

    return Promise.reject(new Error(message))
  }
)

export default apiClient
