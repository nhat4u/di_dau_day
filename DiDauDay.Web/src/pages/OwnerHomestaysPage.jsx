import { useCallback, useEffect, useState } from 'react'
import {
  AlertCircle,
  ArrowLeft,
  Building2,
  CheckCircle2,
  Clock3,
  Eye,
  FilePenLine,
  ImageIcon,
  LoaderCircle,
  MapPin,
  PauseCircle,
  Plus,
  RotateCcw,
  Trash2,
  Users,
  X,
} from 'lucide-react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api, { getApiErrorMessage } from '../services/api'
import '../styles/owner-homestays.css'

const statusLabels = {
  draft: 'Bản nháp',
  approved: 'Đang hoạt động',
  maintenance: 'Tạm ngừng',
  pending: 'Chờ duyệt',
  rejected: 'Bị từ chối',
}

const requestTypeLabels = {
  update: 'Chỉnh sửa thông tin',
  maintenance: 'Tạm ngừng hoạt động',
  reactivate: 'Hoạt động trở lại',
  close: 'Đóng/xóa homestay',
}

function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('authUser') || 'null')
  } catch {
    return null
  }
}

function formatPrice(value) {
  return `${new Intl.NumberFormat('vi-VN').format(Number(value || 0))}đ`
}

function formatDate(value) {
  if (!value) return '--'

  return new Intl.DateTimeFormat('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(value))
}

function OwnerHomestaysPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const [homestays, setHomestays] = useState([])
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [notice, setNotice] = useState(() =>
    location.state?.message
      ? { type: 'success', text: location.state.message }
      : null,
  )
  const [workingId, setWorkingId] = useState(null)
  const [requestModal, setRequestModal] = useState(null)
  const [requestReason, setRequestReason] = useState('')
  const [requestError, setRequestError] = useState('')

  const loadHomestays = useCallback(async () => {
    const user = getStoredUser()
    if (!localStorage.getItem('accessToken')) {
      navigate('/login', { replace: true })
      return
    }

    if (user?.role?.toLowerCase() !== 'owner') {
      setPageError('Trang này chỉ dành cho tài khoản chủ homestay.')
      setLoading(false)
      return
    }

    try {
      setLoading(true)
      setPageError('')
      const response = await api.get('/owner/homestays')
      setHomestays(response.data.homestays || [])
    } catch (error) {
      if (error.response?.status === 401) {
        localStorage.removeItem('accessToken')
        localStorage.removeItem('authUser')
        navigate('/login', { replace: true })
        return
      }

      setPageError(
        getApiErrorMessage(error, 'Không thể tải danh sách homestay.'),
      )
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => {
    loadHomestays()
  }, [loadHomestays])

  useEffect(() => {
    if (!location.state?.message) return
    navigate(location.pathname, { replace: true, state: null })
  }, [location.pathname, location.state, navigate])

  useEffect(() => {
    if (!requestModal) return undefined

    const oldOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    function handleEscape(event) {
      if (event.key === 'Escape' && !workingId) {
        setRequestModal(null)
      }
    }

    window.addEventListener('keydown', handleEscape)
    return () => {
      document.body.style.overflow = oldOverflow
      window.removeEventListener('keydown', handleEscape)
    }
  }, [requestModal, workingId])

  async function deleteDraft(homestay) {
    if (!window.confirm(`Xóa bản nháp “${homestay.name}”?`)) return

    try {
      setWorkingId(homestay.id)
      setNotice(null)
      await api.delete(`/owner/homestays/${homestay.id}`)
      setHomestays((items) => items.filter((item) => item.id !== homestay.id))
      setNotice({ type: 'success', text: 'Đã xóa bản nháp homestay.' })
    } catch (error) {
      setNotice({
        type: 'error',
        text: getApiErrorMessage(error, 'Không thể xóa bản nháp.'),
      })
    } finally {
      setWorkingId(null)
    }
  }

  function openRequestModal(homestay, requestType) {
    setRequestReason('')
    setRequestError('')
    setRequestModal({ homestay, requestType })
  }

  async function submitLifecycleRequest(event) {
    event.preventDefault()
    const reason = requestReason.trim()

    if (reason.length < 5) {
      setRequestError('Vui lòng nhập lý do có ít nhất 5 ký tự.')
      return
    }

    const { homestay, requestType } = requestModal

    try {
      setWorkingId(homestay.id)
      setRequestError('')
      const response = await api.post('/owner/homestay-change-requests', {
        homestayId: homestay.id,
        requestType,
        reason,
      })

      setRequestModal(null)
      setNotice({ type: 'success', text: response.data.message })
      await loadHomestays()
    } catch (error) {
      setRequestError(
        getApiErrorMessage(error, 'Không thể gửi yêu cầu cho QTV.'),
      )
    } finally {
      setWorkingId(null)
    }
  }

  async function cancelPendingRequest(homestay) {
    const request = homestay.pendingChange
    if (!request) return

    if (!window.confirm('Bạn muốn hủy yêu cầu đang chờ QTV xử lý?')) return

    try {
      setWorkingId(homestay.id)
      await api.delete(`/owner/homestay-change-requests/${request.id}`)
      setNotice({ type: 'success', text: 'Đã hủy yêu cầu thay đổi.' })
      await loadHomestays()
    } catch (error) {
      setNotice({
        type: 'error',
        text: getApiErrorMessage(error, 'Không thể hủy yêu cầu.'),
      })
    } finally {
      setWorkingId(null)
    }
  }

  return (
    <>
      <Header />
      <main className="owner-homestays-page">
        <div className="owner-homestays-container">
          <Link className="owner-page-back" to="/">
            <ArrowLeft size={17} /> Về trang chủ
          </Link>

          <section className="owner-homestays-heading">
            <div>
              <span><Building2 size={18} /> KHU VỰC CHỦ HOMESTAY</span>
              <h1>Homestay của tôi</h1>
              <p>
                Hoàn thiện bản nháp, theo dõi trạng thái và gửi yêu cầu thay đổi
                đến QTV tại đây.
              </p>
            </div>

            <Link className="owner-primary-button" to="/owner/homestays/new">
              <Plus size={19} /> Đăng homestay mới
            </Link>
          </section>

          {notice && (
            <div className={`owner-notice ${notice.type}`}>
              {notice.type === 'success'
                ? <CheckCircle2 size={19} />
                : <AlertCircle size={19} />}
              <span>{notice.text}</span>
              <button type="button" onClick={() => setNotice(null)}>
                <X size={17} />
              </button>
            </div>
          )}

          {loading ? (
            <div className="owner-page-state">
              <LoaderCircle className="spin" size={32} />
              <p>Đang tải danh sách homestay...</p>
            </div>
          ) : pageError ? (
            <div className="owner-page-state error">
              <AlertCircle size={34} />
              <p>{pageError}</p>
              <button type="button" onClick={loadHomestays}>Thử lại</button>
            </div>
          ) : homestays.length === 0 ? (
            <div className="owner-empty-state">
              <Building2 size={52} />
              <h2>Bạn chưa có homestay nào</h2>
              <p>Tạo home đầu tiên và nhập đầy đủ thông tin, bảng giá, hình ảnh.</p>
              <Link className="owner-primary-button" to="/owner/homestays/new">
                <Plus size={19} /> Bắt đầu đăng home
              </Link>
            </div>
          ) : (
            <div className="owner-homestay-list">
              {homestays.map((homestay) => (
                <article className="owner-homestay-card" key={homestay.id}>
                  <div className="owner-homestay-cover">
                    {homestay.coverImageUrl ? (
                      <img src={homestay.coverImageUrl} alt={homestay.name} />
                    ) : (
                      <span><ImageIcon size={38} /> Chưa có ảnh</span>
                    )}
                    <em className={`owner-home-status status-${homestay.status}`}>
                      {statusLabels[homestay.status] || homestay.status}
                    </em>
                  </div>

                  <div className="owner-homestay-content">
                    <div className="owner-homestay-title-row">
                      <div>
                        <h2>{homestay.name}</h2>
                        <p><MapPin size={15} /> {homestay.address}, {homestay.province}</p>
                      </div>
                      <small>Cập nhật {formatDate(homestay.updatedAt)}</small>
                    </div>

                    <div className="owner-homestay-facts">
                      <span><Users size={17} /> Tối đa {homestay.maxGuests} khách</span>
                      <span><ImageIcon size={17} /> {homestay.imageCount} ảnh</span>
                      <span>
                        {homestay.hasPriceTable
                          ? `${formatPrice(homestay.pricePerHour)} – ${formatPrice(homestay.overnightPrice)}`
                          : 'Chưa nhập bảng giá'}
                      </span>
                    </div>

                    {homestay.pendingChange && (
                      <div className="owner-pending-change">
                        <Clock3 size={18} />
                        <span>
                          Đang chờ QTV duyệt:{' '}
                          <strong>
                            {requestTypeLabels[homestay.pendingChange.requestType]}
                          </strong>
                        </span>
                        <button
                          type="button"
                          disabled={workingId === homestay.id}
                          onClick={() => cancelPendingRequest(homestay)}
                        >
                          Hủy yêu cầu
                        </button>
                      </div>
                    )}

                    {!homestay.pendingChange &&
                      homestay.latestChange &&
                      homestay.latestChange?.status !== 'pending' && (
                        <div
                          className={`owner-change-result ${homestay.latestChange.status}`}
                        >
                          {homestay.latestChange.status === 'approved'
                            ? <CheckCircle2 size={18} />
                            : <AlertCircle size={18} />}
                          <span>
                            Yêu cầu{' '}
                            <strong>
                              {requestTypeLabels[homestay.latestChange.requestType]}
                            </strong>{' '}
                            {homestay.latestChange.status === 'approved'
                              ? 'đã được QTV duyệt.'
                              : 'đã bị QTV từ chối.'}
                            {homestay.latestChange.adminNote && (
                              <> Lý do: {homestay.latestChange.adminNote}</>
                            )}
                          </span>
                        </div>
                      )}

                    <div className="owner-homestay-actions">
                      {homestay.status === 'draft' && (
                        <>
                          <Link
                            className="owner-action-button primary"
                            to={`/owner/homestays/${homestay.id}/setup`}
                          >
                            <FilePenLine size={17} /> Tiếp tục hoàn thiện
                          </Link>
                          <button
                            className="owner-action-button danger"
                            type="button"
                            disabled={workingId === homestay.id}
                            onClick={() => deleteDraft(homestay)}
                          >
                            {workingId === homestay.id
                              ? <LoaderCircle className="spin" size={17} />
                              : <Trash2 size={17} />}
                            Xóa bản nháp
                          </button>
                        </>
                      )}

                      {homestay.status === 'approved' && (
                        <>
                          <Link
                            className="owner-action-button"
                            to={`/homestays/${homestay.slug}`}
                          >
                            <Eye size={17} /> Xem trang khách
                          </Link>
                          <Link
                            className="owner-action-button primary"
                            to={`/owner/homestays/${homestay.id}/change`}
                            aria-disabled={Boolean(homestay.pendingChange)}
                            onClick={(event) => {
                              if (homestay.pendingChange) event.preventDefault()
                            }}
                          >
                            <FilePenLine size={17} /> Yêu cầu chỉnh sửa
                          </Link>
                          <button
                            className="owner-action-button"
                            type="button"
                            disabled={Boolean(homestay.pendingChange)}
                            onClick={() => openRequestModal(homestay, 'maintenance')}
                          >
                            <PauseCircle size={17} /> Tạm ngừng
                          </button>
                          <button
                            className="owner-action-button danger"
                            type="button"
                            disabled={Boolean(homestay.pendingChange)}
                            onClick={() => openRequestModal(homestay, 'close')}
                          >
                            <Trash2 size={17} /> Yêu cầu đóng/xóa
                          </button>
                        </>
                      )}

                      {homestay.status === 'maintenance' && !homestay.pendingChange && (
                        <>
                          <Link
                            className="owner-action-button"
                            to={`/owner/homestays/${homestay.id}/change`}
                          >
                            <FilePenLine size={17} /> Yêu cầu chỉnh sửa
                          </Link>
                          <button
                            className="owner-action-button primary"
                            type="button"
                            onClick={() => openRequestModal(homestay, 'reactivate')}
                          >
                            <RotateCcw size={17} /> Yêu cầu hoạt động lại
                          </button>
                        </>
                      )}
                    </div>
                  </div>
                </article>
              ))}
            </div>
          )}
        </div>
      </main>
      <Footer />

      {requestModal && (
        <div className="owner-modal-backdrop" role="presentation">
          <section
            className="owner-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="lifecycle-modal-title"
          >
            <button
              className="owner-modal-close"
              type="button"
              disabled={Boolean(workingId)}
              onClick={() => setRequestModal(null)}
            >
              <X size={21} />
            </button>

            <span className="owner-modal-icon"><Clock3 size={24} /></span>
            <h2 id="lifecycle-modal-title">
              {requestTypeLabels[requestModal.requestType]}
            </h2>
            <p>
              Homestay: <strong>{requestModal.homestay.name}</strong>. QTV sẽ xem
              lý do và kiểm tra các đơn liên quan trước khi quyết định.
            </p>

            <form onSubmit={submitLifecycleRequest}>
              <label>
                <span>Lý do gửi QTV</span>
                <textarea
                  value={requestReason}
                  maxLength={1000}
                  rows={5}
                  placeholder="Ví dụ: Homestay sửa chữa trong 2 tuần..."
                  onChange={(event) => setRequestReason(event.target.value)}
                />
              </label>

              {requestError && (
                <div className="owner-form-error">
                  <AlertCircle size={17} /> {requestError}
                </div>
              )}

              <div className="owner-modal-actions">
                <button
                  type="button"
                  disabled={Boolean(workingId)}
                  onClick={() => setRequestModal(null)}
                >
                  Quay lại
                </button>
                <button className="primary" type="submit" disabled={Boolean(workingId)}>
                  {workingId
                    ? <LoaderCircle className="spin" size={18} />
                    : <CheckCircle2 size={18} />}
                  Gửi yêu cầu
                </button>
              </div>
            </form>
          </section>
        </div>
      )}
    </>
  )
}

export default OwnerHomestaysPage
