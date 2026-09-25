import { useCallback, useEffect, useState } from 'react'
import {
  AlertCircle,
  ArrowLeft,
  Building2,
  CheckCircle2,
  Clock3,
  FilePenLine,
  LoaderCircle,
  Mail,
  Phone,
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
import '../styles/admin-homestay-changes.css'

const filters = [
  ['all', 'Tất cả'],
  ['pending', 'Chờ xử lý'],
  ['approved', 'Đã duyệt'],
  ['rejected', 'Đã từ chối'],
]

const typeLabels = {
  update: 'Chỉnh sửa thông tin',
  maintenance: 'Tạm ngừng hoạt động',
  reactivate: 'Hoạt động trở lại',
  close: 'Đóng/xóa homestay',
}

const statusLabels = {
  pending: 'Chờ QTV xử lý',
  approved: 'Đã duyệt',
  rejected: 'Đã từ chối',
}

const informationFields = [
  ['name', 'Tên homestay'],
  ['roomRank', 'Hạng phòng'],
  ['description', 'Mô tả'],
  ['address', 'Địa chỉ'],
  ['province', 'Tỉnh/thành phố'],
  ['touristDestination', 'Điểm du lịch'],
  ['maxGuests', 'Số khách tối đa'],
]

const priceFields = [
  ['priceFirst2Hours', '2 giờ đầu'],
  ['priceCombo4Hours', 'Combo 4 giờ'],
  ['priceExtraHour', 'Giờ phát sinh'],
  ['priceOvernightWeekday', 'Qua đêm ngày thường'],
  ['priceOvernightWeekend', 'Qua đêm cuối tuần'],
  ['priceDayNightWeekday', 'Ngày đêm ngày thường'],
  ['priceDayNightWeekend', 'Ngày đêm cuối tuần'],
  ['priceDayWeekday', 'Ban ngày ngày thường'],
  ['priceDayWeekend', 'Ban ngày cuối tuần'],
]

function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('authUser') || 'null')
  } catch {
    return null
  }
}

