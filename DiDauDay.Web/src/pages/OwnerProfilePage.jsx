import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  BadgeCheck,
  CheckCircle2,
  Clock3,
  FilePenLine,
  History,
  IdCard,
  Landmark,
  LoaderCircle,
  Mail,
  MapPin,
  Phone,
  Save,
  ShieldCheck,
  UserRound,
  X,
  XCircle,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api, { getApiErrorMessage } from '../services/api'
import '../styles/owner-profile.css'

const statusLabels = {
  pending: 'Chờ QTV xử lý',
  approved: 'Đã duyệt, chờ cập nhật',
  completed: 'Đã cập nhật',
  rejected: 'Đã từ chối',
}

const fieldLabels = {
  fullName: 'Họ và tên',
  email: 'Email',
  phone: 'Số điện thoại',
  citizenId: 'CCCD/CMND',
  address: 'Địa chỉ thường trú',
  bankName: 'Ngân hàng',
  bankAccount: 'Số tài khoản',
  bankAccountName: 'Tên chủ tài khoản',
}

const emptyBankForm = {
  bankName: '',
  bankAccount: '',
  bankAccountName: '',
}

const emptyChangeForm = {
  fullName: '',
  email: '',
  phone: '',
  citizenId: '',
  address: '',
  bankName: '',
  bankAccount: '',
  bankAccountName: '',
  reason: '',
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
  if (!value || value.length <= 4) return value || '--'
  return `${'*'.repeat(Math.max(4, value.length - 4))}${value.slice(-4)}`
}

