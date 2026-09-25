import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  BadgeCheck,
  Ban,
  Building2,
  Check,
  CheckCircle2,
  Clock3,
  FilePenLine,
  IdCard,
  Landmark,
  LoaderCircle,
  Mail,
  MapPin,
  Phone,
  RefreshCw,
  Search,
  ShieldCheck,
  UserRound,
  UsersRound,
  X,
  XCircle,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api, { getApiErrorMessage } from '../services/api'
import '../styles/admin-owners.css'

const accountStatusLabels = {
  pending: 'Chờ duyệt',
  approved: 'Đã duyệt',
  rejected: 'Đã từ chối',
  blocked: 'Đã khóa',
}

const requestStatusLabels = {
  pending: 'Chờ xử lý',
  approved: 'Đã duyệt',
  completed: 'Đã cập nhật',
  rejected: 'Đã từ chối',
}

const fieldLabels = {
  fullName: 'Họ và tên',
  email: 'Email',
  phone: 'Số điện thoại',
  citizenId: 'CCCD/CMND',
  address: 'Địa chỉ',
  bankName: 'Ngân hàng',
  bankAccount: 'Số tài khoản',
  bankAccountName: 'Chủ tài khoản',
}

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

function maskBankAccount(value) {
  if (!value || value.length <= 4) return value || 'Chưa bổ sung'
  return `${'*'.repeat(Math.max(4, value.length - 4))}${value.slice(-4)}`
}

function getCurrentValue(item, key) {
  if (['fullName', 'email', 'phone'].includes(key)) {
    return item.owner?.[key] || '--'
  }

  return item.currentProfile?.[key] || 'Chưa có'
}

