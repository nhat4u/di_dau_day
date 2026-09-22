import {
  BrowserRouter,
  Navigate,
  Route,
  Routes,
} from 'react-router-dom'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import RegisterPage from './pages/RegisterPage'
import HomestaysPage from './pages/HomestaysPage'
import HomestayDetailPage from './pages/HomestayDetailPage'
import MyBookingsPage from './pages/MyBookingsPage'
import OwnerBookingsPage from './pages/OwnerBookingsPage'
import WalletPage from './pages/WalletPage'
import AdminRefundsPage from './pages/AdminRefundsPage'
import OwnerHomestaysPage from './pages/OwnerHomestaysPage'
import OwnerHomestayEditorPage from './pages/OwnerHomestayEditorPage'
import AdminHomestayChangesPage from './pages/AdminHomestayChangesPage'
import AdminOwnersPage from './pages/AdminOwnersPage'
import OwnerProfilePage from './pages/OwnerProfilePage'
import AdminDashboardPage from './pages/AdminDashboardPage'
import OwnerDashboardPage from './pages/OwnerDashboardPage'
import AdminBookingsPage from './pages/AdminBookingsPage'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<HomePage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/homestays" element={<HomestaysPage />} />
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
        <Route
          path="/owner/homestays"
          element={<OwnerHomestaysPage />}
        />
        <Route
          path="/owner/homestays/new"
          element={<OwnerHomestayEditorPage />}
        />
        <Route
          path="/owner/homestays/:id/setup"
          element={<OwnerHomestayEditorPage />}
        />
        <Route
          path="/owner/homestays/:id/change"
          element={<OwnerHomestayEditorPage mode="change-request" />}
        />
        <Route
          path="/owner/profile"
          element={<OwnerProfilePage />}
        />
        <Route path="/wallet" element={<WalletPage />} />
        <Route
          path="/owner/dashboard"
          element={<OwnerDashboardPage />}
        />
        <Route
          path="/admin/dashboard"
          element={<AdminDashboardPage />}
        />
        <Route
          path="/admin/bookings"
          element={<AdminBookingsPage />}
        />
        <Route
          path="/admin/refunds"
          element={<AdminRefundsPage />}
        />
        <Route
          path="/admin/homestay-changes"
          element={<AdminHomestayChangesPage />}
        />
        <Route
          path="/admin/owners"
          element={<AdminOwnersPage />}
        />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App
