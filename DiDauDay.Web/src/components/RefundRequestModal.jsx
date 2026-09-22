import { useEffect, useState } from 'react'
import {
  AlertCircle,
  Info,
  LoaderCircle,
  Send,
  X,
} from 'lucide-react'
import api, { getApiErrorMessage } from '../services/api'

const refundReasons = [
  {
    value: 'guest_cancelled',
    label: 'Tôi muốn hủy chuyến đi',
  },
  {
    value: 'host_cancelled',
    label: 'Chủ homestay hủy hoặc không thể đón khách',
  },
  {
    value: 'power_outage',
    label: 'Mất điện, mất nước hoặc sự cố tiện ích',
  },
  {
    value: 'service_issue',
    label: 'Phòng hoặc dịch vụ có vấn đề',
  },
  {
    value: 'other',
    label: 'Lý do khác',
  },
]

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

function formatTimeUntilCheckIn(value) {
  const hours = Number(value)

  if (!Number.isFinite(hours)) {
    return ''
  }

  if (hours <= 0) {
    return (
      'Yêu cầu được gửi sau giờ nhận phòng ' +
      Math.abs(hours).toFixed(1) +
      ' giờ.'
    )
  }

  const days = Math.floor(hours / 24)
  const remainingHours = Math.floor(hours % 24)

  if (days > 0) {
    return (
      'Còn ' +
      days +
      ' ngày ' +
      remainingHours +
      ' giờ đến giờ nhận phòng.'
    )
  }

  return (
    'Còn khoảng ' +
    Math.floor(hours) +
    ' giờ đến giờ nhận phòng.'
  )
}

function RefundRequestModal({ booking, onClose, onSubmitted }) {
  const [reason, setReason] = useState('guest_cancelled')
  const [description, setDescription] = useState('')
  const [preview, setPreview] = useState(null)
  const [previewLoading, setPreviewLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.body.style.overflow = previousOverflow
    }
  }, [])

  useEffect(() => {
    let active = true

    async function loadPreview() {
      try {
        setPreviewLoading(true)
        setPreview(null)
        setError('')

        const response = await api.get(
          '/refunds/preview/' + booking.id,
          { params: { reason } },
        )

        if (active) {
          setPreview(response.data)
        }
      } catch (requestError) {
        if (active) {
          setError(
            getApiErrorMessage(
              requestError,
              'Không thể kiểm tra chính sách hoàn tiền.',
            ),
          )
        }
      } finally {
        if (active) {
          setPreviewLoading(false)
        }
      }
    }

    loadPreview()

    return () => {
      active = false
    }
  }, [booking.id, reason])

  function requestClose() {
    if (!submitting) {
      onClose()
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (reason !== 'guest_cancelled' && !description.trim()) {
      setError('Vui lòng mô tả chi tiết sự cố để QTV xem xét.')
      return
    }

    if (!preview?.canSubmit) {
      setError(
        preview?.policyLabel ||
          'Yêu cầu này không thuộc chính sách hoàn tiền.',
      )
      return
    }

    try {
      setSubmitting(true)
      setError('')

      const response = await api.post('/refunds', {
        bookingId: booking.id,
        reason,
        description: description.trim() || null,
      })

      await onSubmitted(
        response.data.message ||
          'Đã gửi yêu cầu hoàn tiền. Vui lòng chờ QTV xử lý.',
      )
    } catch (requestError) {
      setError(
        getApiErrorMessage(
          requestError,
          'Không thể gửi yêu cầu hoàn tiền.',
        ),
      )
      setSubmitting(false)
    }
  }

  return (
    <div
      className="refund-modal-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          requestClose()
        }
      }}
    >
      <section
        className="refund-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="refund-modal-title"
      >
        <header className="refund-modal-header">
          <div>
            <span>YÊU CẦU HOÀN TIỀN</span>
            <h2 id="refund-modal-title">{booking.homestay.name}</h2>
            <p>Mã đặt phòng: {booking.bookingCode}</p>
          </div>

          <button
            type="button"
            aria-label="Đóng"
            onClick={requestClose}
            disabled={submitting}
          >
            <X size={23} />
          </button>
        </header>

        <form className="refund-modal-form" onSubmit={handleSubmit}>
          <div className="refund-booking-summary">
            <div>
              <small>Nhận phòng</small>
              <strong>{formatDateTime(booking.checkIn)}</strong>
            </div>
            <div>
              <small>Tổng tiền đã thanh toán</small>
              <strong>{formatPrice(booking.totalAmount)}</strong>
            </div>
          </div>

          <label className="refund-form-field">
            <span>Lý do yêu cầu hoàn tiền</span>
            <select
              value={reason}
              onChange={(event) => {
                setReason(event.target.value)
                setError('')
              }}
              disabled={submitting}
            >
              {refundReasons.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
          </label>

          <label className="refund-form-field">
            <span>
              {reason === 'guest_cancelled'
                ? 'Thông tin thêm (không bắt buộc)'
                : 'Mô tả chi tiết sự cố *'}
            </span>
            <textarea
              value={description}
              onChange={(event) => {
                setDescription(event.target.value)
                setError('')
              }}
              maxLength={1000}
              rows={4}
              placeholder="Hãy cung cấp thông tin để QTV có căn cứ xử lý..."
              disabled={submitting}
            />
            <small>{description.length}/1000 ký tự</small>
          </label>

          <div
            className={
              'refund-policy-preview ' +
              (preview?.canSubmit === false ? 'is-not-eligible' : '')
            }
          >
            {previewLoading ? (
              <>
                <LoaderCircle className="my-bookings-spinner" />
                <span>Đang kiểm tra chính sách hoàn tiền...</span>
              </>
            ) : preview ? (
              <>
                <Info size={20} />
                <span>
                  <strong>{preview.policyLabel}</strong>
                  <small>
                    {formatTimeUntilCheckIn(
                      preview.hoursBeforeCheckIn,
                    )}
                  </small>
                  <small>
                    Số tiền dự kiến:{' '}
                    <b>{formatPrice(preview.refundAmount)}</b>
                  </small>
                </span>
              </>
            ) : (
              <>
                <AlertCircle size={20} />
                <span>Chưa thể xác định chính sách.</span>
              </>
            )}
          </div>

          {error && (
            <div className="refund-modal-error">
              <AlertCircle size={19} />
              {error}
            </div>
          )}

          <div className="refund-modal-actions">
            <button
              className="refund-modal-cancel"
              type="button"
              onClick={requestClose}
              disabled={submitting}
            >
              Đóng
            </button>

            <button
              className="refund-modal-submit"
              type="submit"
              disabled={
                submitting ||
                previewLoading ||
                !preview?.canSubmit
              }
            >
              {submitting ? (
                <LoaderCircle className="my-bookings-spinner" />
              ) : (
                <Send size={18} />
              )}
              {submitting
                ? 'Đang gửi...'
                : 'Gửi yêu cầu hoàn tiền'}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}

export default RefundRequestModal
