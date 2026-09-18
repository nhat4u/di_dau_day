import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft,
  Eye,
  EyeOff,
  House,
  LockKeyhole,
  Mail,
  MapPinHouse,
  Phone,
  UserRound,
  UsersRound,
} from 'lucide-react'
import '../styles/register.css'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL || 'http://localhost:5171/api'
).replace(/\/$/, '')

function getApiError(data) {
  if (data?.message) {
    return data.message
  }

  if (data?.errors) {
    const firstError = Object.values(data.errors)
      .flat()
      .find(Boolean)

    if (firstError) {
      return firstError
    }
  }

  return 'Không thể đăng ký tài khoản. Vui lòng thử lại.'
}

function RegisterPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const roleFromUrl = searchParams.get('role') === 'owner'
    ? 'owner'
    : 'guest'

  const [formData, setFormData] = useState({
    fullName: '',
    email: '',
    phone: '',
    password: '',
    confirmPassword: '',
    role: roleFromUrl,
  })
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  const updateField = (event) => {
    const { name, value } = event.target

    setFormData((current) => ({
      ...current,
      [name]: value,
    }))
    setErrorMessage('')
  }

  const chooseRole = (role) => {
    setFormData((current) => ({ ...current, role }))
    setErrorMessage('')
  }

  const validateForm = () => {
    if (formData.fullName.trim().length < 2) {
      return 'Họ tên phải có ít nhất 2 ký tự.'
    }

    if (!/^\S+@\S+\.\S+$/.test(formData.email.trim())) {
      return 'Email không hợp lệ.'
    }

    if (!/^0[0-9]{9}$/.test(formData.phone.trim())) {
      return 'Số điện thoại phải gồm 10 số và bắt đầu bằng số 0.'
    }

    if (formData.password.length < 8) {
      return 'Mật khẩu phải có ít nhất 8 ký tự.'
    }

    if (formData.password !== formData.confirmPassword) {
      return 'Mật khẩu xác nhận không khớp.'
    }

    return ''
  }

  const handleSubmit = async (event) => {
    event.preventDefault()

    const validationMessage = validateForm()

    if (validationMessage) {
      setErrorMessage(validationMessage)
      return
    }

    setIsSubmitting(true)
    setErrorMessage('')
    setSuccessMessage('')

    try {
      const response = await fetch(`${API_BASE_URL}/auth/register`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          fullName: formData.fullName.trim(),
          email: formData.email.trim().toLowerCase(),
          phone: formData.phone.trim(),
          password: formData.password,
          confirmPassword: formData.confirmPassword,
          role: formData.role,
        }),
      })

      const data = await response.json().catch(() => ({}))

      if (!response.ok) {
        throw new Error(getApiError(data))
      }

      setSuccessMessage(data.message || 'Đăng ký tài khoản thành công.')

      window.setTimeout(() => {
        navigate('/login', {
          replace: true,
          state: {
            registrationMessage: data.message,
            email: formData.email.trim().toLowerCase(),
          },
        })
      }, 1400)
    } catch (error) {
      setErrorMessage(
        error instanceof Error
          ? error.message
          : 'Không thể kết nối đến máy chủ.',
      )
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="register-page">
      <section className="register-showcase">
        <div className="register-decor register-decor-top" />
        <div className="register-decor register-decor-bottom" />

        <Link className="register-brand" to="/">
          <span className="register-brand-icon">
            <MapPinHouse size={30} strokeWidth={2.2} />
          </span>
          <span>
            <strong>Đi Đâu Đây</strong>
            <small>Homestay Việt Nam</small>
          </span>
        </Link>

        <div className="register-showcase-content">
          <p className="register-kicker">BẮT ĐẦU HÀNH TRÌNH</p>
          <h1>Một tài khoản, thật nhiều chuyến đi.</h1>
          <p>
            Khám phá những chốn nghỉ chân riêng tư hoặc bắt đầu hành
            trình kinh doanh homestay của bạn.
          </p>

          <div className="register-benefits">
            <span><UsersRound size={20} /> Đặt phòng nhanh chóng, an toàn</span>
            <span><House size={20} /> Quản lý homestay thật dễ dàng</span>
          </div>
        </div>

        <small className="register-copyright">© 2026 Đi Đâu Đây</small>
      </section>

      <section className="register-form-panel">
        <div className="register-form-wrapper">
          <Link className="register-back-link" to="/">
            <ArrowLeft size={18} /> Về trang chủ
          </Link>

          <header className="register-heading">
            <p>TẠO TÀI KHOẢN</p>
            <h2>Chào mừng bạn đến với Đi Đâu Đây</h2>
            <span>Điền thông tin bên dưới để bắt đầu.</span>
          </header>

          <form className="register-form" onSubmit={handleSubmit}>
            <fieldset className="register-role-group">
              <legend>Bạn muốn đăng ký với vai trò</legend>

              <div className="register-role-options">
                <button
                  className={`register-role-card ${
                    formData.role === 'guest' ? 'is-active' : ''
                  }`}
                  type="button"
                  aria-pressed={formData.role === 'guest'}
                  onClick={() => chooseRole('guest')}
                >
                  <UserRound size={24} />
                  <span>
                    <strong>Khách hàng</strong>
                    <small>Tìm và đặt homestay</small>
                  </span>
                </button>

                <button
                  className={`register-role-card ${
                    formData.role === 'owner' ? 'is-active' : ''
                  }`}
                  type="button"
                  aria-pressed={formData.role === 'owner'}
                  onClick={() => chooseRole('owner')}
                >
                  <House size={24} />
                  <span>
                    <strong>Chủ homestay</strong>
                    <small>Đăng và quản lý chỗ ở</small>
                  </span>
                </button>
              </div>

              {formData.role === 'owner' && (
                <p className="register-owner-note">
                  Tài khoản chủ homestay cần được quản trị viên duyệt
                  trước khi sử dụng.
                </p>
              )}
            </fieldset>

            {errorMessage && (
              <div className="register-message is-error" role="alert">
                {errorMessage}
              </div>
            )}

            {successMessage && (
              <div className="register-message is-success" role="status">
                {successMessage} Đang chuyển đến trang đăng nhập...
              </div>
            )}

            <label className="register-field">
              <span>Họ và tên</span>
              <span className="register-input">
                <UserRound size={19} />
                <input
                  name="fullName"
                  type="text"
                  value={formData.fullName}
                  onChange={updateField}
                  placeholder="Nhập họ và tên"
                  autoComplete="name"
                  minLength={2}
                  maxLength={100}
                  required
                />
              </span>
            </label>

            <div className="register-field-grid">
              <label className="register-field">
                <span>Email</span>
                <span className="register-input">
                  <Mail size={19} />
                  <input
                    name="email"
                    type="email"
                    value={formData.email}
                    onChange={updateField}
                    placeholder="example@gmail.com"
                    autoComplete="email"
                    required
                  />
                </span>
              </label>

              <label className="register-field">
                <span>Số điện thoại</span>
                <span className="register-input">
                  <Phone size={19} />
                  <input
                    name="phone"
                    type="tel"
                    inputMode="numeric"
                    value={formData.phone}
                    onChange={updateField}
                    placeholder="0912345678"
                    autoComplete="tel"
                    maxLength={10}
                    pattern="0[0-9]{9}"
                    required
                  />
                </span>
              </label>
            </div>

            <div className="register-field-grid">
              <label className="register-field">
                <span>Mật khẩu</span>
                <span className="register-input">
                  <LockKeyhole size={19} />
                  <input
                    name="password"
                    type={showPassword ? 'text' : 'password'}
                    value={formData.password}
                    onChange={updateField}
                    placeholder="Ít nhất 8 ký tự"
                    autoComplete="new-password"
                    minLength={8}
                    required
                  />
                  <button
                    type="button"
                    aria-label={showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
                    onClick={() => setShowPassword((current) => !current)}
                  >
                    {showPassword ? <EyeOff size={19} /> : <Eye size={19} />}
                  </button>
                </span>
              </label>

              <label className="register-field">
                <span>Xác nhận mật khẩu</span>
                <span className="register-input">
                  <LockKeyhole size={19} />
                  <input
                    name="confirmPassword"
                    type={showConfirmPassword ? 'text' : 'password'}
                    value={formData.confirmPassword}
                    onChange={updateField}
                    placeholder="Nhập lại mật khẩu"
                    autoComplete="new-password"
                    minLength={8}
                    required
                  />
                  <button
                    type="button"
                    aria-label={
                      showConfirmPassword
                        ? 'Ẩn mật khẩu xác nhận'
                        : 'Hiện mật khẩu xác nhận'
                    }
                    onClick={() => setShowConfirmPassword((current) => !current)}
                  >
                    {showConfirmPassword
                      ? <EyeOff size={19} />
                      : <Eye size={19} />}
                  </button>
                </span>
              </label>
            </div>

            <button
              className="register-submit-button"
              type="submit"
              disabled={isSubmitting || Boolean(successMessage)}
            >
              {isSubmitting ? 'Đang tạo tài khoản...' : 'Đăng ký tài khoản'}
            </button>

            <p className="register-login-link">
              Đã có tài khoản? <Link to="/login">Đăng nhập ngay</Link>
            </p>
          </form>
        </div>
      </section>
    </main>
  )
}

export default RegisterPage
