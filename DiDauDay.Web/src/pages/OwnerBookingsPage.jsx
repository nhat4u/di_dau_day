import { useCallback, useEffect, useState } from 'react'
import {
  CalendarDays,
  CheckCircle2,
  Clock3,
  Eye,
  House,
  LoaderCircle,
  Mail,
  MapPin,
  Phone,
  ReceiptText,
  UserRound,
  Users,
  WalletCards,
  X,
  XCircle,
} from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api from '../services/api'
import '../styles/owner-bookings.css'

const bookingTypeLabels = {
  hourly: 'Thuê theo giờ',
  daytime: 'Thuê ban ngày',
  overnight: 'Thuê qua đêm',
  day_night: 'Thuê ngày và đêm',
}

const bookingStatusLabels = {
  pending_payment: 'Chờ khách thanh toán',
  confirmed: 'Đã xác nhận',
  funds_held: 'Đang giữ tiền',
  completed: 'Đã hoàn thành',
  cancelled: 'Đã hủy',
  refunded: 'Đã hoàn tiền',
}

const paymentStatusLabels = {
  held: 'Tiền đang tạm giữ',
  settled: 'Đã chia tiền',
  refunded: 'Đã hoàn tiền',
}

const paymentMethodLabels = {
  bank_transfer: 'Chuyển khoản ngân hàng',
  momo: 'Ví MoMo',
  vnpay: 'VNPay',
}

