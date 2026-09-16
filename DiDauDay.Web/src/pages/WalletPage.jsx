import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ArrowDownLeft,
  ArrowUpRight,
  CheckCircle2,
  CircleDollarSign,
  Clock3,
  History,
  Landmark,
  LoaderCircle,
  ReceiptText,
  RefreshCw,
  WalletCards,
  XCircle,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api from '../services/api'
import '../styles/wallet.css'

const transactionTypeLabels = {
  owner_income: 'Thu nhập từ đơn đặt phòng',
  platform_fee: 'Phí nền tảng 10%',
  withdrawal: 'Rút tiền',
  refund: 'Hoàn tiền',
  adjustment: 'Điều chỉnh số dư',
}

const transactionStatusLabels = {
  completed: 'Hoàn thành',
  pending: 'Đang xử lý',
  failed: 'Thất bại',
  cancelled: 'Đã hủy',
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
  return `${new Intl.NumberFormat('vi-VN').format(
    Number(value || 0),
  )}đ`
}

function formatDateTime(value) {
  if (!value) {
    return 'Chưa phát sinh giao dịch'
  }

  return new Intl.DateTimeFormat('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(value))
}

function getErrorMessage(error, fallbackMessage) {
  return error.response?.data?.message || fallbackMessage
}

function WalletPage() {
  const navigate = useNavigate()

  const [wallet, setWallet] = useState(null)
  const [transactions, setTransactions] = useState([])
  const [selectedDirection, setSelectedDirection] = useState('all')
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')

  const currentUser = getStoredUser()
  const currentRole = currentUser?.role?.toLowerCase()
  const isAdmin = currentRole === 'admin'

  const loadWallet = useCallback(
    async (isManualRefresh = false) => {
      const accessToken = localStorage.getItem('accessToken')
      const storedUser = getStoredUser()
      const storedRole = storedUser?.role?.toLowerCase()

      if (!accessToken) {
        navigate('/login', { replace: true })
        return
      }

      if (storedRole !== 'owner' && storedRole !== 'admin') {
        setError(
          'Ví chỉ dành cho tài khoản chủ homestay hoặc QTV.',
        )
        setLoading(false)
        return
      }

      try {
        if (isManualRefresh) {
          setRefreshing(true)
        } else {
          setLoading(true)
        }

        setError('')

        const [walletResponse, transactionResponse] =
          await Promise.all([
            api.get('/wallet'),
            api.get('/wallet/transactions', {
              params: { limit: 100 },
            }),
          ])

        setWallet(walletResponse.data.wallet)
        setTransactions(
          transactionResponse.data.transactions || [],
        )
      } catch (requestError) {
        if (requestError.response?.status === 401) {
          localStorage.removeItem('accessToken')
          localStorage.removeItem('authUser')
          navigate('/login', { replace: true })
          return
        }

        setError(
          getErrorMessage(
            requestError,
            'Không thể tải thông tin ví.',
          ),
        )
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [navigate],
  )

  useEffect(() => {
    loadWallet()
  }, [loadWallet])

  const visibleTransactions = useMemo(() => {
    if (selectedDirection === 'all') {
      return transactions
    }

    return transactions.filter(
      (transaction) =>
        transaction.direction === selectedDirection,
    )
  }, [selectedDirection, transactions])

  const walletExplanation = isAdmin
    ? 'Mỗi đơn hoàn thành sẽ cộng 10% phí nền tảng vào ví QTV.'
    : 'Mỗi đơn hoàn thành sẽ cộng 90% doanh thu vào ví chủ homestay.'

  return (
    <>
      <Header />

      <main className="wallet-page">
        <div className="container wallet-container">
          <header className="wallet-heading">
            <div>
              <span className="wallet-eyebrow">
                <WalletCards size={18} />
                QUẢN LÝ TÀI CHÍNH
              </span>
              <h1>Ví của tôi</h1>
              <p>{walletExplanation}</p>
            </div>

            <button
              className="wallet-refresh-button"
              type="button"
              onClick={() => loadWallet(true)}
              disabled={loading || refreshing}
            >
              <RefreshCw
                className={refreshing ? 'is-spinning' : ''}
                size={18}
              />
              {refreshing ? 'Đang cập nhật...' : 'Cập nhật số dư'}
            </button>
          </header>

          {loading && (
            <section className="wallet-state">
              <LoaderCircle className="wallet-spinner" />
              <h2>Đang tải thông tin ví...</h2>
            </section>
          )}

          {!loading && error && (
            <section className="wallet-state is-error">
              <XCircle size={46} />
              <h2>Chưa thể mở ví</h2>
              <p>{error}</p>
              <button type="button" onClick={() => loadWallet()}>
                Thử lại
              </button>
            </section>
          )}

          {!loading && !error && wallet && (
            <>
              <section className="wallet-summary-grid">
                <article className="wallet-summary-card is-primary">
                  <span className="wallet-summary-icon">
                    <CircleDollarSign size={26} />
                  </span>
                  <div>
                    <small>Số dư khả dụng</small>
                    <strong>
                      {formatPrice(wallet.availableBalance)}
                    </strong>
                    <p>Có thể sử dụng sau khi đơn hoàn thành.</p>
                  </div>
                </article>

                <article className="wallet-summary-card">
                  <span className="wallet-summary-icon">
                    <Clock3 size={25} />
                  </span>
                  <div>
                    <small>Số dư đang chờ</small>
                    <strong>
                      {formatPrice(wallet.pendingBalance)}
                    </strong>
                    <p>Khoản tiền chưa được quyết toán.</p>
                  </div>
                </article>

                <article className="wallet-summary-card">
                  <span className="wallet-summary-icon">
                    <Landmark size={25} />
                  </span>
                  <div>
                    <small>Tổng thu nhập</small>
                    <strong>
                      {formatPrice(wallet.totalEarned)}
                    </strong>
                    <p>Tổng số tiền đã được cộng vào ví.</p>
                  </div>
                </article>
              </section>

              <section className="wallet-history-section">
                <div className="wallet-history-heading">
                  <div>
                    <span>
                      <History size={19} />
                      LỊCH SỬ GIAO DỊCH
                    </span>
                    <h2>Các khoản thu và chi</h2>
                    <p>
                      Cập nhật lần cuối:{' '}
                      {formatDateTime(wallet.updatedAt)}
                    </p>
                  </div>

                  <div className="wallet-history-filters">
                    <button
                      className={
                        selectedDirection === 'all'
                          ? 'is-active'
                          : ''
                      }
                      type="button"
                      onClick={() => setSelectedDirection('all')}
                    >
                      Tất cả
                    </button>
                    <button
                      className={
                        selectedDirection === 'credit'
                          ? 'is-active'
                          : ''
                      }
                      type="button"
                      onClick={() => setSelectedDirection('credit')}
                    >
                      Tiền vào
                    </button>
                    <button
                      className={
                        selectedDirection === 'debit'
                          ? 'is-active'
                          : ''
                      }
                      type="button"
                      onClick={() => setSelectedDirection('debit')}
                    >
                      Tiền ra
                    </button>
                  </div>
                </div>

                {visibleTransactions.length === 0 ? (
                  <div className="wallet-empty-history">
                    <ReceiptText size={43} />
                    <h3>Chưa có giao dịch</h3>
                    <p>
                      Giao dịch sẽ xuất hiện sau khi một đơn được
                      hoàn thành và chia tiền.
                    </p>
                  </div>
                ) : (
                  <div className="wallet-transaction-list">
                    {visibleTransactions.map((transaction) => {
                      const isCredit =
                        transaction.direction === 'credit'

                      return (
                        <article
                          className="wallet-transaction"
                          key={transaction.id}
                        >
                          <span
                            className={`wallet-transaction-icon ${
                              isCredit ? 'is-credit' : 'is-debit'
                            }`}
                          >
                            {isCredit ? (
                              <ArrowDownLeft size={21} />
                            ) : (
                              <ArrowUpRight size={21} />
                            )}
                          </span>

                          <div className="wallet-transaction-main">
                            <strong>
                              {transactionTypeLabels[
                                transaction.transactionType
                              ] || transaction.description}
                            </strong>
                            <p>
                              {transaction.description}
                              {transaction.bookingCode && (
                                <span>
                                  {' · '}Đơn{' '}
                                  {transaction.bookingCode}
                                </span>
                              )}
                            </p>
                            <small>
                              {formatDateTime(
                                transaction.createdAt,
                              )}
                            </small>
                          </div>

                          <div className="wallet-transaction-value">
                            <strong
                              className={
                                isCredit ? 'is-credit' : 'is-debit'
                              }
                            >
                              {isCredit ? '+' : '-'}
                              {formatPrice(transaction.amount)}
                            </strong>
                            <span>
                              Số dư: {' '}
                              {formatPrice(
                                transaction.balanceAfter,
                              )}
                            </span>
                            <small>
                              <CheckCircle2 size={14} />
                              {transactionStatusLabels[
                                transaction.status
                              ] || transaction.status}
                            </small>
                          </div>
                        </article>
                      )
                    })}
                  </div>
                )}
              </section>

              <p className="wallet-withdrawal-note">
                Chức năng yêu cầu rút tiền chưa được hiển thị vì
                backend hiện chưa có API rút tiền.
              </p>
            </>
          )}
        </div>
      </main>

      <Footer />
    </>
  )
}

export default WalletPage
