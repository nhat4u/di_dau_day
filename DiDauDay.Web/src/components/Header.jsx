import { useEffect, useState } from 'react'
import {
  ClipboardList,
  LogOut,
  MapPinHouse,
  Menu,
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
  const [currentUser, setCurrentUser] = useState(getStoredUser)

  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    setCurrentUser(getStoredUser())
    setMenuOpen(false)
  }, [location.pathname])

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

  const navigationLinks = (
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
  )

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

            {(isOwner || isAdmin) && (
              <Link className="header-bookings-link" to="/wallet">
                <WalletCards size={18} />
                Ví của tôi
              </Link>
            )}

            <div className="header-user-information">
              <span className="header-user-avatar">
                {avatarLetter}
              </span>
              <span className="header-user-text">
                <strong>{displayName}</strong>
                <small>{getRoleName(currentUser.role)}</small>
              </span>
            </div>

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