function formatDateTime(value) {
  if (!value) return '--'
  return new Intl.DateTimeFormat('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(value))
}

function formatValue(value, isPrice = false) {
  if (Array.isArray(value)) return value.length ? value.join(', ') : 'Không có'
  if (typeof value === 'boolean') return value ? 'Có' : 'Không'
  if (value === null || value === undefined || value === '') return '--'
  if (isPrice) {
    return `${new Intl.NumberFormat('vi-VN').format(Number(value))}đ`
  }
  return String(value)
}

function areAmenityListsEqual(first, second) {
  const normalize = (values) => (Array.isArray(values) ? values : [])
    .map((value) => String(value).trim().toLocaleLowerCase('vi'))
    .sort()

  return JSON.stringify(normalize(first)) === JSON.stringify(normalize(second))
}

function AdminHomestayChangesPage() {
  const navigate = useNavigate()
  const [filter, setFilter] = useState('pending')
  const [requests, setRequests] = useState([])
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [notice, setNotice] = useState(null)
  const [decision, setDecision] = useState(null)
  const [adminNote, setAdminNote] = useState('')
  const [decisionError, setDecisionError] = useState('')
  const [working, setWorking] = useState(false)

  const loadRequests = useCallback(async (showLoading = true) => {
    const user = getStoredUser()
    if (!localStorage.getItem('accessToken')) {
      navigate('/login', { replace: true })
      return
    }
    if (user?.role?.toLowerCase() !== 'admin') {
      setPageError('Trang này chỉ dành cho quản trị viên.')
      setLoading(false)
      return
    }

    try {
      if (showLoading) setLoading(true)
      setPageError('')
      const response = await api.get('/admin/homestay-change-requests', {
        params: filter === 'all' ? {} : { status: filter },
      })
      setRequests(response.data.requests || [])
    } catch (error) {
      setPageError(
        getApiErrorMessage(error, 'Không thể tải yêu cầu thay đổi homestay.'),
      )
    } finally {
      if (showLoading) setLoading(false)
    }
  }, [filter, navigate])

  useEffect(() => {
    loadRequests()
  }, [loadRequests])

  useEffect(() => {
    if (!decision) return undefined
    const oldOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = oldOverflow
    }
  }, [decision])

  function openDecision(action, request) {
    setDecision({ action, request })
    setAdminNote('')
    setDecisionError('')
  }

  async function submitDecision(event) {
    event.preventDefault()
    const note = adminNote.trim()

    if (decision.action === 'reject' && note.length < 5) {
      setDecisionError('Lý do từ chối phải có ít nhất 5 ký tự.')
      return
    }

    try {
      setWorking(true)
      setDecisionError('')
      const response = await api.patch(
        `/admin/homestay-change-requests/${decision.request.id}/${decision.action}`,
        { adminNote: note || null },
      )
      setDecision(null)
      setNotice({ type: 'success', text: response.data.message })
      await loadRequests(false)
      window.dispatchEvent(new Event('homestay-change-updated'))
    } catch (error) {
      setDecisionError(
        getApiErrorMessage(error, 'Không thể xử lý yêu cầu.'),
      )
    } finally {
      setWorking(false)
    }
  }

  return (
    <>
      <Header />
      <main className="admin-home-changes-page">
        <div className="admin-home-changes-container">
          <Link className="admin-home-back" to="/">
            <ArrowLeft size={17} /> Về trang chủ
          </Link>

          <section className="admin-home-heading">
            <div>
              <span><ShieldCheck size={18} /> KHU VỰC QUẢN TRỊ</span>
              <h1>Duyệt thay đổi homestay</h1>
              <p>
                So sánh dữ liệu cũ và mới, kiểm tra đơn tương lai trước khi
                duyệt chỉnh sửa hoặc ngừng hoạt động.
              </p>
            </div>
            <button type="button" onClick={() => loadRequests()}>
              <RefreshCw size={18} /> Làm mới
            </button>
          </section>

          <div className="admin-home-filters">
            {filters.map(([value, label]) => (
              <button
                key={value}
                type="button"
                className={filter === value ? 'active' : ''}
                onClick={() => setFilter(value)}
              >
                {label}
              </button>
            ))}
          </div>

          {notice && (
            <div className={`admin-home-notice ${notice.type}`}>
              <CheckCircle2 size={19} /> {notice.text}
              <button type="button" onClick={() => setNotice(null)}><X size={17} /></button>
            </div>
          )}

          {loading ? (
            <div className="admin-home-state">
              <LoaderCircle className="spin" size={34} />
              <p>Đang tải yêu cầu...</p>
            </div>
          ) : pageError ? (
            <div className="admin-home-state error">
              <AlertCircle size={38} />
              <p>{pageError}</p>
            </div>
          ) : requests.length === 0 ? (
            <div className="admin-home-state">
              <CheckCircle2 size={48} />
              <h2>Không có yêu cầu trong mục này</h2>
              <p>Các yêu cầu mới của chủ home sẽ xuất hiện tại đây.</p>
            </div>
          ) : (
            <div className="admin-home-request-list">
              {requests.map((request) => {
                const proposed = request.proposed
                return (
                  <article className="admin-home-request-card" key={request.id}>
                    <header>
                      <div>
                        <small>Yêu cầu #{request.id}</small>
                        <strong>{request.homestay.name}</strong>
                      </div>
                      <span className={`admin-home-status status-${request.status}`}>
                        {statusLabels[request.status] || request.status}
                      </span>
                    </header>

                    <div className="admin-home-request-summary">
                      <span><FilePenLine size={17} /> {typeLabels[request.requestType]}</span>
                      <span><Clock3 size={17} /> {formatDateTime(request.createdAt)}</span>
                      <span className={request.futureBookingCount > 0 ? 'warning' : ''}>
                        <Building2 size={17} /> {request.futureBookingCount} đơn tương lai
                      </span>
                    </div>

                    <div className="admin-home-owner-row">
                      <span><UserRound size={18} /></span>
                      <strong>{request.owner.fullName}</strong>
                      <em><Mail size={16} /> {request.owner.email}</em>
                      <em><Phone size={16} /> {request.owner.phone}</em>
                    </div>

                    <section className="admin-home-reason">
                      <small>Lý do chủ homestay gửi</small>
                      <p>{request.reason}</p>
                    </section>

                    {request.requestType === 'update' && proposed && (
                      <div className="admin-home-comparison">
                        <h3>So sánh thông tin</h3>
                        <div className="admin-home-comparison-head">
                          <span>Hạng mục</span>
                          <span>Thông tin hiện tại</span>
                          <span>Thông tin đề xuất</span>
                        </div>
                        {informationFields.map(([key, label]) => {
                          const currentValue = request.homestay[key]
                          const proposedValue = proposed.homestay?.[key]
                          const changed = currentValue !== proposedValue
                          return (
                            <div className={changed ? 'changed' : ''} key={key}>
                              <strong>{label}</strong>
                              <span>{formatValue(currentValue)}</span>
                              <span>{formatValue(proposedValue)}</span>
                            </div>
                          )
                        })}

                        <div className={
                          areAmenityListsEqual(
                            request.homestay.amenities,
                            proposed.homestay?.amenities,
                          ) ? '' : 'changed'
                        }>
                          <strong>Tiện ích & ưu đãi</strong>
                          <span>{formatValue(request.homestay.amenities)}</span>
                          <span>{formatValue(proposed.homestay?.amenities)}</span>
                        </div>

                        <h3>Bảng giá</h3>
                        {priceFields.map(([key, label]) => {
                          const currentValue = request.homestay.prices?.[key]
                          const proposedValue = proposed.prices?.[key]
                          const changed = Number(currentValue) !== Number(proposedValue)
                          return (
                            <div className={changed ? 'changed' : ''} key={key}>
                              <strong>{label}</strong>
                              <span>{formatValue(currentValue, true)}</span>
                              <span>{formatValue(proposedValue, true)}</span>
                            </div>
                          )
                        })}
                      </div>
                    )}

                    {request.futureBookingCount > 0 &&
                      ['maintenance', 'close'].includes(request.requestType) && (
                        <div className="admin-home-warning">
                          <AlertCircle size={20} />
                          Home đang có {request.futureBookingCount} đơn trong tương lai.
                          QTV cần kiểm tra và xử lý các đơn này trước khi đóng home.
                        </div>
                      )}

                    {request.status !== 'pending' && request.adminNote && (
                      <div className="admin-home-result">
                        <strong>Phản hồi của QTV</strong>
                        <p>{request.adminNote}</p>
                        <small>{formatDateTime(request.processedAt)} · {request.processedByName}</small>
                      </div>
                    )}

                    {request.status === 'pending' && (
                      <footer>
                        <button className="reject" type="button" onClick={() => openDecision('reject', request)}>
                          <XCircle size={18} /> Từ chối
                        </button>
                        <button className="approve" type="button" onClick={() => openDecision('approve', request)}>
                          <CheckCircle2 size={18} /> Duyệt yêu cầu
                        </button>
                      </footer>
                    )}
                  </article>
                )
              })}
            </div>
          )}
        </div>
      </main>
      <Footer />

      {decision && (
        <div className="admin-home-modal-backdrop">
          <section className="admin-home-modal" role="dialog" aria-modal="true">
            <button type="button" className="close" disabled={working} onClick={() => setDecision(null)}>
              <X size={21} />
            </button>
            <span className={decision.action === 'approve' ? 'approve-icon' : 'reject-icon'}>
              {decision.action === 'approve'
                ? <CheckCircle2 size={27} />
                : <XCircle size={27} />}
            </span>
            <h2>{decision.action === 'approve' ? 'Duyệt yêu cầu?' : 'Từ chối yêu cầu?'}</h2>
            <p>
              {typeLabels[decision.request.requestType]} ·{' '}
              <strong>{decision.request.homestay.name}</strong>
            </p>

            <form onSubmit={submitDecision}>
              <label>
                <span>
                  Ghi chú QTV {decision.action === 'reject' ? '*' : '(không bắt buộc)'}
                </span>
                <textarea
                  rows={5}
                  maxLength={1000}
                  value={adminNote}
                  placeholder={
                    decision.action === 'reject'
                      ? 'Nhập rõ lý do từ chối để chủ home biết...'
                      : 'Nhập ghi chú hoặc căn cứ duyệt...'
                  }
                  onChange={(event) => setAdminNote(event.target.value)}
                />
              </label>

              {decisionError && (
                <div className="admin-home-modal-error">
                  <AlertCircle size={17} /> {decisionError}
                </div>
              )}

              <div>
                <button type="button" disabled={working} onClick={() => setDecision(null)}>
                  Quay lại
                </button>
                <button className={decision.action} type="submit" disabled={working}>
                  {working
                    ? <LoaderCircle className="spin" size={18} />
                    : decision.action === 'approve'
                      ? <CheckCircle2 size={18} />
                      : <XCircle size={18} />}
                  {decision.action === 'approve' ? 'Xác nhận duyệt' : 'Xác nhận từ chối'}
                </button>
              </div>
            </form>
          </section>
        </div>
      )}
    </>
  )
}

export default AdminHomestayChangesPage