function OwnerProfilePage() {
  const navigate = useNavigate()
  const [account, setAccount] = useState(null)
  const [profile, setProfile] = useState(null)
  const [hasBankAccount, setHasBankAccount] = useState(false)
  const [requests, setRequests] = useState([])
  const [bankForm, setBankForm] = useState(emptyBankForm)
  const [changeForm, setChangeForm] = useState(emptyChangeForm)
  const [showChangeModal, setShowChangeModal] = useState(false)
  const [loading, setLoading] = useState(true)
  const [savingBank, setSavingBank] = useState(false)
  const [sendingRequest, setSendingRequest] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const activeRequest = useMemo(
    () => requests.find(
      (item) => item.status === 'pending' || item.status === 'approved',
    ),
    [requests],
  )

  const loadData = useCallback(async () => {
    const user = getStoredUser()
    const token = localStorage.getItem('accessToken')

    if (!token) {
      navigate('/login', { replace: true })
      return
    }

    if (user?.role?.toLowerCase() !== 'owner') {
      navigate('/', { replace: true })
      return
    }

    try {
      setLoading(true)
      setError('')

      const [profileResponse, requestResponse] = await Promise.all([
        api.get('/owner/profile'),
        api.get('/owner/profile-change-requests'),
      ])

      const nextAccount = profileResponse.data.account
      setAccount(nextAccount)
      setProfile(profileResponse.data.profile)
      setHasBankAccount(Boolean(profileResponse.data.hasBankAccount))
      setRequests(requestResponse.data.requests || [])

      if (nextAccount && user) {
        const nextStoredUser = {
          ...user,
          fullName: nextAccount.fullName,
          email: nextAccount.email,
          phone: nextAccount.phone,
          status: nextAccount.status,
        }

        localStorage.setItem('authUser', JSON.stringify(nextStoredUser))
        window.dispatchEvent(new Event('auth-changed'))
      }
    } catch (requestError) {
      if (requestError.response?.status === 401) {
        localStorage.removeItem('accessToken')
        localStorage.removeItem('authUser')
        navigate('/login', { replace: true })
        return
      }

      setError(getApiErrorMessage(
        requestError,
        'Không thể tải hồ sơ chủ homestay.',
      ))
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => {
    loadData()
  }, [loadData])

  const updateBankField = (event) => {
    const { name, value } = event.target
    setBankForm((current) => ({ ...current, [name]: value }))
    setError('')
    setMessage('')
  }

  const updateChangeField = (event) => {
    const { name, value } = event.target
    setChangeForm((current) => ({ ...current, [name]: value }))
    setError('')
  }

  const handleAddBank = async (event) => {
    event.preventDefault()
    setSavingBank(true)
    setError('')
    setMessage('')

    try {
      const response = await api.patch('/owner/profile/bank', {
        bankName: bankForm.bankName.trim(),
        bankAccount: bankForm.bankAccount.trim(),
        bankAccountName: bankForm.bankAccountName.trim(),
      })

      setMessage(response.data.message || 'Đã bổ sung tài khoản ngân hàng.')
      setBankForm(emptyBankForm)
      await loadData()
    } catch (requestError) {
      setError(getApiErrorMessage(
        requestError,
        'Không thể bổ sung tài khoản ngân hàng.',
      ))
    } finally {
      setSavingBank(false)
    }
  }

  const handleSendChangeRequest = async (event) => {
    event.preventDefault()

    const changes = Object.fromEntries(
      Object.entries(changeForm)
        .filter(([key]) => key !== 'reason')
        .map(([key, value]) => [key, value.trim()])
        .filter(([, value]) => value.length > 0),
    )

    if (Object.keys(changes).length === 0) {
      setError('Vui lòng nhập ít nhất một thông tin mới.')
      return
    }

    setSendingRequest(true)
    setError('')
    setMessage('')

    try {
      const response = await api.post(
        '/owner/profile-change-requests',
        {
          reason: changeForm.reason.trim(),
          changes,
        },
      )

      setMessage(response.data.message || 'Đã gửi yêu cầu thay đổi.')
      setChangeForm(emptyChangeForm)
      setShowChangeModal(false)
      await loadData()
      window.dispatchEvent(new Event('profile-change-updated'))
    } catch (requestError) {
      setError(getApiErrorMessage(
        requestError,
        'Không thể gửi yêu cầu thay đổi hồ sơ.',
      ))
    } finally {
      setSendingRequest(false)
    }
  }

  return (
    <>
      <Header />

      <main className="owner-profile-page">
        <div className="container owner-profile-container">
          <header className="owner-profile-heading">
            <div>
              <span><ShieldCheck size={18} /> HỒ SƠ CHỦ HOMESTAY</span>
              <h1>Thông tin của tôi</h1>
              <p>
                Theo dõi thông tin đã đăng ký và gửi QTV duyệt khi cần thay đổi.
              </p>
            </div>

            <button
              type="button"
              onClick={() => setShowChangeModal(true)}
              disabled={Boolean(activeRequest) || loading}
            >
              <FilePenLine size={18} />
              {activeRequest ? 'Đang chờ QTV xử lý' : 'Yêu cầu thay đổi'}
            </button>
          </header>

          {message && (
            <div className="owner-profile-message is-success">
              <CheckCircle2 size={19} /> {message}
            </div>
          )}

          {error && (
            <div className="owner-profile-message is-error">
              <XCircle size={19} /> {error}
            </div>
          )}

          {loading && (
            <section className="owner-profile-state">
              <LoaderCircle className="owner-profile-spinner" />
              <h2>Đang tải hồ sơ...</h2>
            </section>
          )}

          {!loading && account && profile && (
            <>
              <section className="owner-profile-grid">
                <article className="owner-profile-card">
                  <div className="owner-profile-card-title">
                    <UserRound size={21} />
                    <div>
                      <h2>Thông tin tài khoản</h2>
                      <p>Thông tin dùng để đăng nhập và liên hệ.</p>
                    </div>
                    <span className={`owner-account-status is-${account.status}`}>
                      <BadgeCheck size={15} />
                      {account.status === 'approved' ? 'Đã duyệt' : account.status}
                    </span>
                  </div>

                  <div className="owner-profile-information-list">
                    <div><UserRound size={18} /><span>Họ và tên</span><strong>{account.fullName}</strong></div>
                    <div><Mail size={18} /><span>Email</span><strong>{account.email}</strong></div>
                    <div><Phone size={18} /><span>Số điện thoại</span><strong>{account.phone}</strong></div>
                    <div><Clock3 size={18} /><span>Ngày đăng ký</span><strong>{formatDateTime(account.createdAt)}</strong></div>
                  </div>
                </article>

                <article className="owner-profile-card">
                  <div className="owner-profile-card-title">
                    <IdCard size={21} />
                    <div>
                      <h2>Thông tin xác minh</h2>
                      <p>Thông tin QTV đã dùng để xét duyệt tài khoản.</p>
                    </div>
                  </div>

                  <div className="owner-profile-information-list">
                    <div><IdCard size={18} /><span>CCCD/CMND</span><strong>{profile.citizenId}</strong></div>
                    <div className="is-wide"><MapPin size={18} /><span>Địa chỉ</span><strong>{profile.address}</strong></div>
                  </div>
                </article>
              </section>

              <section className="owner-bank-section">
                <div className="owner-bank-heading">
                  <span><Landmark size={21} /></span>
                  <div>
                    <h2>Tài khoản nhận tiền</h2>
                    <p>
                      Có thể bổ sung sau khi được duyệt. Sau lần đầu, thay đổi
                      tài khoản cần QTV xác nhận.
                    </p>
                  </div>
                </div>

                {hasBankAccount ? (
                  <div className="owner-bank-summary">
                    <div><small>Ngân hàng</small><strong>{profile.bankName}</strong></div>
                    <div><small>Số tài khoản</small><strong>{maskBankAccount(profile.bankAccount)}</strong></div>
                    <div><small>Chủ tài khoản</small><strong>{profile.bankAccountName}</strong></div>
                    <span><CheckCircle2 size={17} /> Đã hoàn tất</span>
                  </div>
                ) : (
                  <form className="owner-bank-form" onSubmit={handleAddBank}>
                    <label>
                      <span>Tên ngân hàng</span>
                      <input
                        name="bankName"
                        value={bankForm.bankName}
                        onChange={updateBankField}
                        placeholder="Ví dụ: Vietcombank"
                        minLength={2}
                        maxLength={100}
                        required
                      />
                    </label>
                    <label>
                      <span>Số tài khoản</span>
                      <input
                        name="bankAccount"
                        value={bankForm.bankAccount}
                        onChange={updateBankField}
                        placeholder="Nhập 6–30 chữ số"
                        inputMode="numeric"
                        pattern="[0-9]{6,30}"
                        required
                      />
                    </label>
                    <label>
                      <span>Tên chủ tài khoản</span>
                      <input
                        name="bankAccountName"
                        value={bankForm.bankAccountName}
                        onChange={updateBankField}
                        placeholder="NGUYEN VAN A"
                        minLength={2}
                        maxLength={100}
                        required
                      />
                    </label>
                    <button type="submit" disabled={savingBank}>
                      <Save size={18} />
                      {savingBank ? 'Đang lưu...' : 'Lưu tài khoản'}
                    </button>
                  </form>
                )}
              </section>

              <section className="owner-change-history">
                <div className="owner-change-history-heading">
                  <div>
                    <span><History size={18} /> LỊCH SỬ YÊU CẦU</span>
                    <h2>Các thay đổi hồ sơ</h2>
                  </div>
                  <strong>{requests.length} yêu cầu</strong>
                </div>

                {requests.length === 0 ? (
                  <div className="owner-change-empty">
                    <FilePenLine size={38} />
                    <p>Bạn chưa gửi yêu cầu thay đổi hồ sơ.</p>
                  </div>
                ) : (
                  <div className="owner-change-list">
                    {requests.map((item) => (
                      <article key={item.id}>
                        <div className="owner-change-topline">
                          <div>
                            <strong>Yêu cầu #{item.id}</strong>
                            <small>{formatDateTime(item.createdAt)}</small>
                          </div>
                          <span className={`is-${item.status}`}>
                            {statusLabels[item.status] || item.status}
                          </span>
                        </div>
                        <p><b>Lý do:</b> {item.reason}</p>
                        <div className="owner-change-values">
                          {item.requestedChanges
                            ? Object.entries(item.requestedChanges)
                              .filter(([, value]) => value)
                              .map(([key, value]) => (
                                <span key={key}>
                                  <small>{fieldLabels[key] || key}</small>
                                  <strong>{value}</strong>
                                </span>
                              ))
                            : <em>{item.requestedInformation}</em>}
                        </div>
                        {item.adminNote && (
                          <p className="owner-change-admin-note">
                            <b>Phản hồi QTV:</b> {item.adminNote}
                          </p>
                        )}
                      </article>
                    ))}
                  </div>
                )}
              </section>
            </>
          )}
        </div>
      </main>

      <Footer />

      {showChangeModal && account && profile && (
        <div className="owner-change-modal-backdrop">
          <div className="owner-change-modal" role="dialog" aria-modal="true">
            <header>
              <div>
                <span><FilePenLine size={19} /> YÊU CẦU QTV DUYỆT</span>
                <h2>Thay đổi thông tin hồ sơ</h2>
                <p>Chỉ nhập những mục bạn muốn thay đổi.</p>
              </div>
              <button type="button" onClick={() => setShowChangeModal(false)}>
                <X size={22} />
              </button>
            </header>

            <form onSubmit={handleSendChangeRequest}>
              <div className="owner-change-form-grid">
                <label><span>Họ và tên</span><input name="fullName" value={changeForm.fullName} onChange={updateChangeField} placeholder={account.fullName} minLength={2} maxLength={100} /></label>
                <label><span>Email</span><input name="email" type="email" value={changeForm.email} onChange={updateChangeField} placeholder={account.email} /></label>
                <label><span>Số điện thoại</span><input name="phone" value={changeForm.phone} onChange={updateChangeField} placeholder={account.phone} inputMode="numeric" pattern="0[0-9]{9}" /></label>
                <label><span>CCCD/CMND</span><input name="citizenId" value={changeForm.citizenId} onChange={updateChangeField} placeholder={profile.citizenId} inputMode="numeric" pattern="[0-9]{9,12}" /></label>
                <label className="is-wide"><span>Địa chỉ thường trú</span><input name="address" value={changeForm.address} onChange={updateChangeField} placeholder={profile.address} minLength={5} maxLength={255} /></label>
                {hasBankAccount && (
                  <>
                    <label><span>Ngân hàng</span><input name="bankName" value={changeForm.bankName} onChange={updateChangeField} placeholder={profile.bankName} /></label>
                    <label><span>Số tài khoản</span><input name="bankAccount" value={changeForm.bankAccount} onChange={updateChangeField} placeholder={profile.bankAccount} inputMode="numeric" pattern="[0-9]{6,30}" /></label>
                    <label className="is-wide"><span>Tên chủ tài khoản</span><input name="bankAccountName" value={changeForm.bankAccountName} onChange={updateChangeField} placeholder={profile.bankAccountName} /></label>
                  </>
                )}
                <label className="is-wide">
                  <span>Lý do thay đổi *</span>
                  <textarea
                    name="reason"
                    value={changeForm.reason}
                    onChange={updateChangeField}
                    placeholder="Trình bày lý do để QTV đối chiếu..."
                    minLength={5}
                    maxLength={500}
                    required
                  />
                </label>
              </div>

              <footer>
                <button type="button" onClick={() => setShowChangeModal(false)}>
                  Hủy
                </button>
                <button type="submit" disabled={sendingRequest}>
                  <FilePenLine size={18} />
                  {sendingRequest ? 'Đang gửi...' : 'Gửi QTV duyệt'}
                </button>
              </footer>
            </form>
          </div>
        </div>
      )}
    </>
  )
}

export default OwnerProfilePage
