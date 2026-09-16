import {
  BrowserRouter,
  Navigate,
  Route,
  Routes,
} from 'react-router-dom'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import HomestayDetailPage from './pages/HomestayDetailPage'
import MyBookingsPage from './pages/MyBookingsPage'
import OwnerBookingsPage from './pages/OwnerBookingsPage'
import WalletPage from './pages/WalletPage'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<HomePage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route
          path="/homestays/:slug"
          element={<HomestayDetailPage />}
        />
        <Route
          path="/my-bookings"
          element={<MyBookingsPage />}
        />
        <Route
          path="/owner/bookings"
          element={<OwnerBookingsPage />}
        />
        <Route path="/wallet" element={<WalletPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App
