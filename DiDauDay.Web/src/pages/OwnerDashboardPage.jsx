import { useCallback, useEffect, useState } from 'react'
import {
  AlertTriangle,
  Banknote,
  Building2,
  CheckCircle2,
  ClipboardList,
  Hourglass,
  LayoutDashboard,
  LoaderCircle,
  Phone,
  RefreshCw,
  ShieldCheck,
  Users,
  WalletCards,
  Wrench,
  XCircle,
} from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api, { getApiErrorMessage } from '../services/api'
import '../styles/owner-dashboard.css'

const bookingTypeLabels = {
  hourly: 'Thuê theo giờ',
  daytime: 'Thuê ban ngày',
  overnight: 'Thuê qua đêm',
  day_night: 'Thuê ngày và đêm',
}

function getStoredUser() {
  const storedUser = localStorage.getItem('authUser')

  if (!storedUser) {
    return null
  }

  try {
    return JSON.parse(storedUser)
  } catch {
    return null
  }
}

function formatPrice(value) {
  return `${new Intl.NumberFormat('vi-VN').format(
    Number(value || 0),
  )}đ`
}

function formatDateTime(value) {
  if (!value) {
    return '--'
  }

  return new Intl.DateTimeFormat('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(value))
}

function OwnerDashboardPage() {
  const navigate = useNavigate()

  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [refreshing, setRefreshing] = useState(false)

  const loadDashboard = useCallback(
    async (options = {}) => {
      const { showLoading = true } = options
      const accessToken = localStorage.getItem('accessToken')
      const currentUser = getStoredUser()

      if (!accessToken) {
        navigate('/login', { replace: true })
        return
      }

      if (currentUser?.role?.toLowerCase() !== 'owner') {
        setPageError('Trang này chỉ dành cho chủ homestay.')
        setLoading(false)
        return
      }

      try {
        if (showLoading) {
          setLoading(true)
        } else {
          setRefreshing(true)
        }

        setPageError('')

        const response = await api.get('/owner/dashboard')
        setData(response.data)
      } catch (requestError) {
        if (requestError.response?.status === 401) {
          localStorage.removeItem('accessToken')
          localStorage.removeItem('authUser')
          navigate('/login', { replace: true })
          return
        }

        setPageError(
          getApiErrorMessage(
            requestError,
            'Không thể tải dữ liệu dashboard.',
          ),
        )
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [navigate],
  )

  useEffect(() => {
    loadDashboard()
  }, [loadDashboard])

  const overview = data?.overview
  const wallet = data?.wallet
  const upcomingBookings = data?.upcomingBookings || []

  return (
    <>
      <Header />

      <main className="owner-dashboard-page">
        <div className="container owner-dashboard-container">
          <header className="owner-dashboard-heading">
            <div>
              <span>
                <ShieldCheck size={18} />
                KHÔNG GIAN CHỦ HOME
              </span>
              <h1>
                <LayoutDashboard size={30} strokeWidth={2.2} />
                Dashboard của tôi
              </h1>
              <p>
                Theo dõi tình hình homestay, đơn đặt phòng sắp tới
                và dòng tiền của bạn.
              </p>
            </div>

            <button
              type="button"
              onClick={() => loadDashboard({ showLoading: false })}
              disabled={loading || refreshing}
            >
              <RefreshCw
                size={18}
                className={refreshing ? 'is-spinning' : ''}
              />
              Làm mới
            </button>
          </header>

          {loading && (
            <section className="owner-dashboard-state">
              <LoaderCircle className="owner-dashboard-spinner" />
              <h2>Đang tải dashboard...</h2>
            </section>
          )}

          {!loading && pageError && (
            <section className="owner-dashboard-state is-error">
              <XCircle size={46} />
              <h2>Chưa thể mở dashboard</h2>
              <p>{pageError}</p>
              <button type="button" onClick={() => loadDashboard()}>
                Thử lại
              </button>
            </section>
          )}

          {!loading && !pageError && overview && (
            <>
              <section className="owner-dashboard-stats">
                <article className="owner-stat-card">
                  <span className="owner-stat-icon is-home">
                    <Building2 size={22} />
                  </span>
                  <div>
                    <strong>{overview.totalHomestays}</strong>
                    <small>Homestay của bạn</small>
                  </div>
                  <Link
                    className="owner-stat-flag is-neutral"
                    to="/owner/homestays"
                  >
                    Quản lý
                  </Link>
                </article>

                <article className="owner-stat-card">
                  <span className="owner-stat-icon is-active">
                    <CheckCircle2 size={22} />
                  </span>
                  <div>
                    <strong>{overview.activeHomestays}</strong>
                    <small>Đang hoạt động</small>
                  </div>
                </article>

                <article className="owner-stat-card">
                  <span className="owner-stat-icon is-maintenance">
                    <Wrench size={22} />
                  </span>
                  <div>
                    <strong>{overview.maintenanceHomestays}</strong>
                    <small>Đang bảo trì</small>
                  </div>
                </article>

                <article className="owner-stat-card">
                  <span className="owner-stat-icon is-booking">
                    <ClipboardList size={22} />
                  </span>
                  <div>
                    <strong>{overview.totalBookings}</strong>
                    <small>Tổng đơn đặt phòng</small>
                  </div>
                  <Link
                    className="owner-stat-flag is-neutral"
                    to="/owner/bookings"
                  >
                    Xem tất cả
                  </Link>
                </article>

                <article className="owner-stat-card">
                  <span className="owner-stat-icon is-confirmed">
                    <Users size={22} />
                  </span>
                  <div>
                    <strong>{overview.confirmedBookings}</strong>
                    <small>Đang diễn ra / đã xác nhận</small>
                  </div>
                </article>

                <article className="owner-stat-card">
                  <span className="owner-stat-icon is-completed">
                    <CheckCircle2 size={22} />
                  </span>
                  <div>
                    <strong>{overview.completedBookings}</strong>
                    <small>Đã hoàn thành</small>
                  </div>
                </article>

                {overview.disputedBookings > 0 && (
                  <article className="owner-stat-card is-alert-card">
                    <span className="owner-stat-icon is-disputed">
                      <AlertTriangle size={22} />
                    </span>
                    <div>
                      <strong>{overview.disputedBookings}</strong>
                      <small>Đang xử lý hoàn tiền</small>
                    </div>
                  </article>
                )}
              </section>

              <section className="owner-dashboard-money">
                <article className="owner-money-card">
                  <span>
                    <Hourglass size={19} />
                    Tiền đang tạm giữ
                  </span>
                  <strong>{formatPrice(overview.heldMoney)}</strong>
                </article>

                <article className="owner-money-card is-accent">
                  <span>
                    <Banknote size={19} />
                    Tổng doanh thu đã nhận
                  </span>
                  <strong>
                    {formatPrice(overview.totalRevenue)}
                  </strong>
                </article>

                <article className="owner-money-card">
                  <span>
                    <WalletCards size={19} />
                    Số dư khả dụng trong ví
                  </span>
                  <strong>
                    {formatPrice(wallet?.availableBalance)}
                  </strong>
                </article>

                <article className="owner-money-card">
                  <span>
                    <WalletCards size={19} />
                    Số dư đang chờ
                  </span>
                  <strong>
                    {formatPrice(wallet?.pendingBalance)}
                  </strong>
                </article>
              </section>

              <section className="owner-dashboard-panel">
                <div className="owner-dashboard-panel-title">
                  <ClipboardList size={19} />
                  <h2>Đơn sắp tới</h2>
                  <Link
                    className="owner-dashboard-panel-link"
                    to="/owner/bookings"
                  >
                    Xem tất cả đơn
                  </Link>
                </div>

                {upcomingBookings.length === 0 ? (
                  <p className="owner-dashboard-empty">
                    Chưa có đơn nào sắp diễn ra.
                  </p>
                ) : (
                  <div className="owner-upcoming-bookings">
                    {upcomingBookings.map((booking) => (
                      <div
                        className="owner-upcoming-booking-row"
                        key={booking.id}
                      >
                        <div className="owner-upcoming-booking-main">
                          <strong>{booking.homestayName}</strong>
                          <small>
                            {bookingTypeLabels[
                              booking.bookingType
                            ] || booking.bookingType}
                            {' · '}
                            {booking.bookingCode}
                          </small>
                        </div>

                        <div className="owner-upcoming-booking-dates">
                          <span>
                            Nhận: {formatDateTime(booking.checkIn)}
                          </span>
                          <span>
                            Trả: {formatDateTime(booking.checkOut)}
                          </span>
                        </div>

                        <div className="owner-upcoming-booking-guest">
                          <strong>{booking.guestName}</strong>
                          <small>
                            <Phone size={12} />
                            {booking.guestPhone}
                          </small>
                        </div>

                        <strong className="owner-upcoming-booking-amount">
                          {formatPrice(booking.totalAmount)}
                        </strong>
                      </div>
                    ))}
                  </div>
                )}
              </section>
            </>
          )}
        </div>
      </main>

      <Footer />
    </>
  )
}

export default OwnerDashboardPage
