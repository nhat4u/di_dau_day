import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const accessToken = localStorage.getItem('accessToken')

  if (accessToken) {
    config.headers.Authorization = `Bearer ${accessToken}`
  }

  return config
})

export function getApiErrorMessage(
  error,
  defaultMessage = 'Đã xảy ra lỗi. Vui lòng thử lại.',
) {
  const responseData = error.response?.data

  if (responseData?.message) {
    return responseData.message
  }

  if (responseData?.errors) {
    return Object.values(responseData.errors).flat().join(' ')
  }

  return defaultMessage
}

export default api