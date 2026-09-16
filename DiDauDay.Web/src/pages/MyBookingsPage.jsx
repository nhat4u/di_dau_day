import { useCallback, useEffect, useState } from 'react'
import {
  ArrowLeft,
  CalendarDays,
  CheckCircle2,
  Clock3,
  CreditCard,
  House,
  LoaderCircle,
  MapPin,
  ReceiptText,
  Users,
  XCircle,
} from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api from '../services/api'
import '../styles/my-bookings.css'

const bookingTypeLabels = {
  hourly: 'Thuê theo giờ',
  daytime: 'Thuê ban ngày',
  overnight: 'Thuê qua đêm',
  day_night: 'Thuê ngày và đêm',
}

const bookingStatusLabels = {
  pending_payment: 'Chờ thanh toán',
  confirmed: 'Đã xác nhận',
  cancelled: 'Đã hủy',
  completed: 'Đã hoàn thành',
  refunded: 'Đã hoàn tiền',
}

const paymentMethods = [
  {
    value: 'bank_transfer',
    label: 'Chuyển khoản ngân hàng',
  },
  {
    value: 'momo',
    label: 'Ví MoMo',
  },
  {
    value: 'vnpay',
    label: 'VNPay',
  },
]

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

function getErrorMessage(error, fallbackMessage) {
  return error.response?.data?.message || fallbackMessage
}