const statusFilters = [
  { value: '', label: 'Tất cả' },
  { value: 'pending_payment', label: 'Chờ thanh toán' },
  { value: 'confirmed', label: 'Đã xác nhận' },
  { value: 'completed', label: 'Hoàn thành' },
  { value: 'cancelled', label: 'Đã hủy' },
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

function OwnerBookingsPage() {
  const navigate = useNavigate()

  const [bookings, setBookings] = useState([])
  const [selectedStatus, setSelectedStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [workingBookingId, setWorkingBookingId] = useState(null)
  const [notice, setNotice] = useState(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')
  const [selectedBooking, setSelectedBooking] = useState(null)

  const loadBookings = useCallback(
    async (status = '') => {
      const accessToken = localStorage.getItem('accessToken')
      const currentUser = getStoredUser()

      if (!accessToken) {
        navigate('/login', { replace: true })
        return
      }

      if (currentUser?.role?.toLowerCase() !== 'owner') {
        setPageError(
          'Trang này chỉ dành cho tài khoản chủ homestay.',
        )
        setLoading(false)
        return
      }

      try {
        setLoading(true)
        setPageError('')

        const response = await api.get('/owner/bookings', {
          params: status ? { status } : {},
        })

        setBookings(response.data.bookings || [])
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
    },
    [navigate],
  )

  useEffect(() => {
    loadBookings(selectedStatus)
  }, [loadBookings, selectedStatus])

  useEffect(() => {
    if (!detailOpen) {
      return undefined
    }

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    function closeWithEscape(event) {
      if (event.key === 'Escape' && !workingBookingId) {
        setDetailOpen(false)
      }
    }

    window.addEventListener('keydown', closeWithEscape)

    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', closeWithEscape)
    }
  }, [detailOpen, workingBookingId])

  function changeStatusFilter(status) {
    setSelectedStatus(status)
    setNotice(null)
  }

  async function openBookingDetail(bookingId) {
    try {
      setSelectedBooking(null)
      setDetailError('')
      setDetailLoading(true)
      setDetailOpen(true)

      const response = await api.get(
        `/owner/bookings/${bookingId}`,
      )

      setSelectedBooking(response.data.booking)
    } catch (error) {
      setDetailError(
        getErrorMessage(
          error,
          'Không thể tải chi tiết đơn đặt phòng.',
        ),
      )
    } finally {
      setDetailLoading(false)
    }
  }

  function closeBookingDetail() {
    if (!workingBookingId) {
      setDetailOpen(false)
    }
  }

  async function completeBooking(booking) {
    const ownerAmount = Math.round(
      Number(booking.totalAmount) * 0.9,
    )
    const platformFee =
      Number(booking.totalAmount) - ownerAmount

    const shouldComplete = window.confirm(
      `Hoàn thành đơn ${booking.bookingCode}?\n\n` +
        `Chủ homestay nhận: ${formatPrice(ownerAmount)}\n` +
        `Phí nền tảng 10%: ${formatPrice(platformFee)}`,
    )

    if (!shouldComplete) {
      return
    }

    try {
      setWorkingBookingId(booking.id)
      setNotice(null)

      const response = await api.patch(
        `/owner/bookings/${booking.id}/complete`,
      )

      setBookings((currentBookings) =>
        currentBookings.map((currentBooking) =>
          currentBooking.id === booking.id
            ? {
                ...currentBooking,
                status: 'completed',
                paymentStatus: 'settled',
              }
            : currentBooking,
        ),
      )

      setSelectedBooking((currentBooking) =>
        currentBooking?.id === booking.id
          ? {
              ...currentBooking,
              status: 'completed',
              payment: currentBooking.payment
                ? {
                    ...currentBooking.payment,
                    status: 'settled',
                  }
                : currentBooking.payment,
            }
          : currentBooking,
      )

      setNotice({
        bookingId: booking.id,
        type: 'success',
        message:
          response.data.message ||
          'Đã hoàn thành đơn và chia tiền.',
        settlement: response.data.settlement || null,
      })
    } catch (error) {
      setNotice({
        bookingId: booking.id,
        type: 'error',
        message: getErrorMessage(
          error,
          'Không thể hoàn thành đơn đặt phòng.',
        ),
      })
    } finally {
      setWorkingBookingId(null)
    }
  }

  function renderCompleteArea(booking) {
    const canHaveCompleteButton =
      booking.status === 'confirmed' ||
      booking.status === 'funds_held'

    if (!canHaveCompleteButton) {
      return null
    }

    const hasCheckedOut =
      new Date(booking.checkOut).getTime() <= Date.now()

    return (
      <div className="owner-booking-complete-area">
        {!hasCheckedOut && (
          <p>
            Chỉ có thể hoàn thành sau{' '}
            <strong>{formatDateTime(booking.checkOut)}</strong>.
          </p>
        )}

        <button
          className="owner-complete-button"
          type="button"
          onClick={() => completeBooking(booking)}
          disabled={
            !hasCheckedOut || workingBookingId !== null
          }
        >
          <CheckCircle2 size={19} />
          {workingBookingId === booking.id
            ? 'Đang hoàn thành...'
            : hasCheckedOut
              ? 'Hoàn thành & chia tiền'
              : 'Chưa đến giờ trả phòng'}
        </button>
      </div>
    )
  }

  return (
    <>
      <Header />

      <main className="owner-bookings-page">
        <div className="container owner-bookings-container">
          <header className="owner-bookings-heading">
            <span className="owner-bookings-eyebrow">
              <ReceiptText size={17} />
              QUẢN LÝ ĐẶT PHÒNG
            </span>
            <h1>Đơn đặt phòng</h1>
            <p>
              Theo dõi khách đặt, tiền tạm giữ và hoàn thành đơn
              sau khi khách trả phòng.
            </p>
          </header>

          <nav
            className="owner-booking-filters"
            aria-label="Lọc trạng thái đơn"
          >
            {statusFilters.map((filter) => (
              <button
                key={filter.value || 'all'}
                className={
                  selectedStatus === filter.value
                    ? 'is-active'
                    : ''
                }
                type="button"
                onClick={() => changeStatusFilter(filter.value)}
              >
                {filter.label}
              </button>
            ))}
          </nav>

          {loading && (
            <section className="owner-bookings-state">
              <LoaderCircle className="owner-bookings-spinner" />
              <h2>Đang tải đơn đặt phòng...</h2>
            </section>
          )}

          {!loading && pageError && (
            <section className="owner-bookings-state is-error">
              <XCircle size={45} />
              <h2>Chưa thể mở danh sách đơn</h2>
              <p>{pageError}</p>
              <button
                type="button"
                onClick={() => loadBookings(selectedStatus)}
              >
                Thử lại
              </button>
            </section>
          )}

          {!loading && !pageError && bookings.length === 0 && (
            <section className="owner-bookings-state">
              <House size={48} />
              <h2>Không có đơn phù hợp</h2>
              <p>
                Chưa có đơn đặt phòng nào trong trạng thái này.
              </p>
            </section>
          )}

          {!loading && !pageError && bookings.length > 0 && (
            <section className="owner-booking-list">
              {bookings.map((booking) => {
                const bookingNotice =
                  notice?.bookingId === booking.id
                    ? notice
                    : null

                return (
                  <article
                    className="owner-booking-card"
                    key={booking.id}
                  >
                    <div className="owner-booking-card-top">
                      <div>
                        <small>Mã đặt phòng</small>
                        <strong>{booking.bookingCode}</strong>
                      </div>

                      <div className="owner-booking-statuses">
                        {booking.paymentStatus && (
                          <span className="owner-payment-status">
                            <WalletCards size={15} />
                            {paymentStatusLabels[
                              booking.paymentStatus
                            ] || booking.paymentStatus}
                          </span>
                        )}

                        <span
                          className={`owner-booking-status status-${booking.status}`}
                        >
                          {bookingStatusLabels[booking.status] ||
                            booking.status}
                        </span>
                      </div>
                    </div>

                    <div className="owner-booking-card-body">
                      <div className="owner-booking-main">
                        <Link
                          className="owner-booking-homestay"
                          to={`/homestays/${booking.homestay.slug}`}
                        >
                          {booking.homestay.name}
                        </Link>

                        <div className="owner-booking-guest">
                          <span className="owner-guest-avatar">
                            {booking.guest.fullName
                              ?.trim()
                              .charAt(0)
                              .toUpperCase() || 'K'}
                          </span>

                          <span>
                            <small>Khách đặt phòng</small>
                            <strong>{booking.guest.fullName}</strong>
                            <em>{booking.guest.phone || booking.guest.email}</em>
                          </span>
                        </div>

                        <div className="owner-booking-facts">
                          <div>
                            <CalendarDays size={20} />
                            <span>
                              <small>Nhận phòng</small>
                              <strong>
                                {formatDateTime(booking.checkIn)}
                              </strong>
                            </span>
                          </div>

                          <div>
                            <Clock3 size={20} />
                            <span>
                              <small>Trả phòng</small>
                              <strong>
                                {formatDateTime(booking.checkOut)}
                              </strong>
                            </span>
                          </div>

                          <div>
                            <Users size={20} />
                            <span>
                              <small>Loại đặt phòng</small>
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

                      <aside className="owner-booking-side">
                        <span>Tổng tiền</span>
                        <strong>
                          {formatPrice(booking.totalAmount)}
                        </strong>

                        <button
                          className="owner-detail-button"
                          type="button"
                          onClick={() =>
                            openBookingDetail(booking.id)
                          }
                        >
                          <Eye size={18} />
                          Xem chi tiết
                        </button>

                        {renderCompleteArea(booking)}
                      </aside>
                    </div>

                    {booking.status === 'pending_payment' && (
                      <div className="owner-booking-waiting-note">
                        Khách chưa thanh toán nên đơn chưa được xác
                        nhận.
                      </div>
                    )}

                    {bookingNotice && (
                      <div
                        className={`owner-booking-notice is-${bookingNotice.type}`}
                      >
                        {bookingNotice.type === 'success' ? (
                          <CheckCircle2 size={20} />
                        ) : (
                          <XCircle size={20} />
                        )}

                        <span>
                          <strong>{bookingNotice.message}</strong>

                          {bookingNotice.settlement && (
                            <small>
                              Chủ nhận{' '}
                              {formatPrice(
                                bookingNotice.settlement
                                  .ownerAmount,
                              )}
                              {' · '}QTV nhận{' '}
                              {formatPrice(
                                bookingNotice.settlement
                                  .platformFee,
                              )}
                            </small>
                          )}
                        </span>
                      </div>
                    )}
                  </article>
                )
              })}
            </section>
          )}
        </div>
      </main>

      <Footer />

      {detailOpen && (
        <div
          className="owner-detail-overlay"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) {
              closeBookingDetail()
            }
          }}
        >
          <section
            className="owner-detail-modal"
            role="dialog"
            aria-modal="true"
            aria-label="Chi tiết đơn đặt phòng"
          >
            <button
              className="owner-detail-close"
              type="button"
              aria-label="Đóng"
              onClick={closeBookingDetail}
              disabled={workingBookingId !== null}
            >
              <X size={23} />
            </button>

            {detailLoading && (
              <div className="owner-detail-state">
                <LoaderCircle className="owner-bookings-spinner" />
                <p>Đang tải chi tiết...</p>
              </div>
            )}

            {!detailLoading && detailError && (
              <div className="owner-detail-state is-error">
                <XCircle size={38} />
                <p>{detailError}</p>
              </div>
            )}

            {!detailLoading && selectedBooking && (
              <>
                <header className="owner-detail-heading">
                  <span>CHI TIẾT ĐƠN</span>
                  <h2>{selectedBooking.bookingCode}</h2>
                  <p>{selectedBooking.homestay.name}</p>
                </header>

                <div className="owner-detail-grid">
                  <div>
                    <UserRound size={19} />
                    <span>
                      <small>Khách hàng</small>
                      <strong>
                        {selectedBooking.guest.fullName}
                      </strong>
                    </span>
                  </div>

                  <div>
                    <Phone size={19} />
                    <span>
                      <small>Số điện thoại</small>
                      <strong>
                        {selectedBooking.guest.phone ||
                          'Chưa cập nhật'}
                      </strong>
                    </span>
                  </div>

                  <div>
                    <Mail size={19} />
                    <span>
                      <small>Email</small>
                      <strong>{selectedBooking.guest.email}</strong>
                    </span>
                  </div>

                  <div>
                    <MapPin size={19} />
                    <span>
                      <small>Địa chỉ homestay</small>
                      <strong>
                        {selectedBooking.homestay.address}
                      </strong>
                    </span>
                  </div>

                  <div>
                    <CalendarDays size={19} />
                    <span>
                      <small>Nhận phòng</small>
                      <strong>
                        {formatDateTime(selectedBooking.checkIn)}
                      </strong>
                    </span>
                  </div>

                  <div>
                    <Clock3 size={19} />
                    <span>
                      <small>Trả phòng</small>
                      <strong>
                        {formatDateTime(selectedBooking.checkOut)}
                      </strong>
                    </span>
                  </div>
                </div>

                <div className="owner-detail-payment">
                  <div>
                    <span>Trạng thái đơn</span>
                    <strong>
                      {bookingStatusLabels[
                        selectedBooking.status
                      ] || selectedBooking.status}
                    </strong>
                  </div>

                  <div>
                    <span>Tổng tiền</span>
                    <strong>
                      {formatPrice(selectedBooking.totalAmount)}
                    </strong>
                  </div>

                  {selectedBooking.payment && (
                    <>
                      <div>
                        <span>Phương thức</span>
                        <strong>
                          {paymentMethodLabels[
                            selectedBooking.payment.paymentMethod
                          ] ||
                            selectedBooking.payment.paymentMethod}
                        </strong>
                      </div>

                      <div>
                        <span>Mã giao dịch</span>
                        <strong>
                          {
                            selectedBooking.payment
                              .transactionCode
                          }
                        </strong>
                      </div>
                    </>
                  )}
                </div>

                {notice?.bookingId === selectedBooking.id && (
                  <div
                    className={`owner-booking-notice is-${notice.type}`}
                  >
                    {notice.type === 'success' ? (
                      <CheckCircle2 size={20} />
                    ) : (
                      <XCircle size={20} />
                    )}
                    <span>
                      <strong>{notice.message}</strong>
                      {notice.settlement && (
                        <small>
                          Chủ nhận{' '}
                          {formatPrice(
                            notice.settlement.ownerAmount,
                          )}
                          {' · '}QTV nhận{' '}
                          {formatPrice(
                            notice.settlement.platformFee,
                          )}
                        </small>
                      )}
                    </span>
                  </div>
                )}

                {renderCompleteArea(selectedBooking)}
              </>
            )}
          </section>
        </div>
      )}
    </>
  )
}

export default OwnerBookingsPage
