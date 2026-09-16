import { useState } from 'react'
import {
  Eye,
  EyeOff,
  LockKeyhole,
  LogIn,
  Mail,
} from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import AuthLayout from '../layouts/AuthLayout'
import api, { getApiErrorMessage } from '../services/api'

function LoginPage() {
  const navigate = useNavigate()
  const rememberedEmail = localStorage.getItem('rememberedEmail') || ''

  const [form, setForm] = useState({
    email: rememberedEmail,
    password: '',
  })

  const [remember, setRemember] = useState(Boolean(rememberedEmail))
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  function handleChange(event) {
    const { name, value } = event.target

    setForm((currentForm) => ({
      ...currentForm,
      [name]: value,
    }))

    setError('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')
    setSuccess('')

    if (!form.email.trim() || !form.password) {
      setError('Vui lòng nhập đầy đủ email và mật khẩu.')
      return
    }

    try {
      setLoading(true)

      const response = await api.post('/auth/login', {
        email: form.email.trim(),
        password: form.password,
      })

      const { accessToken, user } = response.data

      if (!accessToken || !user) {
        throw new Error('Phản hồi đăng nhập không hợp lệ.')
      }

      localStorage.setItem('accessToken', accessToken)
      localStorage.setItem('authUser', JSON.stringify(user))

      if (remember) {
        localStorage.setItem('rememberedEmail', form.email.trim())
      } else {
        localStorage.removeItem('rememberedEmail')
      }

      setSuccess(`Đăng nhập thành công. Xin chào ${user.fullName}!`)

      setTimeout(() => {
        navigate('/')
      }, 700)
    } catch (requestError) {
      setError(
        getApiErrorMessage(
          requestError,
          'Đăng nhập thất bại. Vui lòng kiểm tra lại email và mật khẩu.',
        ),
      )
    } finally {
      setLoading(false)
    }
  }

  return (
    <AuthLayout
      eyebrow="Chào mừng trở lại"
      title="Đăng nhập tài khoản"
      description="Tiếp tục hành trình khám phá những homestay tuyệt vời."
    >
      <form className="auth-form" onSubmit={handleSubmit}>
        {error && (
          <div className="auth-alert auth-alert-error">
            {error}
          </div>
        )}

        {success && (
          <div className="auth-alert auth-alert-success">
            {success}
          </div>
        )}

        <div className="form-group">
          <label htmlFor="login-email">Email</label>

          <div className="input-wrapper">
            <Mail className="input-icon" size={19} />

            <input
              id="login-email"
              name="email"
              type="email"
              value={form.email}
              onChange={handleChange}
              placeholder="Nhập địa chỉ email"
              autoComplete="email"
              disabled={loading}
            />
          </div>
        </div>

        <div className="form-group">
          <label htmlFor="login-password">Mật khẩu</label>

          <div className="input-wrapper">
            <LockKeyhole className="input-icon" size={19} />

            <input
              id="login-password"
              name="password"
              type={showPassword ? 'text' : 'password'}
              value={form.password}
              onChange={handleChange}
              placeholder="Nhập mật khẩu"
              autoComplete="current-password"
              disabled={loading}
            />

            <button
              className="password-toggle"
              type="button"
              aria-label={showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
              onClick={() => setShowPassword((current) => !current)}
            >
              {showPassword ? (
                <EyeOff size={19} />
              ) : (
                <Eye size={19} />
              )}
            </button>
          </div>
        </div>

        <div className="form-options">
          <label className="remember-option">
            <input
              type="checkbox"
              checked={remember}
              onChange={(event) => setRemember(event.target.checked)}
            />
            <span>Ghi nhớ tài khoản</span>
          </label>

          <Link className="forgot-link" to="/forgot-password">
            Quên mật khẩu?
          </Link>
        </div>

        <button
          className="auth-submit"
          type="submit"
          disabled={loading}
        >
          {loading ? (
            <>
              <span className="auth-spinner" />
              Đang đăng nhập...
            </>
          ) : (
            <>
              <LogIn size={20} />
              Đăng nhập
            </>
          )}
        </button>
      </form>

      <p className="auth-switch">
        Bạn chưa có tài khoản?{' '}
        <Link to="/register">Đăng ký ngay</Link>
      </p>
    </AuthLayout>
  )
}

export default LoginPage