import { useCallback, useEffect, useState } from 'react'
import {
  Banknote,
  Building2,
  CheckCircle2,
  ClipboardList,
  Clock3,
  FileCheck2,
  HandCoins,
  Hourglass,
  LayoutDashboard,
  LoaderCircle,
  RefreshCw,
  ShieldCheck,
  UsersRound,
  WalletCards,
  XCircle,
} from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api, { getApiErrorMessage } from '../services/api'
import '../styles/admin-dashboard.css'

const bookingStatusLabels = {
  pending_payment: 'Chờ thanh toán',
  confirmed: 'Đã xác nhận',
  funds_held: 'Đang giữ tiền',
  disputed: 'Đang xử lý hoàn tiền',
  completed: 'Đã hoàn thành',
  cancelled: 'Đã hủy',
  refunded: 'Đã hoàn tiền',
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

function AdminDashboardPage() {
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

      if (currentUser?.role?.toLowerCase() !== 'admin') {
        setPageError('Trang này chỉ dành cho quản trị viên.')
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

        const response = await api.get('/admin/dashboard')
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
  const pendingActions = data?.pendingActions
  const wallet = data?.wallet
  const recentBookings = data?.recentBookings || []

  return (
    <>
      <Header />

      <main className="admin-dashboard-page">
        <div className="container admin-dashboard-container">
          <header className="admin-dashboard-heading">
            <div>
              <span>
                <ShieldCheck size={18} />
                TRUNG TÂM QUẢN TRỊ
              </span>
              <h1>
                <LayoutDashboard size={30} strokeWidth={2.2} />
                Dashboard QTV
              </h1>
              <p>
                Tổng quan toàn hệ thống: chủ home, homestay, đơn
                đặt phòng và dòng tiền.
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
            <section className="admin-dashboard-state">
              <LoaderCircle className="admin-dashboard-spinner" />
              <h2>Đang tải dashboard...</h2>
            </section>
          )}

          {!loading && pageError && (
            <section className="admin-dashboard-state is-error">
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
              <section className="admin-dashboard-stats">
                <article className="admin-stat-card">
                  <span className="admin-stat-icon is-owner">
                    <UsersRound size={22} />
                  </span>
                  <div>
                    <strong>{overview.approvedOwners}</strong>
                    <small>Chủ home đã duyệt</small>
                  </div>
                  {overview.pendingOwners > 0 && (
                    <Link
                      className="admin-stat-flag"
                      to="/admin/owners"
                    >
                      {overview.pendingOwners} chờ duyệt
                    </Link>
                  )}
                </article>

                <article className="admin-stat-card">
                  <span className="admin-stat-icon is-homestay">
                    <Building2 size={22} />
                  </span>
                  <div>
                    <strong>{overview.approvedHomestays}</strong>
                    <small>Homestay đang hoạt động</small>
                  </div>
                </article>

                <article className="admin-stat-card">
                  <span className="admin-stat-icon is-guest">
                    <UsersRound size={22} />
                  </span>
                  <div>
                    <strong>{overview.totalGuests}</strong>
                    <small>Khách hàng</small>
                  </div>
                </article>

                <article className="admin-stat-card">
                  <span className="admin-stat-icon is-booking">
                    <ClipboardList size={22} />
                  </span>
                  <div>
                    <strong>{overview.totalBookings}</strong>
                    <small>Tổng đơn đặt phòng</small>
                  </div>
                  <Link
                    className="admin-stat-flag is-neutral"
                    to="/admin/bookings"
                  >
                    Xem tất cả
                  </Link>
                </article>

                <article className="admin-stat-card">
                  <span className="admin-stat-icon is-confirmed">
                    <CheckCircle2 size={22} />
                  </span>
                  <div>
                    <strong>{overview.confirmedBookings}</strong>
                    <small>Đang diễn ra / đã xác nhận</small>
                  </div>
                </article>

                <article className="admin-stat-card">
                  <span className="admin-stat-icon is-completed">
                    <CheckCircle2 size={22} />
                  </span>
                  <div>
                    <strong>{overview.completedBookings}</strong>
                    <small>Đã hoàn thành</small>
                  </div>
                </article>
              </section>

              <section className="admin-dashboard-money">
                <article className="admin-money-card">
                  <span>
                    <Hourglass size={19} />
                    Tiền đang tạm giữ
                  </span>
                  <strong>{formatPrice(overview.heldMoney)}</strong>
                </article>

                <article className="admin-money-card">
                  <span>
                    <Banknote size={19} />
                    Tổng doanh thu đã hoàn tất
                  </span>
                  <strong>
                    {formatPrice(overview.totalRevenue)}
                  </strong>
                </article>

                <article className="admin-money-card is-accent">
                  <span>
                    <HandCoins size={19} />
                    Doanh thu nền tảng (phí)
                  </span>
                  <strong>
                    {formatPrice(overview.platformRevenue)}
                  </strong>
                </article>

                <article className="admin-money-card">
                  <span>
                    <WalletCards size={19} />
                    Số dư khả dụng trong ví
                  </span>
                  <strong>
                    {formatPrice(wallet?.availableBalance)}
                  </strong>
                </article>
              </section>

              <section className="admin-dashboard-columns">
                <article className="admin-dashboard-panel">
                  <div className="admin-dashboard-panel-title">
                    <FileCheck2 size={19} />
                    <h2>Việc chờ QTV xử lý</h2>
                  </div>

                  <div className="admin-pending-list">
                    <Link to="/admin/refunds">
                      <span>Yêu cầu hoàn tiền</span>
                      <strong
                        className={
                          pendingActions?.pendingRefunds > 0
                            ? 'is-alert'
                            : ''
                        }
                      >
                        {pendingActions?.pendingRefunds ?? 0}
                      </strong>
                    </Link>

                    <Link to="/admin/owners">
                      <span>Yêu cầu đổi hồ sơ chủ home</span>
                      <strong
                        className={
                          pendingActions?.pendingProfileChanges > 0
                            ? 'is-alert'
                            : ''
                        }
                      >
                        {pendingActions?.pendingProfileChanges ?? 0}
                      </strong>
                    </Link>

                    <Link to="/admin/homestay-changes">
                      <span>Yêu cầu đổi thông tin homestay</span>
                      <strong
                        className={
                          overview.pendingOwners > 0 ? 'is-alert' : ''
                        }
                      >
                        {overview.pendingOwners ?? 0}
                      </strong>
                    </Link>

                    <div className="admin-pending-static">
                      <span>Yêu cầu rút tiền</span>
                      <strong
                        className={
                          pendingActions?.pendingWithdrawals > 0
                            ? 'is-alert'
                            : ''
                        }
                      >
                        {pendingActions?.pendingWithdrawals ?? 0}
                      </strong>
                    </div>
                  </div>
                </article>

                <article className="admin-dashboard-panel is-wide">
                  <div className="admin-dashboard-panel-title">
                    <ClipboardList size={19} />
                    <h2>Đơn đặt phòng gần đây</h2>
                    <Link
                      className="admin-dashboard-panel-link"
                      to="/admin/bookings"
                    >
                      Xem tất cả đơn
                    </Link>
                  </div>

                  {recentBookings.length === 0 ? (
                    <p className="admin-dashboard-empty">
                      Chưa có đơn đặt phòng nào.
                    </p>
                  ) : (
                    <div className="admin-recent-bookings">
                      {recentBookings.map((booking) => (
                        <div
                          className="admin-recent-booking-row"
                          key={booking.id}
                        >
                          <div>
                            <strong>{booking.bookingCode}</strong>
                            <small>{booking.homestayName}</small>
                          </div>
                          <div className="admin-recent-booking-guest">
                            <small>{booking.guestName}</small>
                            <span className="admin-recent-booking-time">
                              <Clock3 size={13} />
                              {formatDateTime(booking.createdAt)}
                            </span>
                          </div>
                          <strong className="admin-recent-booking-amount">
                            {formatPrice(booking.totalAmount)}
                          </strong>
                          <span
                            className={
                              'admin-recent-booking-status status-' +
                              booking.status
                            }
                          >
                            {bookingStatusLabels[booking.status] ||
                              booking.status}
                          </span>
                        </div>
                      ))}
                    </div>
                  )}
                </article>
              </section>
            </>
          )}
        </div>
      </main>

      <Footer />
    </>
  )
}

export default AdminDashboardPage
