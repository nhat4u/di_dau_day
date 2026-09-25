import { useCallback, useEffect, useState } from 'react'
import {
  Banknote,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  Eye,
  House,
  LoaderCircle,
  Mail,
  MapPin,
  Phone,
  ReceiptText,
  Search,
  ShieldCheck,
  UserRound,
  X,
  XCircle,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api, { getApiErrorMessage } from '../services/api'
import '../styles/admin-bookings.css'

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
  disputed: 'Đang xử lý hoàn tiền',
  completed: 'Đã hoàn thành',
  cancelled: 'Đã hủy',
  refunded: 'Đã hoàn tiền',
}

const paymentStatusLabels = {
  held: 'Tiền đang tạm giữ',
  settled: 'Đã chia tiền',
  refunded: 'Đã hoàn tiền',
}

const statusFilters = [
  { value: '', label: 'Tất cả' },
  { value: 'pending_payment', label: 'Chờ thanh toán' },
  { value: 'confirmed', label: 'Đã xác nhận' },
  { value: 'funds_held', label: 'Đang giữ tiền' },
  { value: 'disputed', label: 'Yêu cầu hoàn tiền' },
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

function AdminBookingsPage() {
  const navigate = useNavigate()

  const [bookings, setBookings] = useState([])
  const [selectedStatus, setSelectedStatus] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')

  const [detailOpen, setDetailOpen] = useState(false)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')
  const [selectedBooking, setSelectedBooking] = useState(null)

  const loadBookings = useCallback(async () => {
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
      setLoading(true)
      setPageError('')

      const response = await api.get('/admin/bookings', {
        params: {
          ...(selectedStatus ? { status: selectedStatus } : {}),
          ...(search ? { search } : {}),
          page,
          pageSize: 15,
        },
      })

      setBookings(response.data.bookings || [])
      setTotalPages(response.data.totalPages || 1)
      setTotal(response.data.total || 0)
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
          'Không thể tải danh sách đơn đặt phòng.',
        ),
      )
    } finally {
      setLoading(false)
    }
  }, [navigate, selectedStatus, search, page])

  useEffect(() => {
    loadBookings()
  }, [loadBookings])

  useEffect(() => {
    if (!detailOpen) {
      return undefined
    }

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    function closeWithEscape(event) {
      if (event.key === 'Escape') {
        setDetailOpen(false)
      }
    }

    window.addEventListener('keydown', closeWithEscape)

    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', closeWithEscape)
    }
  }, [detailOpen])

  function changeStatusFilter(status) {
    setSelectedStatus(status)
    setPage(1)
  }

  function submitSearch(event) {
    event.preventDefault()
    setSearch(searchInput.trim())
    setPage(1)
  }

  async function openBookingDetail(bookingId) {
    try {
      setSelectedBooking(null)
      setDetailError('')
      setDetailLoading(true)
      setDetailOpen(true)

      const response = await api.get(
        `/admin/bookings/${bookingId}`,
      )

      setSelectedBooking(response.data.booking)
    } catch (requestError) {
      setDetailError(
        getApiErrorMessage(
          requestError,
          'Không thể tải chi tiết đơn đặt phòng.',
        ),
      )
    } finally {
      setDetailLoading(false)
    }
  }

  function closeBookingDetail() {
    setDetailOpen(false)
  }

  return (
    <>
      <Header />

      <main className="admin-bookings-page">
        <div className="container admin-bookings-container">
          <header className="admin-bookings-heading">
            <div>
              <span>
                <ShieldCheck size={18} />
                QUẢN TRỊ ĐƠN ĐẶT PHÒNG
              </span>
              <h1>Toàn bộ đơn đặt phòng</h1>
              <p>
                Theo dõi mọi đơn đặt phòng trên toàn hệ thống, không
                phân biệt chủ home nào — {total} đơn.
              </p>
            </div>

            <form
              className="admin-bookings-search"
              onSubmit={submitSearch}
            >
              <Search size={17} />
              <input
                type="text"
                value={searchInput}
                onChange={(event) =>
                  setSearchInput(event.target.value)
                }
                placeholder="Mã đơn, tên khách, email, homestay..."
              />
              {searchInput && (
                <button
                  type="button"
                  className="admin-bookings-search-clear"
                  onClick={() => {
                    setSearchInput('')
                    setSearch('')
                    setPage(1)
                  }}
                >
                  <X size={15} />
                </button>
              )}
              <button type="submit">Tìm</button>
            </form>
          </header>

          <nav
            className="admin-booking-filters"
            aria-label="Lọc trạng thái đơn"
          >
            {statusFilters.map((filter) => (
              <button
                key={filter.value || 'all'}
                className={
                  selectedStatus === filter.value ? 'is-active' : ''
                }
                type="button"
                onClick={() => changeStatusFilter(filter.value)}
              >
                {filter.label}
              </button>
            ))}
          </nav>

          {loading && (
            <section className="admin-bookings-state">
              <LoaderCircle className="admin-bookings-spinner" />
              <h2>Đang tải đơn đặt phòng...</h2>
            </section>
          )}

          {!loading && pageError && (
            <section className="admin-bookings-state is-error">
              <XCircle size={45} />
              <h2>Chưa thể mở danh sách đơn</h2>
              <p>{pageError}</p>
              <button type="button" onClick={() => loadBookings()}>
                Thử lại
              </button>
            </section>
          )}

          {!loading && !pageError && bookings.length === 0 && (
            <section className="admin-bookings-state">
              <House size={48} />
              <h2>Không có đơn phù hợp</h2>
              <p>
                Chưa có đơn đặt phòng nào khớp với bộ lọc hiện tại.
              </p>
            </section>
          )}

          {!loading && !pageError && bookings.length > 0 && (
            <>
              <section className="admin-booking-table">
                <div className="admin-booking-table-head">
                  <span>Mã đơn / Homestay</span>
                  <span>Khách hàng</span>
                  <span>Chủ home</span>
                  <span>Nhận - Trả phòng</span>
                  <span>Số tiền</span>
                  <span>Trạng thái</span>
                  <span></span>
                </div>

                {bookings.map((booking) => (
                  <div
                    className="admin-booking-table-row"
                    key={booking.id}
                  >
                    <div className="admin-booking-cell-main">
                      <strong>{booking.bookingCode}</strong>
                      <small>{booking.homestay?.name}</small>
                    </div>

                    <div className="admin-booking-cell-guest">
                      <strong>{booking.guest?.fullName}</strong>
                      <small>{booking.guest?.email}</small>
                    </div>

                    <div className="admin-booking-cell-owner">
                      {booking.homestay?.ownerName}
                    </div>

                    <div className="admin-booking-cell-dates">
                      <span>
                        <CalendarDays size={13} />
                        {formatDateTime(booking.checkIn)}
                      </span>
                      <span>
                        <CalendarDays size={13} />
                        {formatDateTime(booking.checkOut)}
                      </span>
                    </div>

                    <div className="admin-booking-cell-amount">
                      <strong>
                        {formatPrice(booking.totalAmount)}
                      </strong>
                      {booking.paymentStatus && (
                        <small>
                          {paymentStatusLabels[
                            booking.paymentStatus
                          ] || booking.paymentStatus}
                        </small>
                      )}
                    </div>

                    <div className="admin-booking-cell-status">
                      <span
                        className={
                          'admin-booking-status status-' +
                          booking.status
                        }
                      >
                        {bookingStatusLabels[booking.status] ||
                          booking.status}
                      </span>
                      {booking.latestRefundStatus && (
                        <small className="admin-booking-refund-flag">
                          Hoàn tiền:{' '}
                          {booking.latestRefundStatus}
                        </small>
                      )}
                    </div>

                    <div className="admin-booking-cell-action">
                      <button
                        type="button"
                        onClick={() =>
                          openBookingDetail(booking.id)
                        }
                      >
                        <Eye size={17} />
                      </button>
                    </div>
                  </div>
                ))}
              </section>

              {totalPages > 1 && (
                <nav className="admin-bookings-pagination">
                  <button
                    type="button"
                    disabled={page <= 1}
                    onClick={() =>
                      setPage((current) => Math.max(1, current - 1))
                    }
                  >
                    <ChevronLeft size={18} />
                  </button>

                  <span>
                    Trang {page} / {totalPages}
                  </span>

                  <button
                    type="button"
                    disabled={page >= totalPages}
                    onClick={() =>
                      setPage((current) =>
                        Math.min(totalPages, current + 1),
                      )
                    }
                  >
                    <ChevronRight size={18} />
                  </button>
                </nav>
              )}
            </>
          )}
        </div>
      </main>

      <Footer />

      {detailOpen && (
        <div
          className="admin-booking-detail-backdrop"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) {
              closeBookingDetail()
            }
          }}
        >
          <section
            className="admin-booking-detail-modal"
            role="dialog"
            aria-modal="true"
          >
            <header>
              <div>
                <ReceiptText size={19} />
                <h2>Chi tiết đơn đặt phòng</h2>
              </div>
              <button type="button" onClick={closeBookingDetail}>
                <X size={22} />
              </button>
            </header>

            {detailLoading && (
              <div className="admin-booking-detail-state">
                <LoaderCircle className="admin-bookings-spinner" />
                <p>Đang tải chi tiết...</p>
              </div>
            )}

            {!detailLoading && detailError && (
              <div className="admin-booking-detail-state is-error">
                <XCircle size={38} />
                <p>{detailError}</p>
              </div>
            )}

            {!detailLoading && !detailError && selectedBooking && (
              <div className="admin-booking-detail-body">
                <div className="admin-booking-detail-topline">
                  <div>
                    <small>Mã đơn</small>
                    <strong>{selectedBooking.bookingCode}</strong>
                  </div>
                  <span
                    className={
                      'admin-booking-status status-' +
                      selectedBooking.status
                    }
                  >
                    {bookingStatusLabels[selectedBooking.status] ||
                      selectedBooking.status}
                  </span>
                </div>

                <div className="admin-booking-detail-grid">
                  <section>
                    <h3>
                      <House size={17} />
                      Homestay
                    </h3>
                    <p className="admin-booking-detail-name">
                      {selectedBooking.homestay?.name}
                    </p>
                    <div className="admin-booking-detail-line">
                      <MapPin size={15} />
                      {selectedBooking.homestay?.address},{' '}
                      {selectedBooking.homestay?.province}
                    </div>
                    <div className="admin-booking-detail-line">
                      <UserRound size={15} />
                      Chủ home:{' '}
                      {selectedBooking.homestay?.owner?.fullName}
                    </div>
                    <div className="admin-booking-detail-line">
                      <Phone size={15} />
                      {selectedBooking.homestay?.owner?.phone}
                    </div>
                  </section>

                  <section>
                    <h3>
                      <UserRound size={17} />
                      Khách hàng
                    </h3>
                    <p className="admin-booking-detail-name">
                      {selectedBooking.guest?.fullName}
                    </p>
                    <div className="admin-booking-detail-line">
                      <Mail size={15} />
                      {selectedBooking.guest?.email}
                    </div>
                    <div className="admin-booking-detail-line">
                      <Phone size={15} />
                      {selectedBooking.guest?.phone}
                    </div>
                  </section>
                </div>

                <div className="admin-booking-detail-facts">
                  <div>
                    <small>Loại hình</small>
                    <strong>
                      {bookingTypeLabels[
                        selectedBooking.bookingType
                      ] || selectedBooking.bookingType}
                    </strong>
                  </div>
                  <div>
                    <small>Nhận phòng</small>
                    <strong>
                      {formatDateTime(selectedBooking.checkIn)}
                    </strong>
                  </div>
                  <div>
                    <small>Trả phòng</small>
                    <strong>
                      {formatDateTime(selectedBooking.checkOut)}
                    </strong>
                  </div>
                  <div>
                    <small>Số khách</small>
                    <strong>{selectedBooking.guestCount}</strong>
                  </div>
                  <div className="is-total">
                    <small>Tổng tiền</small>
                    <strong>
                      {formatPrice(selectedBooking.totalAmount)}
                    </strong>
                  </div>
                </div>

                {selectedBooking.payment && (
                  <section className="admin-booking-detail-panel">
                    <h3>
                      <Banknote size={17} />
                      Thanh toán
                    </h3>
                    <div className="admin-booking-detail-facts">
                      <div>
                        <small>Phương thức</small>
                        <strong>
                          {selectedBooking.payment.paymentMethod}
                        </strong>
                      </div>
                      <div>
                        <small>Trạng thái</small>
                        <strong>
                          {paymentStatusLabels[
                            selectedBooking.payment.status
                          ] || selectedBooking.payment.status}
                        </strong>
                      </div>
                      <div>
                        <small>Số tiền</small>
                        <strong>
                          {formatPrice(
                            selectedBooking.payment.amount,
                          )}
                        </strong>
                      </div>
                      <div>
                        <small>Thanh toán lúc</small>
                        <strong>
                          {formatDateTime(
                            selectedBooking.payment.paidAt,
                          )}
                        </strong>
                      </div>
                    </div>
                  </section>
                )}

                {selectedBooking.settlement && (
                  <section className="admin-booking-detail-panel">
                    <h3>
                      <ClipboardList size={17} />
                      Chia tiền
                    </h3>
                    <div className="admin-booking-detail-facts">
                      <div>
                        <small>Tổng tiền</small>
                        <strong>
                          {formatPrice(
                            selectedBooking.settlement.grossAmount,
                          )}
                        </strong>
                      </div>
                      <div>
                        <small>Phí nền tảng</small>
                        <strong>
                          {formatPrice(
                            selectedBooking.settlement.platformFee,
                          )}
                        </strong>
                      </div>
                      <div className="is-total">
                        <small>Chủ home nhận</small>
                        <strong>
                          {formatPrice(
                            selectedBooking.settlement.ownerAmount,
                          )}
                        </strong>
                      </div>
                    </div>
                  </section>
                )}

                {selectedBooking.refundRequests?.length > 0 && (
                  <section className="admin-booking-detail-panel">
                    <h3>Yêu cầu hoàn tiền</h3>
                    {selectedBooking.refundRequests.map((item) => (
                      <div
                        className="admin-booking-refund-item"
                        key={item.id}
                      >
                        <div>
                          <strong>
                            {formatPrice(item.refundAmount)}
                          </strong>
                          <span
                            className={
                              'admin-booking-status status-' +
                              item.status
                            }
                          >
                            {item.status}
                          </span>
                        </div>
                        <small>{item.description || item.reason}</small>
                      </div>
                    ))}
                  </section>
                )}
              </div>
            )}
          </section>
        </div>
      )}
    </>
  )
}

export default AdminBookingsPage