function MyBookingsPage() {
  const navigate = useNavigate()

  const [bookings, setBookings] = useState([])
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [selectedMethods, setSelectedMethods] = useState({})
  const [workingAction, setWorkingAction] = useState('')
  const [notice, setNotice] = useState(null)

  const loadBookings = useCallback(async () => {
    const accessToken = localStorage.getItem('accessToken')
    const currentUser = getStoredUser()

    if (!accessToken) {
      navigate('/login', { replace: true })
      return
    }

    if (currentUser?.role?.toLowerCase() !== 'guest') {
      setPageError(
        'Trang này dành cho tài khoản khách hàng. Tài khoản chủ homestay không thể đặt phòng.',
      )
      setLoading(false)
      return
    }

    try {
      setLoading(true)
      setPageError('')

      const response = await api.get('/bookings/my')
      const bookingList = response.data.bookings || []

      setBookings(bookingList)
      setSelectedMethods((currentMethods) => {
        const nextMethods = { ...currentMethods }

        bookingList.forEach((booking) => {
          if (!nextMethods[booking.id]) {
            nextMethods[booking.id] = 'bank_transfer'
          }
        })

        return nextMethods
      })
    } catch (error) {
      if (error.response?.status === 401) {
        localStorage.removeItem('accessToken')
        localStorage.removeItem('authUser')
        navigate('/login', { replace: true })
        return
      }

      setPageError(
        getErrorMessage(
          error,
          'Không thể tải danh sách đơn đặt phòng.',
        ),
      )
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => {
    loadBookings()
  }, [loadBookings])

  function changePaymentMethod(bookingId, paymentMethod) {
    setSelectedMethods((currentMethods) => ({
      ...currentMethods,
      [bookingId]: paymentMethod,
    }))
    setNotice(null)
  }

  async function payBooking(booking) {
    const actionKey = `pay-${booking.id}`

    try {
      setWorkingAction(actionKey)
      setNotice(null)

      const response = await api.post('/payments', {
        bookingId: booking.id,
        paymentMethod:
          selectedMethods[booking.id] || 'bank_transfer',
      })

      setBookings((currentBookings) =>
        currentBookings.map((currentBooking) =>
          currentBooking.id === booking.id
            ? {
                ...currentBooking,
                status:
                  response.data.bookingStatus || 'confirmed',
              }
            : currentBooking,
        ),
      )

      setNotice({
        bookingId: booking.id,
        type: 'success',
        message:
          response.data.message ||
          'Thanh toán thành công. Đơn đã được xác nhận.',
        transactionCode:
          response.data.payment?.transactionCode || '',
      })
    } catch (error) {
      setNotice({
        bookingId: booking.id,
        type: 'error',
        message: getErrorMessage(
          error,
          'Không thể thanh toán đơn này.',
        ),
      })

      if (error.response?.status === 409) {
        await loadBookings()
      }
    } finally {
      setWorkingAction('')
    }
  }

  async function cancelBooking(booking) {
    const shouldCancel = window.confirm(
      `Bạn có chắc muốn hủy đơn ${booking.bookingCode}?`,
    )

    if (!shouldCancel) {
      return
    }

    const actionKey = `cancel-${booking.id}`

    try {
      setWorkingAction(actionKey)
      setNotice(null)

      const response = await api.patch(
        `/bookings/${booking.id}/cancel`,
      )

      setBookings((currentBookings) =>
        currentBookings.map((currentBooking) =>
          currentBooking.id === booking.id
            ? { ...currentBooking, status: 'cancelled' }
            : currentBooking,
        ),
      )

      setNotice({
        bookingId: booking.id,
        type: 'success',
        message:
          response.data.message || 'Đã hủy đơn đặt phòng.',
      })
    } catch (error) {
      setNotice({
        bookingId: booking.id,
        type: 'error',
        message: getErrorMessage(
          error,
          'Không thể hủy đơn đặt phòng.',
        ),
      })
    } finally {
      setWorkingAction('')
    }
  }

  return (
    <>
      <Header />

      <main className="my-bookings-page">
        <div className="container my-bookings-container">
          <Link className="my-bookings-back" to="/">
            <ArrowLeft size={18} />
            Về trang chủ
          </Link>

          <header className="my-bookings-heading">
            <span className="my-bookings-eyebrow">
              <ReceiptText size={17} />
              LỊCH SỬ ĐẶT PHÒNG
            </span>
            <h1>Đơn đặt phòng của tôi</h1>
            <p>
              Thanh toán đơn đang chờ và theo dõi trạng thái đặt
              phòng tại đây.
            </p>
          </header>

          {loading && (
            <section className="my-bookings-state">
              <LoaderCircle className="my-bookings-spinner" />
              <h2>Đang tải đơn đặt phòng...</h2>
            </section>
          )}

          {!loading && pageError && (
            <section className="my-bookings-state is-error">
              <XCircle size={45} />
              <h2>Chưa thể mở danh sách đơn</h2>
              <p>{pageError}</p>
              <button type="button" onClick={loadBookings}>
                Thử lại
              </button>
            </section>
          )}

          {!loading && !pageError && bookings.length === 0 && (
            <section className="my-bookings-state">
              <House size={48} />
              <h2>Bạn chưa có đơn đặt phòng</h2>
              <p>Hãy chọn một homestay phù hợp để bắt đầu.</p>
              <Link to="/">Khám phá homestay</Link>
            </section>
          )}

          {!loading && !pageError && bookings.length > 0 && (
            <section className="booking-list">
              {bookings.map((booking) => {
                const isPending =
                  booking.status === 'pending_payment'
                const isPaying =
                  workingAction === `pay-${booking.id}`
                const isCancelling =
                  workingAction === `cancel-${booking.id}`
                const bookingNotice =
                  notice?.bookingId === booking.id
                    ? notice
                    : null

                return (
                  <article className="booking-card" key={booking.id}>
                    <div className="booking-card-top">
                      <div>
                        <span className="booking-code-label">
                          Mã đặt phòng
                        </span>
                        <strong className="booking-code">
                          {booking.bookingCode}
                        </strong>
                      </div>

                      <span
                        className={`booking-status status-${booking.status}`}
                      >
                        {bookingStatusLabels[booking.status] ||
                          booking.status}
                      </span>
                    </div>

                    <div className="booking-card-body">
                      <div className="booking-main-information">
                        <Link
                          className="booking-homestay-name"
                          to={`/homestays/${booking.homestay.slug}`}
                        >
                          {booking.homestay.name}
                        </Link>

                        <p className="booking-address">
                          <MapPin size={17} />
                          {booking.homestay.address}
                        </p>

                        <div className="booking-facts">
                          <div className="booking-fact">
                            <CalendarDays size={20} />
                            <span>
                              <small>Nhận phòng</small>
                              <strong>
                                {formatDateTime(booking.checkIn)}
                              </strong>
                            </span>
                          </div>

                          <div className="booking-fact">
                            <Clock3 size={20} />
                            <span>
                              <small>Trả phòng</small>
                              <strong>
                                {formatDateTime(booking.checkOut)}
                              </strong>
                            </span>
                          </div>

                          <div className="booking-fact">
                            <Users size={20} />
                            <span>
                              <small>Loại phòng</small>
                              <strong>
                                {bookingTypeLabels[
                                  booking.bookingType
                                ] || booking.bookingType}
                                {' · '}
                                {booking.guestCount} khách
                              </strong>
                            </span>
                          </div>
                        </div>
                      </div>

                      <div className="booking-price-box">
                        <span>Tổng tiền</span>
                        <strong>
                          {formatPrice(booking.totalAmount)}
                        </strong>
                      </div>
                    </div>

                    {bookingNotice && (
                      <div
                        className={`booking-notice is-${bookingNotice.type}`}
                      >
                        {bookingNotice.type === 'success' ? (
                          <CheckCircle2 size={19} />
                        ) : (
                          <XCircle size={19} />
                        )}
                        <span>
                          {bookingNotice.message}
                          {bookingNotice.transactionCode && (
                            <small>
                              Mã giao dịch:{' '}
                              {bookingNotice.transactionCode}
                            </small>
                          )}
                        </span>
                      </div>
                    )}

                    {isPending && (
                      <div className="booking-payment-panel">
                        <label>
                          <span>Phương thức thanh toán</span>
                          <select
                            value={
                              selectedMethods[booking.id] ||
                              'bank_transfer'
                            }
                            onChange={(event) =>
                              changePaymentMethod(
                                booking.id,
                                event.target.value,
                              )
                            }
                            disabled={Boolean(workingAction)}
                          >
                            {paymentMethods.map((method) => (
                              <option
                                key={method.value}
                                value={method.value}
                              >
                                {method.label}
                              </option>
                            ))}
                          </select>
                        </label>

                        <div className="booking-payment-actions">
                          <button
                            className="booking-cancel-button"
                            type="button"
                            onClick={() => cancelBooking(booking)}
                            disabled={Boolean(workingAction)}
                          >
                            {isCancelling
                              ? 'Đang hủy...'
                              : 'Hủy đơn'}
                          </button>

                          <button
                            className="booking-pay-button"
                            type="button"
                            onClick={() => payBooking(booking)}
                            disabled={Boolean(workingAction)}
                          >
                            <CreditCard size={19} />
                            {isPaying
                              ? 'Đang thanh toán...'
                              : 'Thanh toán & xác nhận'}
                          </button>
                        </div>
                      </div>
                    )}

                    {booking.status === 'confirmed' && (
                      <div className="booking-confirmed-message">
                        <CheckCircle2 size={20} />
                        Đơn đã được thanh toán và xác nhận.
                      </div>
                    )}
                  </article>
                )
              })}
            </section>
          )}

          <p className="payment-demo-note">
            Lưu ý: API hiện tại đang mô phỏng thanh toán. Khi bấm
            “Thanh toán &amp; xác nhận”, hệ thống tạo giao dịch và xác
            nhận đơn ngay, chưa chuyển sang cổng MoMo/VNPay thật.
          </p>
        </div>
      </main>

      <Footer />
    </>
  )
}

export default MyBookingsPage
