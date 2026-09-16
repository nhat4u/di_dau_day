import { ArrowLeft, MapPinHouse } from 'lucide-react'
import { Link } from 'react-router-dom'
import '../styles/auth.css'

function AuthLayout({ eyebrow, title, description, children }) {
  return (
    <main className="auth-page">
      <section className="auth-showcase">
        <span className="auth-shape auth-shape-one" />
        <span className="auth-shape auth-shape-two" />

        <div className="auth-showcase-inner">
          <Link className="auth-brand" to="/">
            <span className="auth-brand-icon">
              <MapPinHouse size={27} />
            </span>

            <span>
              <strong>Đi Đâu Đây</strong>
              <small>HOMESTAY VIỆT NAM</small>
            </span>
          </Link>

          <div className="auth-showcase-content">
            <span className="auth-showcase-eyebrow">
              CHÀO MỪNG TRỞ LẠI
            </span>

            <h1>
              Chuyến đi của bạn
              <br />
              đang chờ.
            </h1>

            <p>
              Đăng nhập để tiếp tục khám phá, quản lý homestay
              và theo dõi những chuyến đi của bạn.
            </p>
          </div>

          <p className="auth-showcase-footer">
            © 2026 Đi Đâu Đây
          </p>
        </div>
      </section>

      <section className="auth-form-side">
        <Link className="auth-back-link" to="/">
          <ArrowLeft size={18} />
          Về trang chủ
        </Link>

        <div className="auth-form-wrapper">
          <div className="auth-form-heading">
            <span>{eyebrow}</span>
            <h2>{title}</h2>
            <p>{description}</p>
          </div>

          {children}
        </div>
      </section>
    </main>
  )
}

export default AuthLayout