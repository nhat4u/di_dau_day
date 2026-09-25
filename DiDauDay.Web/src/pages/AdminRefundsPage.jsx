import { useCallback, useEffect, useState } from 'react'
import {
  AlertCircle,
  ArrowLeft,
  CalendarClock,
  CheckCircle2,
  Clock3,
  HandCoins,
  LoaderCircle,
  Mail,
  Phone,
  ReceiptText,
  RefreshCw,
  ShieldCheck,
  UserRound,
  X,
  XCircle,
} from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api, { getApiErrorMessage } from '../services/api'
import '../styles/admin-refunds.css'

const statusFilters = [
  { value: 'all', label: 'Tất cả' },
  { value: 'pending', label: 'Chờ xử lý' },
  { value: 'completed', label: 'Đã hoàn tiền' },
  { value: 'rejected', label: 'Đã từ chối' },
]

const statusLabels = {
  pending: 'Chờ QTV xử lý',
  approved: 'Chờ hoàn tất',
  completed: 'Đã hoàn tiền',
  rejected: 'Đã từ chối',
}

const reasonLabels = {
  guest_cancelled: 'Khách muốn hủy chuyến đi',
  host_cancelled: 'Chủ homestay hủy/không thể đón khách',
  power_outage: 'Mất điện, mất nước hoặc sự cố tiện ích',
  service_issue: 'Phòng hoặc dịch vụ có vấn đề',
  other: 'Lý do khác',
}

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
  return (
    new Intl.NumberFormat('vi-VN').format(Number(value || 0)) + 'đ'
  )
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

function formatPolicyTime(value) {
  const hours = Number(value)

  if (!Number.isFinite(hours)) {
    return '--'
  }

  if (hours < 0) {
    return (
      'Gửi sau giờ nhận phòng ' +
      Math.abs(hours).toFixed(1) +
      ' giờ'
    )
  }

  const days = Math.floor(hours / 24)
  const remainingHours = Math.floor(hours % 24)

  if (days > 0) {
    return days + ' ngày ' + remainingHours + ' giờ trước nhận phòng'
  }

  return Math.floor(hours) + ' giờ trước nhận phòng'
}

function formatRequestTiming(refundRequest) {
  const requestedAt = new Date(refundRequest.createdAt)
  const checkIn = new Date(refundRequest.checkIn)
  const checkOut = new Date(refundRequest.checkOut)

  if (
    !Number.isNaN(requestedAt.getTime()) &&
    !Number.isNaN(checkOut.getTime()) &&
    requestedAt >= checkOut
  ) {
    const hoursAfterCheckOut =
      (requestedAt.getTime() - checkOut.getTime()) / 3600000
    return (
      'Gửi sau khi trả phòng ' +
      hoursAfterCheckOut.toFixed(1) +
      ' giờ'
    )
  }

  if (
    !Number.isNaN(requestedAt.getTime()) &&
    !Number.isNaN(checkIn.getTime()) &&
    requestedAt >= checkIn
  ) {
    const hoursAfterCheckIn =
      (requestedAt.getTime() - checkIn.getTime()) / 3600000
    return (
      'Gửi sau khi nhận phòng ' +
      hoursAfterCheckIn.toFixed(1) +
      ' giờ'
    )
  }

  return formatPolicyTime(refundRequest.hoursBeforeCheckIn)
}

function calculateRefundPercentage(refundAmount, paymentAmount) {
  const amount = Number(refundAmount)
  const paidAmount = Number(paymentAmount)

  if (
    !Number.isFinite(amount) ||
    !Number.isFinite(paidAmount) ||
    paidAmount <= 0
  ) {
    return ''
  }

  return String(
    Math.min(
      100,
      Math.max(0, Math.round((amount / paidAmount) * 10000) / 100),
    ),
  )
}