function AdminOwnersPage() {
  const navigate = useNavigate()
  const [owners, setOwners] = useState([])
  const [changeRequests, setChangeRequests] = useState([])
  const [activeTab, setActiveTab] = useState('pending')
  const [searchValue, setSearchValue] = useState('')
  const [reviewAction, setReviewAction] = useState(null)
  const [adminNote, setAdminNote] = useState('')
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const loadData = useCallback(async (manual = false) => {
    const token = localStorage.getItem('accessToken')
    const user = getStoredUser()

    if (!token) {
      navigate('/login', { replace: true })
      return
    }

    if (user?.role?.toLowerCase() !== 'admin') {
      navigate('/', { replace: true })
      return
    }

    try {
      if (manual) {
        setRefreshing(true)
      } else {
        setLoading(true)
      }
      setError('')

      const [ownerResponse, requestResponse] = await Promise.all([
        api.get('/admin/owners'),
        api.get('/admin/profile-change-requests'),
      ])

      setOwners(ownerResponse.data.owners || [])
      setChangeRequests(requestResponse.data.requests || [])
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        localStorage.removeItem('accessToken')
        localStorage.removeItem('authUser')
        navigate('/login', { replace: true })
        return
      }

      setError(getApiErrorMessage(
        requestError,
        'Không thể tải danh sách chủ homestay.',
      ))
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [navigate])

  useEffect(() => {
    loadData()
  }, [loadData])

  const pendingOwners = useMemo(
    () => owners.filter((owner) => owner.status === 'pending'),
    [owners],
  )

  const pendingChanges = useMemo(
    () => changeRequests.filter((item) => item.status === 'pending'),
    [changeRequests],
  )

  const visibleOwners = useMemo(() => {
    const source = activeTab === 'pending' ? pendingOwners : owners
    const keyword = searchValue.trim().toLowerCase()

    if (!keyword) return source

    return source.filter((owner) => [
      owner.fullName,
      owner.email,
      owner.phone,
      owner.profile?.citizenId,
    ].some((value) => value?.toLowerCase().includes(keyword)))
  }, [activeTab, owners, pendingOwners, searchValue])

  const openReview = (type, item) => {
    setReviewAction({ type, item })
    setAdminNote('')
    setError('')
    setMessage('')
  }

  const closeReview = () => {
    if (submitting) return
    setReviewAction(null)
    setAdminNote('')
  }

  const submitReview = async () => {
    if (!reviewAction) return

    const { type, item } = reviewAction

    if (type === 'reject-change' && adminNote.trim().length < 3) {
      setError('Vui lòng nhập lý do từ chối rõ ràng.')
      return
    }

    setSubmitting(true)
    setError('')
    setMessage('')

    try {
      let response

      if (type === 'approve-owner') {
        response = await api.put(`/admin/owners/${item.id}/approve`)
      } else if (type === 'reject-owner') {
        response = await api.put(`/admin/owners/${item.id}/reject`)
      } else if (type === 'approve-change') {
        response = await api.patch(
          `/admin/profile-change-requests/${item.id}/approve`,
          { adminNote: adminNote.trim() || null },
        )
      } else {
        response = await api.patch(
          `/admin/profile-change-requests/${item.id}/reject`,
          { adminNote: adminNote.trim() },
        )
      }

      setMessage(response.data.message || 'Đã xử lý thành công.')
      setReviewAction(null)
      setAdminNote('')
      await loadData()
      window.dispatchEvent(new Event('owner-review-updated'))
      window.dispatchEvent(new Event('profile-change-updated'))
    } catch (requestError) {
      setError(getApiErrorMessage(
        requestError,
        'Không thể xử lý yêu cầu.',
      ))
    } finally {
      setSubmitting(false)
    }
  }

  const modalTitle = reviewAction?.type === 'approve-owner'
    ? 'Duyệt tài khoản chủ homestay'
    : reviewAction?.type === 'reject-owner'
      ? 'Từ chối tài khoản chủ homestay'
      : reviewAction?.type === 'approve-change'
        ? 'Duyệt và cập nhật hồ sơ'
        : 'Từ chối yêu cầu thay đổi'

  return (
    <>
      <Header />

      <main className="admin-owners-page">
        <div className="container admin-owners-container">
          <header className="admin-owners-heading">
            <div>
              <span><UsersRound size={19} /> QUẢN LÝ CHỦ HOMESTAY</span>
              <h1>Duyệt và quản lý chủ nhà</h1>
              <p>
                Kiểm tra thông tin đăng ký, danh sách chủ homestay và các yêu
                cầu thay đổi hồ sơ.
              </p>
            </div>

            <button
              type="button"
              onClick={() => loadData(true)}
              disabled={loading || refreshing}
            >
              <RefreshCw className={refreshing ? 'is-spinning' : ''} size={18} />
              {refreshing ? 'Đang tải...' : 'Làm mới'}
            </button>
          </header>

          <section className="admin-owner-stats">
            <article><Clock3 size={22} /><span><small>Chờ duyệt tài khoản</small><strong>{pendingOwners.length}</strong></span></article>
            <article><BadgeCheck size={22} /><span><small>Tổng chủ homestay</small><strong>{owners.length}</strong></span></article>
            <article><FilePenLine size={22} /><span><small>Yêu cầu sửa hồ sơ</small><strong>{pendingChanges.length}</strong></span></article>
          </section>

          <div className="admin-owner-toolbar">
            <div className="admin-owner-tabs">
              <button className={activeTab === 'pending' ? 'is-active' : ''} type="button" onClick={() => setActiveTab('pending')}>
                Chờ duyệt <span>{pendingOwners.length}</span>
              </button>
              <button className={activeTab === 'all' ? 'is-active' : ''} type="button" onClick={() => setActiveTab('all')}>
                Tất cả chủ home <span>{owners.length}</span>
              </button>
              <button className={activeTab === 'changes' ? 'is-active' : ''} type="button" onClick={() => setActiveTab('changes')}>
                Sửa hồ sơ <span>{pendingChanges.length}</span>
              </button>
            </div>

            {activeTab !== 'changes' && (
              <label className="admin-owner-search">
                <Search size={18} />
                <input
                  value={searchValue}
                  onChange={(event) => setSearchValue(event.target.value)}
                  placeholder="Tìm tên, email, SĐT, CCCD..."
                />
              </label>
            )}
          </div>

          {message && <div className="admin-owner-message is-success"><CheckCircle2 size={19} />{message}</div>}
          {error && <div className="admin-owner-message is-error"><XCircle size={19} />{error}</div>}

          {loading ? (
            <section className="admin-owner-state">
              <LoaderCircle className="admin-owner-spinner" />
              <h2>Đang tải dữ liệu...</h2>
            </section>
          ) : activeTab === 'changes' ? (
            <section className="admin-profile-change-list">
              {changeRequests.length === 0 ? (
                <div className="admin-owner-state"><FilePenLine size={42} /><h2>Chưa có yêu cầu thay đổi</h2></div>
              ) : changeRequests.map((item) => (
                <article className="admin-profile-change-card" key={item.id}>
                  <header>
                    <div>
                      <span>YÊU CẦU #{item.id}</span>
                      <h2>{item.owner.fullName}</h2>
                      <p><Mail size={14} />{item.owner.email} <Phone size={14} />{item.owner.phone}</p>
                    </div>
                    <span className={`admin-owner-status is-${item.status}`}>
                      {requestStatusLabels[item.status] || item.status}
                    </span>
                  </header>

                  <div className="admin-profile-change-reason">
                    <strong>Lý do chủ homestay gửi</strong>
                    <p>{item.reason}</p>
                    <small>Gửi lúc {formatDateTime(item.createdAt)}</small>
                  </div>

                  {item.requestedChanges ? (
                    <div className="admin-profile-comparison">
                      <div className="admin-profile-comparison-heading"><span>Thông tin</span><span>Hiện tại</span><span>Đề nghị thay đổi</span></div>
                      {Object.entries(item.requestedChanges)
                        .filter(([, value]) => value)
                        .map(([key, value]) => (
                          <div key={key}>
                            <strong>{fieldLabels[key] || key}</strong>
                            <span>{getCurrentValue(item, key)}</span>
                            <span>{value}</span>
                          </div>
                        ))}
                    </div>
                  ) : (
                    <div className="admin-profile-legacy">
                      <strong>Nội dung yêu cầu cũ</strong>
                      <p>{item.requestedInformation}</p>
                    </div>
                  )}

                  {item.adminNote && <p className="admin-profile-note"><b>Ghi chú QTV:</b> {item.adminNote}</p>}

                  {item.status === 'pending' && (
                    <footer>
                      <button type="button" className="is-reject" onClick={() => openReview('reject-change', item)}><Ban size={17} />Từ chối</button>
                      <button type="button" className="is-approve" onClick={() => openReview('approve-change', item)} disabled={!item.requestedChanges}><Check size={17} />Duyệt & cập nhật</button>
                    </footer>
                  )}
                </article>
              ))}
            </section>
          ) : (
            <section className="admin-owner-list">
              {visibleOwners.length === 0 ? (
                <div className="admin-owner-state"><UsersRound size={44} /><h2>Không có tài khoản phù hợp</h2></div>
              ) : visibleOwners.map((owner) => (
                <article className="admin-owner-card" key={owner.id}>
                  <header>
                    <div className="admin-owner-avatar">{owner.fullName.charAt(0).toUpperCase()}</div>
                    <div>
                      <span>MÃ CHỦ HOME #{owner.id}</span>
                      <h2>{owner.fullName}</h2>
                      <p>Đăng ký {formatDateTime(owner.createdAt)}</p>
                    </div>
                    <span className={`admin-owner-status is-${owner.status}`}>
                      {accountStatusLabels[owner.status] || owner.status}
                    </span>
                  </header>

                  <div className="admin-owner-details">
                    <div><Mail size={17} /><small>Email</small><strong>{owner.email}</strong></div>
                    <div><Phone size={17} /><small>Số điện thoại</small><strong>{owner.phone}</strong></div>
                    <div><IdCard size={17} /><small>CCCD/CMND</small><strong>{owner.profile?.citizenId || 'Thiếu hồ sơ'}</strong></div>
                    <div><MapPin size={17} /><small>Địa chỉ</small><strong>{owner.profile?.address || 'Thiếu hồ sơ'}</strong></div>
                    <div><Building2 size={17} /><small>Homestay</small><strong>{owner.approvedHomestayCount}/{owner.homestayCount} đã duyệt</strong></div>
                    <div><Landmark size={17} /><small>Tài khoản nhận tiền</small><strong>{owner.hasBankAccount ? `${owner.profile.bankName} · ${maskBankAccount(owner.profile.bankAccount)}` : 'Chưa bổ sung'}</strong></div>
                  </div>

                  {owner.status === 'pending' && (
                    <footer>
                      <button type="button" className="is-reject" onClick={() => openReview('reject-owner', owner)}><X size={17} />Từ chối</button>
                      <button type="button" className="is-approve" onClick={() => openReview('approve-owner', owner)}><ShieldCheck size={17} />Duyệt tài khoản</button>
                    </footer>
                  )}
                </article>
              ))}
            </section>
          )}
        </div>
      </main>

      <Footer />

      {reviewAction && (
        <div className="admin-owner-modal-backdrop">
          <div className="admin-owner-modal" role="dialog" aria-modal="true">
            <header>
              <span className={reviewAction.type.includes('reject') ? 'is-reject' : 'is-approve'}>
                {reviewAction.type.includes('reject') ? <Ban size={22} /> : <ShieldCheck size={22} />}
              </span>
              <div><h2>{modalTitle}</h2><p>Hãy kiểm tra kỹ thông tin trước khi xác nhận.</p></div>
              <button type="button" onClick={closeReview}><X size={21} /></button>
            </header>

            <div className="admin-owner-modal-person">
              <UserRound size={19} />
              <span><small>Chủ homestay</small><strong>{reviewAction.item.owner?.fullName || reviewAction.item.fullName}</strong></span>
            </div>

            {(reviewAction.type === 'approve-change' || reviewAction.type === 'reject-change') && (
              <label>
                <span>{reviewAction.type === 'reject-change' ? 'Lý do từ chối *' : 'Ghi chú QTV (không bắt buộc)'}</span>
                <textarea value={adminNote} onChange={(event) => setAdminNote(event.target.value)} placeholder="Nhập nội dung phản hồi cho chủ homestay..." maxLength={500} />
              </label>
            )}

            {error && (
              <div className="admin-owner-message is-error">
                <XCircle size={18} /> {error}
              </div>
            )}

            <footer>
              <button type="button" onClick={closeReview}>Quay lại</button>
              <button type="button" className={reviewAction.type.includes('reject') ? 'is-reject' : 'is-approve'} onClick={submitReview} disabled={submitting}>
                {submitting ? 'Đang xử lý...' : 'Xác nhận'}
              </button>
            </footer>
          </div>
        </div>
      )}
    </>
  )
}

export default AdminOwnersPage
