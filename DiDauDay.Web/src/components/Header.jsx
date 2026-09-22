import { useEffect, useRef, useState } from 'react'
import {
  Building2,
  ChevronDown,
  ClipboardList,
  FileCheck2,
  HandCoins,
  LayoutDashboard,
  LogOut,
  MapPinHouse,
  Menu,
  UserRound,
  UsersRound,
  WalletCards,
  X,
} from 'lucide-react'
import {
  Link,
  NavLink,
  useLocation,
  useNavigate,
} from 'react-router-dom'
import '../styles/header.css'
import api from '../services/api'

function getStoredUser() {
  const accessToken = localStorage.getItem('accessToken')
  const storedUser = localStorage.getItem('authUser')

  if (!accessToken || !storedUser) {
    return null
  }

  try {
    return JSON.parse(storedUser)
  } catch {
    return null
  }
}

function getRoleName(role) {
  switch (role?.toLowerCase()) {
    case 'admin':
      return 'Quản trị viên'
    case 'owner':
      return 'Chủ homestay'
    default:
      return 'Khách hàng'
  }
}

function Header() {
  const [menuOpen, setMenuOpen] = useState(false)
  const [accountMenuOpen, setAccountMenuOpen] = useState(false)
  const [currentUser, setCurrentUser] = useState(getStoredUser)
  const [pendingRefundCount, setPendingRefundCount] = useState(0)
  const [pendingHomestayChangeCount, setPendingHomestayChangeCount] = useState(0)
  const [pendingOwnerCount, setPendingOwnerCount] = useState(0)
  const [pendingProfileChangeCount, setPendingProfileChangeCount] = useState(0)
  const accountMenuRef = useRef(null)

  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    setCurrentUser(getStoredUser())
    setMenuOpen(false)
    setAccountMenuOpen(false)
  }, [location.pathname])

  useEffect(() => {
    function closeAccountMenu(event) {
      if (
        accountMenuRef.current &&
        !accountMenuRef.current.contains(event.target)
      ) {
        setAccountMenuOpen(false)
      }
    }

    function closeAccountMenuWithEscape(event) {
      if (event.key === 'Escape') {
        setAccountMenuOpen(false)
      }
    }

    document.addEventListener('mousedown', closeAccountMenu)
    document.addEventListener('keydown', closeAccountMenuWithEscape)

    return () => {
      document.removeEventListener('mousedown', closeAccountMenu)
      document.removeEventListener('keydown', closeAccountMenuWithEscape)
    }
  }, [])

  useEffect(() => {
    function updateUser() {
      setCurrentUser(getStoredUser())
    }

    window.addEventListener('storage', updateUser)
    window.addEventListener('auth-changed', updateUser)

    return () => {
      window.removeEventListener('storage', updateUser)
      window.removeEventListener('auth-changed', updateUser)
    }
  }, [])

  function closeMenu() {
    setMenuOpen(false)
    setAccountMenuOpen(false)
  }

  function handleLogout() {
    localStorage.removeItem('accessToken')
    localStorage.removeItem('authUser')
    setCurrentUser(null)
    closeMenu()
    window.dispatchEvent(new Event('auth-changed'))
    navigate('/', { replace: true })
  }

  const displayName = currentUser?.fullName || 'Khách hàng'
  const avatarLetter = displayName.trim().charAt(0).toUpperCase()
  const isGuest = currentUser?.role?.toLowerCase() === 'guest'
  const isOwner = currentUser?.role?.toLowerCase() === 'owner'
  const isAdmin = currentUser?.role?.toLowerCase() === 'admin'
  const bookingPageLink = isGuest
    ? '/my-bookings'
    : '/owner/bookings'
  const bookingPageLabel = isGuest
    ? 'Đơn của tôi'
    : 'Đơn đặt phòng'

  useEffect(() => {
    if (!isAdmin) {
      setPendingRefundCount(0)
      return undefined
    }

    let active = true

    async function loadPendingRefunds() {
      try {
        const response = await api.get('/admin/refunds', {
          params: { status: 'pending' },
        })

        if (active) {
          setPendingRefundCount(Number(response.data.total || 0))
        }
      } catch {
        if (active) {
          setPendingRefundCount(0)
        }
      }
    }

    loadPendingRefunds()

    const timer = window.setInterval(loadPendingRefunds, 5000)

    return () => {
      active = false
      window.clearInterval(timer)
    }
  }, [isAdmin])

  useEffect(() => {
    if (!isAdmin) {
      setPendingOwnerCount(0)
      setPendingProfileChangeCount(0)
      return undefined
    }

    let active = true

    async function loadPendingOwners() {
      try {
        const [ownerResponse, profileResponse] = await Promise.all([
          api.get('/admin/owners/pending'),
          api.get('/admin/profile-change-requests', {
            params: { status: 'pending' },
          }),
        ])

        if (active) {
          setPendingOwnerCount(Number(ownerResponse.data.total || 0))
          setPendingProfileChangeCount(
            Number(profileResponse.data.total || 0),
          )
        }
      } catch {
        if (active) {
          setPendingOwnerCount(0)
          setPendingProfileChangeCount(0)
        }
      }
    }

    loadPendingOwners()
    const timer = window.setInterval(loadPendingOwners, 5000)
    window.addEventListener('owner-review-updated', loadPendingOwners)
    window.addEventListener('profile-change-updated', loadPendingOwners)

    return () => {
      active = false
      window.clearInterval(timer)
      window.removeEventListener('owner-review-updated', loadPendingOwners)
      window.removeEventListener('profile-change-updated', loadPendingOwners)
    }
  }, [isAdmin])

  useEffect(() => {
    if (!isAdmin) {
      setPendingHomestayChangeCount(0)
      return undefined
    }

    let active = true

    async function loadPendingHomestayChanges() {
      try {
        const response = await api.get(
          '/admin/homestay-change-requests',
          { params: { status: 'pending' } },
        )

        if (active) {
          setPendingHomestayChangeCount(
            Number(response.data.total || 0),
          )
        }
      } catch {
        if (active) setPendingHomestayChangeCount(0)
      }
    }

    loadPendingHomestayChanges()
    const timer = window.setInterval(loadPendingHomestayChanges, 5000)
    window.addEventListener(
      'homestay-change-updated',
      loadPendingHomestayChanges,
    )

    return () => {
      active = false
      window.clearInterval(timer)
      window.removeEventListener(
        'homestay-change-updated',
        loadPendingHomestayChanges,
      )
    }
  }, [isAdmin])

  const navigationLinks = !isAdmin ? (
    <>
      <a href="/#destinations" onClick={closeMenu}>
        Điểm đến
      </a>
      <NavLink to="/homestays" onClick={closeMenu}>
        Homestay
      </NavLink>
      <a href="/#how-it-works" onClick={closeMenu}>
        Cách hoạt động
      </a>
      {!currentUser && (
        <Link to="/register?role=owner" onClick={closeMenu}>
          Trở thành chủ nhà
        </Link>
      )}
    </>
  ) : null

  return (
    <header className="site-header">
      <div className="container header-inner">
        <Link className="site-brand" to="/" onClick={closeMenu}>
          <span className="site-brand-icon">
            <MapPinHouse size={29} strokeWidth={2.3} />
          </span>

          <span className="site-brand-text">
            <strong>Đi Đâu Đây</strong>
            <small>HOMESTAY VIỆT NAM</small>
          </span>
        </Link>

        <nav className="desktop-navigation">
          {navigationLinks}
        </nav>

        {currentUser ? (
          <div className="header-user-area">
            {(isGuest || isOwner) && (
              <Link
                className="header-bookings-link"
                to={bookingPageLink}
              >
                <ClipboardList size={18} />
                {bookingPageLabel}
              </Link>
            )}

            {isAdmin && (
              <Link className="header-bookings-link" to="/admin/dashboard">
                <LayoutDashboard size={18} />
                Dashboard
              </Link>
            )}

            {isAdmin && (
              <Link className="header-bookings-link" to="/admin/bookings">
                <ClipboardList size={18} />
                Tất cả đơn
              </Link>
            )}

            {isAdmin && (
              <Link className="header-bookings-link" to="/wallet">
                <WalletCards size={18} />
                Ví của tôi
              </Link>
            )}

            {isAdmin && (
              <Link
                className="header-bookings-link header-refunds-link"
                to="/admin/owners"
              >
                <UsersRound size={18} />
                Chủ home
                {pendingOwnerCount + pendingProfileChangeCount > 0 && (
                  <span className="header-notification-badge">
                    {pendingOwnerCount + pendingProfileChangeCount > 99
                      ? '99+'
                      : pendingOwnerCount + pendingProfileChangeCount}
                  </span>
                )}
              </Link>
            )}

            {isAdmin && (
              <Link
                className="header-bookings-link header-refunds-link"
                to="/admin/homestay-changes"
              >
                <FileCheck2 size={18} />
                Duyệt home
                {pendingHomestayChangeCount > 0 && (
                  <span className="header-notification-badge">
                    {pendingHomestayChangeCount > 99
                      ? '99+'
                      : pendingHomestayChangeCount}
                  </span>
                )}
              </Link>
            )}

            {isAdmin && (
              <Link
                className="header-bookings-link header-refunds-link"
                to="/admin/refunds"
              >
                <HandCoins size={18} />
                Hoàn tiền
                {pendingRefundCount > 0 && (
                  <span className="header-notification-badge">
                    {pendingRefundCount > 99
                      ? '99+'
                      : pendingRefundCount}
                  </span>
                )}
              </Link>
            )}

            {isOwner ? (
              <div className="header-account-menu" ref={accountMenuRef}>
                <button
                  className={`header-account-trigger${accountMenuOpen ? ' is-open' : ''}`}
                  type="button"
                  aria-haspopup="menu"
                  aria-expanded={accountMenuOpen}
                  onClick={() => setAccountMenuOpen((value) => !value)}
                >
                  <span className="header-user-avatar">
                    {avatarLetter}
                  </span>
                  <span className="header-user-text">
                    <strong>{displayName}</strong>
                    <small>{getRoleName(currentUser.role)}</small>
                  </span>
                  <ChevronDown
                    className="header-account-chevron"
                    size={18}
                  />
                </button>

                {accountMenuOpen && (
                  <div className="header-account-dropdown" role="menu">
                    <div className="header-account-dropdown-title">
                      <strong>Tài khoản chủ home</strong>
                      <small>Quản lý thông tin và hoạt động</small>
                    </div>

                    <NavLink to="/owner/dashboard" onClick={closeMenu}>
                      <LayoutDashboard size={18} />
                      <span>
                        <strong>Dashboard</strong>
                        <small>Tổng quan hoạt động của bạn</small>
                      </span>
                    </NavLink>

                    <NavLink to="/owner/profile" onClick={closeMenu}>
                      <UserRound size={18} />
                      <span>
                        <strong>Hồ sơ của tôi</strong>
                        <small>Thông tin và tài khoản nhận tiền</small>
                      </span>
                    </NavLink>

                    <NavLink to="/owner/homestays" end onClick={closeMenu}>
                      <Building2 size={18} />
                      <span>
                        <strong>Home của tôi</strong>
                        <small>Danh sách và trạng thái homestay</small>
                      </span>
                    </NavLink>

                    <NavLink to="/owner/homestays/new" onClick={closeMenu}>
                      <MapPinHouse size={18} />
                      <span>
                        <strong>Đăng homestay mới</strong>
                        <small>Tạo một địa điểm lưu trú mới</small>
                      </span>
                    </NavLink>

                    <NavLink to="/wallet" onClick={closeMenu}>
                      <WalletCards size={18} />
                      <span>
                        <strong>Ví của tôi</strong>
                        <small>Theo dõi doanh thu và số dư</small>
                      </span>
                    </NavLink>
                  </div>
                )}
              </div>
            ) : (
              <div className="header-user-information">
                <span className="header-user-avatar">
                  {avatarLetter}
                </span>
                <span className="header-user-text">
                  <strong>{displayName}</strong>
                  <small>{getRoleName(currentUser.role)}</small>
                </span>
              </div>
            )}

            <button
              className="header-logout-button"
              type="button"
              onClick={handleLogout}
            >
              <LogOut size={19} />
              Đăng xuất
            </button>
          </div>
        ) : (
          <div className="header-actions">
            <Link className="header-login-button" to="/login">
              Đăng nhập
            </Link>
            <Link
              className="header-register-button"
              to="/register"
            >
              Đăng ký
            </Link>
          </div>
        )}

        <button
          className="mobile-menu-button"
          type="button"
          aria-label={menuOpen ? 'Đóng menu' : 'Mở menu'}
          onClick={() =>
            setMenuOpen((currentValue) => !currentValue)
          }
        >
          {menuOpen ? <X size={25} /> : <Menu size={25} />}
        </button>

        {menuOpen && (
          <nav className="mobile-navigation">
            {navigationLinks}

            {currentUser ? (
              <div className="mobile-user-area">
                <div className="header-user-information">
                  <span className="header-user-avatar">
                    {avatarLetter}
                  </span>
                  <span className="header-user-text">
                    <strong>{displayName}</strong>
                    <small>{getRoleName(currentUser.role)}</small>
                  </span>
                </div>

                {(isGuest || isOwner) && (
                  <Link
                    className="header-bookings-link"
                    to={bookingPageLink}
                    onClick={closeMenu}
                  >
                    <ClipboardList size={18} />
                    {bookingPageLabel}
                  </Link>
                )}

                {(isOwner || isAdmin) && (
                  <Link
                    className="header-bookings-link"
                    to="/wallet"
                    onClick={closeMenu}
                  >
                    <WalletCards size={18} />
                    Ví của tôi
                  </Link>
                )}

                {isOwner && (
                  <Link
                    className="header-bookings-link"
                    to="/owner/dashboard"
                    onClick={closeMenu}
                  >
                    <LayoutDashboard size={18} />
                    Dashboard
                  </Link>
                )}

                {isOwner && (
                  <Link
                    className="header-bookings-link"
                    to="/owner/profile"
                    onClick={closeMenu}
                  >
                    <UserRound size={18} />
                    Hồ sơ của tôi
                  </Link>
                )}

                {isOwner && (
                  <Link
                    className="header-bookings-link"
                    to="/owner/homestays"
                    onClick={closeMenu}
                  >
                    <Building2 size={18} />
                    Home của tôi
                  </Link>
                )}

                {isAdmin && (
                  <Link
                    className="header-bookings-link"
                    to="/admin/dashboard"
                    onClick={closeMenu}
                  >
                    <LayoutDashboard size={18} />
                    Dashboard
                  </Link>
                )}

                {isAdmin && (
                  <Link
                    className="header-bookings-link"
                    to="/admin/bookings"
                    onClick={closeMenu}
                  >
                    <ClipboardList size={18} />
                    Tất cả đơn
                  </Link>
                )}

                {isAdmin && (
                  <Link
                    className="header-bookings-link header-refunds-link"
                    to="/admin/owners"
                    onClick={closeMenu}
                  >
                    <UsersRound size={18} />
                    Chủ home
                    {pendingOwnerCount + pendingProfileChangeCount > 0 && (
                      <span className="header-notification-badge">
                        {pendingOwnerCount + pendingProfileChangeCount > 99
                          ? '99+'
                          : pendingOwnerCount + pendingProfileChangeCount}
                      </span>
                    )}
                  </Link>
                )}

                {isAdmin && (
                  <Link
                    className="header-bookings-link header-refunds-link"
                    to="/admin/homestay-changes"
                    onClick={closeMenu}
                  >
                    <FileCheck2 size={18} />
                    Duyệt home
                    {pendingHomestayChangeCount > 0 && (
                      <span className="header-notification-badge">
                        {pendingHomestayChangeCount > 99
                          ? '99+'
                          : pendingHomestayChangeCount}
                      </span>
                    )}
                  </Link>
                )}

                {isAdmin && (
                  <Link
                    className="header-bookings-link header-refunds-link"
                    to="/admin/refunds"
                    onClick={closeMenu}
                  >
                    <HandCoins size={18} />
                    Hoàn tiền
                    {pendingRefundCount > 0 && (
                      <span className="header-notification-badge">
                        {pendingRefundCount > 99
                          ? '99+'
                          : pendingRefundCount}
                      </span>
                    )}
                  </Link>
                )}

                <button
                  className="header-logout-button"
                  type="button"
                  onClick={handleLogout}
                >
                  <LogOut size={19} />
                  Đăng xuất
                </button>
              </div>
            ) : (
              <div className="mobile-auth-actions">
                <Link
                  className="header-login-button"
                  to="/login"
                  onClick={closeMenu}
                >
                  Đăng nhập
                </Link>
                <Link
                  className="header-register-button"
                  to="/register"
                  onClick={closeMenu}
                >
                  Đăng ký
                </Link>
              </div>
            )}
          </nav>
        )}
      </div>
    </header>
  )
}

export default Header