function AdminRefundsPage() {
  const navigate = useNavigate()
  const [filter, setFilter] = useState('pending')
  const [refundRequests, setRefundRequests] = useState([])
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [workingAction, setWorkingAction] = useState('')
  const [notice, setNotice] = useState(null)
  const [decisionNotes, setDecisionNotes] = useState({})
  const [refundAmounts, setRefundAmounts] = useState({})
  const [refundPercentages, setRefundPercentages] = useState({})
  const [confirmation, setConfirmation] = useState(null)

  const loadRefundRequests = useCallback(async (options = {}) => {
    const { showLoading = true, showError = true } = options
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
      }

      if (showError) {
        setPageError('')
      }

      const response = await api.get('/admin/refunds', {
        params: filter === 'all' ? {} : { status: filter },
      })
      const items = response.data.refundRequests || []

      setRefundRequests(items)
      setRefundAmounts((currentAmounts) => {
        const nextAmounts = { ...currentAmounts }

        items.forEach((item) => {
          if (nextAmounts[item.id] === undefined) {
            nextAmounts[item.id] = String(item.refundAmount || '')
          }
        })

        return nextAmounts
      })
      setRefundPercentages((currentPercentages) => {
        const nextPercentages = { ...currentPercentages }

        items.forEach((item) => {
          if (nextPercentages[item.id] === undefined) {
            nextPercentages[item.id] = calculateRefundPercentage(
              item.refundAmount,
              item.paymentAmount,
            )
          }
        })

        return nextPercentages
      })
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        localStorage.removeItem('accessToken')
        localStorage.removeItem('authUser')
        navigate('/login', { replace: true })
        return
      }

      if (showError) {
        setPageError(
          getApiErrorMessage(
            requestError,
            'Không thể tải danh sách yêu cầu hoàn tiền.',
          ),
        )
      }
    } finally {
      if (showLoading) {
        setLoading(false)
      }
    }
  }, [filter, navigate])

  useEffect(() => {
    loadRefundRequests()

    const timer = window.setInterval(() => {
      loadRefundRequests({
        showLoading: false,
        showError: false,
      })
    }, 5000)

    return () => {
      window.clearInterval(timer)
    }
  }, [loadRefundRequests])

  function updateNote(requestId, value) {
    setDecisionNotes((currentNotes) => ({
      ...currentNotes,
      [requestId]: value,
    }))
    setNotice(null)
  }

  function updateAmount(refundRequest, value) {
    setRefundAmounts((currentAmounts) => ({
      ...currentAmounts,
      [refundRequest.id]: value,
    }))
    setRefundPercentages((currentPercentages) => ({
      ...currentPercentages,
      [refundRequest.id]: calculateRefundPercentage(
        value,
        refundRequest.paymentAmount,
      ),
    }))
    setNotice(null)
  }

  function updatePercentage(refundRequest, value) {
    setRefundPercentages((currentPercentages) => ({
      ...currentPercentages,
      [refundRequest.id]: value,
    }))

    const percentage = Number(value)
    const paymentAmount = Number(refundRequest.paymentAmount)

    if (
      Number.isFinite(percentage) &&
      percentage >= 0 &&
      percentage <= 100 &&
      Number.isFinite(paymentAmount)
    ) {
      setRefundAmounts((currentAmounts) => ({
        ...currentAmounts,
        [refundRequest.id]: String(
          Math.round((paymentAmount * percentage) / 100),
        ),
      }))
    }

    setNotice(null)
  }

  function askToApproveRefund(refundRequest) {
    const isIncident =
      refundRequest.reason !== 'guest_cancelled'
    const amount = Number(
      refundAmounts[refundRequest.id] ||
        refundRequest.refundAmount,
    )

    if (
      isIncident &&
      (!Number.isFinite(amount) ||
        amount <= 0 ||
        amount > Number(refundRequest.paymentAmount))
    ) {
      setNotice({
        requestId: refundRequest.id,
        type: 'error',
        message:
          'Số tiền hoàn phải lớn hơn 0 và không vượt quá số tiền khách đã thanh toán.',
      })
      return
    }

    setConfirmation({
      type: 'approve',
      refundRequest,
      amount: isIncident ? amount : refundRequest.refundAmount,
      percentage: isIncident
        ? refundPercentages[refundRequest.id]
        : refundRequest.refundPercentage,
      adminNote:
        decisionNotes[refundRequest.id]?.trim() || null,
    })
  }

  async function approveRefund(confirmationData) {
    const { refundRequest, amount, adminNote } = confirmationData
    const isIncident = refundRequest.reason !== 'guest_cancelled'

    const actionKey = 'approve-' + refundRequest.id

    try {
      setWorkingAction(actionKey)
      setNotice(null)

      const response = await api.patch(
        '/admin/refunds/' + refundRequest.id + '/approve',
        {
          refundAmount: isIncident ? amount : null,
          adminNote,
        },
      )

      setNotice({
        requestId: refundRequest.id,
        type: 'success',
        message:
          response.data.message ||
          'Đã duyệt và hoàn tiền cho khách.',
      })
      await loadRefundRequests({
        showLoading: false,
        showError: false,
      })
    } catch (requestError) {
      setNotice({
        requestId: refundRequest.id,
        type: 'error',
        message: getApiErrorMessage(
          requestError,
          'Không thể duyệt yêu cầu hoàn tiền.',
        ),
      })
    } finally {
      setWorkingAction('')
      setConfirmation(null)
    }
  }

  function askToRejectRefund(refundRequest) {
    const adminNote =
      decisionNotes[refundRequest.id]?.trim() || ''

    if (!adminNote) {
      setNotice({
        requestId: refundRequest.id,
        type: 'error',
        message: 'Vui lòng nhập lý do từ chối để khách hàng biết.',
      })
      return
    }

    setConfirmation({
      type: 'reject',
      refundRequest,
      adminNote,
    })
  }

  async function rejectRefund(confirmationData) {
    const { refundRequest, adminNote } = confirmationData

    const actionKey = 'reject-' + refundRequest.id

    try {
      setWorkingAction(actionKey)
      setNotice(null)

      const response = await api.patch(
        '/admin/refunds/' + refundRequest.id + '/reject',
        { adminNote },
      )

      setNotice({
        requestId: refundRequest.id,
        type: 'success',
        message:
          response.data.message ||
          'Đã từ chối yêu cầu hoàn tiền.',
      })
      await loadRefundRequests({
        showLoading: false,
        showError: false,
      })
    } catch (requestError) {
      setNotice({
        requestId: refundRequest.id,
        type: 'error',
        message: getApiErrorMessage(
          requestError,
          'Không thể từ chối yêu cầu hoàn tiền.',
        ),
      })
    } finally {
      setWorkingAction('')
      setConfirmation(null)
    }
  }

  function closeConfirmation() {
    if (!workingAction) {
      setConfirmation(null)
    }
  }

  async function confirmDecision() {
    if (!confirmation) {
      return
    }

    if (confirmation.type === 'approve') {
      await approveRefund(confirmation)
      return
    }

    await rejectRefund(confirmation)
  }

  return (
    <>
      <Header />

      <main className="admin-refunds-page">
        <div className="container admin-refunds-container">
          <Link className="admin-refunds-back" to="/">
            <ArrowLeft size={18} />
            Về trang chủ
          </Link>

          <header className="admin-refunds-heading">
            <div>
              <span>
                <ShieldCheck size={18} />
                QUẢN TRỊ HOÀN TIỀN
              </span>
              <h1>Yêu cầu hoàn tiền</h1>
              <p>
                Kiểm tra thời điểm hủy, chính sách áp dụng và quyết
                định số tiền hoàn cho khách.
              </p>
            </div>

            <button
              type="button"
              onClick={() => loadRefundRequests()}
              disabled={loading}
            >
              <RefreshCw size={18} />
              Làm mới
            </button>
          </header>

          <nav className="admin-refund-filters">
            {statusFilters.map((item) => (
              <button
                key={item.value}
                className={filter === item.value ? 'active' : ''}
                type="button"
                onClick={() => {
                  setFilter(item.value)
                  setNotice(null)
                }}
              >
                {item.label}
              </button>
            ))}
          </nav>

          {loading && (
            <section className="admin-refunds-state">
              <LoaderCircle className="admin-refunds-spinner" />
              <h2>Đang tải yêu cầu hoàn tiền...</h2>
            </section>
          )}

          {!loading && pageError && (
            <section className="admin-refunds-state is-error">
              <XCircle size={46} />
              <h2>Chưa thể mở danh sách</h2>
              <p>{pageError}</p>
              <button
                type="button"
                onClick={() => loadRefundRequests()}
              >
                Thử lại
              </button>
            </section>
          )}

          {!loading &&
            !pageError &&
            refundRequests.length === 0 && (
              <section className="admin-refunds-state">
                <HandCoins size={48} />
                <h2>Không có yêu cầu phù hợp</h2>
                <p>
                  Danh sách sẽ tự động cập nhật khi khách gửi yêu
                  cầu mới.
                </p>
              </section>
            )}

          {!loading &&
            !pageError &&
            refundRequests.length > 0 && (
              <section className="admin-refund-list">
                {refundRequests.map((refundRequest) => {
                  const isPending =
                    refundRequest.status === 'pending' ||
                    refundRequest.status === 'approved'
                  const isIncident =
                    refundRequest.reason !== 'guest_cancelled'
                  const requestNotice =
                    notice?.requestId === refundRequest.id
                      ? notice
                      : null
                  const isApproving =
                    workingAction ===
                    'approve-' + refundRequest.id
                  const isRejecting =
                    workingAction ===
                    'reject-' + refundRequest.id

                  return (
                    <article
                      className="admin-refund-card"
                      key={refundRequest.id}
                    >
                      <header className="admin-refund-card-header">
                        <div>
                          <small>Mã đặt phòng</small>
                          <strong>
                            {refundRequest.bookingCode}
                          </strong>
                        </div>
                        <span
                          className={
                            'admin-refund-status status-' +
                            refundRequest.status
                          }
                        >
                          {statusLabels[refundRequest.status] ||
                            refundRequest.status}
                        </span>
                      </header>

                      <div className="admin-refund-card-body">
                        <section className="admin-refund-information">
                          <div className="admin-refund-title">
                            <ReceiptText size={22} />
                            <div>
                              <h2>{refundRequest.homestayName}</h2>
                              <p>
                                {bookingTypeLabels[
                                  refundRequest.bookingType
                                ] || refundRequest.bookingType}
                              </p>
                            </div>
                          </div>

                          <div className="admin-refund-facts">
                            <div>
                              <CalendarClock size={19} />
                              <span>
                                <small>Nhận phòng</small>
                                <strong>
                                  {formatDateTime(
                                    refundRequest.checkIn,
                                  )}
                                </strong>
                              </span>
                            </div>
                            <div>
                              <Clock3 size={19} />
                              <span>
                                <small>Trả phòng</small>
                                <strong>
                                  {formatDateTime(
                                    refundRequest.checkOut,
                                  )}
                                </strong>
                              </span>
                            </div>
                            <div>
                              <Clock3 size={19} />
                              <span>
                                <small>Gửi yêu cầu</small>
                                <strong>
                                  {formatDateTime(
                                    refundRequest.createdAt,
                                  )}
                                </strong>
                              </span>
                            </div>
                          </div>

                          <div className="admin-refund-guest">
                            <div>
                              <UserRound size={18} />
                              <span>
                                <small>Khách hàng</small>
                                <strong>
                                  {
                                    refundRequest.requestedBy
                                      .fullName
                                  }
                                </strong>
                              </span>
                            </div>
                            <div>
                              <Mail size={18} />
                              {
                                refundRequest.requestedBy
                                  .email
                              }
                            </div>
                            <div>
                              <Phone size={18} />
                              {refundRequest.requestedBy.phone ||
                                'Chưa có số điện thoại'}
                            </div>
                          </div>

                          <div className="admin-refund-reason">
                            <small>Lý do khách gửi</small>
                            <strong>
                              {reasonLabels[refundRequest.reason] ||
                                refundRequest.reason}
                            </strong>
                            <p>
                              {refundRequest.description ||
                                'Khách không nhập mô tả bổ sung.'}
                            </p>
                          </div>
                        </section>

                        <aside className="admin-refund-policy">
                          <h3>Chính sách và số tiền</h3>
                          <dl>
                            <div>
                              <dt>Thời điểm yêu cầu</dt>
                              <dd>
                                {formatRequestTiming(refundRequest)}
                              </dd>
                            </div>
                            <div>
                              <dt>Chính sách</dt>
                              <dd>{refundRequest.policyLabel}</dd>
                            </div>
                            <div>
                              <dt>Khách đã thanh toán</dt>
                              <dd>
                                {formatPrice(
                                  refundRequest.paymentAmount,
                                )}
                              </dd>
                            </div>
                            <div className="is-total">
                              <dt>
                                {isIncident
                                  ? 'Mức QTV đang chọn'
                                  : 'Mức hoàn theo chính sách'}
                              </dt>
                              <dd>
                                {formatPrice(
                                  isIncident
                                    ? refundAmounts[
                                        refundRequest.id
                                      ] ??
                                        refundRequest.refundAmount
                                    : refundRequest.refundAmount,
                                )}
                              </dd>
                            </div>
                          </dl>
                        </aside>
                      </div>

                      {requestNotice && (
                        <div
                          className={
                            'admin-refund-notice is-' +
                            requestNotice.type
                          }
                        >
                          {requestNotice.type === 'success' ? (
                            <CheckCircle2 size={19} />
                          ) : (
                            <AlertCircle size={19} />
                          )}
                          {requestNotice.message}
                        </div>
                      )}

                      {isPending && (
                        <div className="admin-refund-review">
                          <div className="admin-refund-decision">
                            {isIncident ? (
                              <>
                                <label>
                                  <span>
                                    Tỷ lệ QTV quyết định hoàn
                                  </span>
                                  <div className="admin-refund-percent-input">
                                    <input
                                      type="number"
                                      min="1"
                                      max="100"
                                      step="1"
                                      value={
                                        refundPercentages[
                                          refundRequest.id
                                        ] ?? ''
                                      }
                                      onChange={(event) =>
                                        updatePercentage(
                                          refundRequest,
                                          event.target.value,
                                        )
                                      }
                                      disabled={Boolean(
                                        workingAction,
                                      )}
                                    />
                                    <strong>%</strong>
                                  </div>
                                </label>

                                <div className="admin-refund-percent-presets">
                                  {[25, 50, 75, 100].map(
                                    (percentage) => (
                                      <button
                                        className={
                                          Number(
                                            refundPercentages[
                                              refundRequest.id
                                            ],
                                          ) === percentage
                                            ? 'active'
                                            : ''
                                        }
                                        key={percentage}
                                        type="button"
                                        onClick={() =>
                                          updatePercentage(
                                            refundRequest,
                                            String(percentage),
                                          )
                                        }
                                        disabled={Boolean(
                                          workingAction,
                                        )}
                                      >
                                        {percentage}%
                                      </button>
                                    ),
                                  )}
                                </div>

                                <label>
                                  <span>
                                    Số tiền hoàn tương ứng
                                  </span>
                                  <input
                                    type="number"
                                    min="1"
                                    max={refundRequest.paymentAmount}
                                    step="1000"
                                    value={
                                      refundAmounts[
                                        refundRequest.id
                                      ] ??
                                      refundRequest.refundAmount
                                    }
                                    onChange={(event) =>
                                      updateAmount(
                                        refundRequest,
                                        event.target.value,
                                      )
                                    }
                                    disabled={Boolean(
                                      workingAction,
                                    )}
                                  />
                                </label>

                                <small>
                                  Áp dụng cho sự cố phòng, dịch vụ
                                  hoặc yêu cầu phát sinh sau khi nhận
                                  phòng. Tối đa{' '}
                                  {formatPrice(
                                    refundRequest.paymentAmount,
                                  )}.
                                </small>
                              </>
                            ) : (
                              <div className="admin-refund-fixed-amount">
                                <span>
                                  Mức hoàn theo chính sách
                                </span>
                                <strong>
                                  {refundRequest.refundPercentage}%
                                  {' · '}
                                  {formatPrice(
                                    refundRequest.refundAmount,
                                  )}
                                </strong>
                                <small>
                                  Hệ thống tự tính theo thời điểm
                                  khách gửi yêu cầu; QTV không thể sửa
                                  mức hoàn này.
                                </small>
                              </div>
                            )}
                          </div>

                          <label className="admin-refund-note-field">
                            <span>
                              Ghi chú quyết định (bắt buộc khi từ
                              chối)
                            </span>
                            <textarea
                              rows={3}
                              maxLength={1000}
                              value={
                                decisionNotes[refundRequest.id] || ''
                              }
                              onChange={(event) =>
                                updateNote(
                                  refundRequest.id,
                                  event.target.value,
                                )
                              }
                              placeholder="Nhập căn cứ duyệt hoặc lý do từ chối..."
                              disabled={Boolean(workingAction)}
                            />
                          </label>

                          <div className="admin-refund-actions">
                            <button
                              className="reject-button"
                              type="button"
                              onClick={() =>
                                askToRejectRefund(refundRequest)
                              }
                              disabled={Boolean(workingAction)}
                            >
                              <XCircle size={18} />
                              {isRejecting
                                ? 'Đang từ chối...'
                                : 'Từ chối'}
                            </button>
                            <button
                              className="approve-button"
                              type="button"
                              onClick={() =>
                                askToApproveRefund(refundRequest)
                              }
                              disabled={Boolean(workingAction)}
                            >
                              <CheckCircle2 size={18} />
                              {isApproving
                                ? 'Đang xử lý...'
                                : 'Duyệt & hoàn tiền'}
                            </button>
                          </div>
                        </div>
                      )}

                      {!isPending && (
                        <div
                          className={
                            'admin-refund-result is-' +
                            refundRequest.status
                          }
                        >
                          {refundRequest.status === 'completed' ? (
                            <CheckCircle2 size={20} />
                          ) : (
                            <XCircle size={20} />
                          )}
                          <span>
                            <strong>
                              {refundRequest.status === 'completed'
                                ? 'Đã hoàn ' +
                                  formatPrice(
                                    refundRequest.refundAmount,
                                  ) +
                                  ' cho khách.'
                                : 'Yêu cầu đã bị từ chối.'}
                            </strong>
                            {refundRequest.adminNote && (
                              <small>
                                Ghi chú: {refundRequest.adminNote}
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

      {confirmation && (
        <div
          className="admin-refund-dialog-backdrop"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) {
              closeConfirmation()
            }
          }}
        >
          <section
            aria-labelledby="refund-confirmation-title"
            aria-modal="true"
            className={
              'admin-refund-dialog is-' + confirmation.type
            }
            role="dialog"
          >
            <button
              aria-label="Đóng hộp xác nhận"
              className="admin-refund-dialog-close"
              type="button"
              onClick={closeConfirmation}
              disabled={Boolean(workingAction)}
            >
              <X size={22} />
            </button>

            <div className="admin-refund-dialog-icon">
              {confirmation.type === 'approve' ? (
                <HandCoins size={30} />
              ) : (
                <XCircle size={30} />
              )}
            </div>

            <h2 id="refund-confirmation-title">
              {confirmation.type === 'approve'
                ? 'Xác nhận duyệt hoàn tiền'
                : 'Xác nhận từ chối yêu cầu'}
            </h2>
            <p>
              {confirmation.type === 'approve'
                ? 'Kiểm tra lại thông tin trước khi cập nhật trạng thái đã hoàn tiền cho khách.'
                : 'Khách hàng sẽ nhận được trạng thái từ chối cùng ghi chú giải thích của QTV.'}
            </p>

            <div className="admin-refund-dialog-summary">
              <div>
                <span>Mã đặt phòng</span>
                <strong>
                  {confirmation.refundRequest.bookingCode}
                </strong>
              </div>
              <div>
                <span>Khách hàng</span>
                <strong>
                  {
                    confirmation.refundRequest.requestedBy
                      .fullName
                  }
                </strong>
              </div>
              {confirmation.type === 'approve' ? (
                <>
                  <div>
                    <span>Tỷ lệ hoàn</span>
                    <strong>{confirmation.percentage}%</strong>
                  </div>
                  <div className="is-refund-total">
                    <span>Số tiền hoàn</span>
                    <strong>
                      {formatPrice(confirmation.amount)}
                    </strong>
                  </div>
                </>
              ) : (
                <div className="is-full-width">
                  <span>Lý do từ chối</span>
                  <strong>{confirmation.adminNote}</strong>
                </div>
              )}
            </div>

            <div className="admin-refund-dialog-actions">
              <button
                className="cancel-button"
                type="button"
                onClick={closeConfirmation}
                disabled={Boolean(workingAction)}
              >
                Quay lại kiểm tra
              </button>
              <button
                className="confirm-button"
                type="button"
                onClick={confirmDecision}
                disabled={Boolean(workingAction)}
              >
                {workingAction ? (
                  <LoaderCircle className="admin-refunds-spinner" />
                ) : confirmation.type === 'approve' ? (
                  <CheckCircle2 size={19} />
                ) : (
                  <XCircle size={19} />
                )}
                {workingAction
                  ? 'Đang xử lý...'
                  : confirmation.type === 'approve'
                    ? 'Xác nhận hoàn tiền'
                    : 'Xác nhận từ chối'}
              </button>
            </div>
          </section>
        </div>
      )}

      <Footer />
    </>
  )
}

export default AdminRefundsPage
