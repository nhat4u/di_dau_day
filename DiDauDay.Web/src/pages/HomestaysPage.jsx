import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  ArrowRight,
  BedDouble,
  MapPin,
  RotateCcw,
  Search,
  SlidersHorizontal,
  Users,
} from 'lucide-react'
import Header from '../components/Header'
import Footer from '../components/Footer'
import '../styles/homestays.css'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL || 'http://localhost:5171/api'
).replace(/\/$/, '')

function formatMoney(value) {
  const number = Number(value)

  if (!Number.isFinite(number)) {
    return 'Liên hệ'
  }

  return `${new Intl.NumberFormat('vi-VN').format(number)}đ`
}

function getRankLabel(rank) {
  const labels = {
    standard: 'Tiêu chuẩn',
    premium: 'Thượng hạng',
    deluxe: 'Cao cấp',
  }

  return labels[rank?.toLowerCase()] || rank || 'Homestay'
}

function HomestaysPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [province, setProvince] = useState(
    searchParams.get('province') || '',
  )
  const [destination, setDestination] = useState(
    searchParams.get('destination') || '',
  )
  const [guests, setGuests] = useState(
    searchParams.get('guests') || '',
  )
  const [sortBy, setSortBy] = useState('newest')
  const [homestays, setHomestays] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    const controller = new AbortController()
    const requestParams = new URLSearchParams()

    const provinceValue = searchParams.get('province')?.trim()
    const destinationValue = searchParams.get('destination')?.trim()
    const guestsValue = searchParams.get('guests')

    if (provinceValue) {
      requestParams.set('province', provinceValue)
    }

    if (destinationValue) {
      requestParams.set('destination', destinationValue)
    }

    if (guestsValue) {
      requestParams.set('guests', guestsValue)
    }

    const loadHomestays = async () => {
      setLoading(true)
      setError('')

      try {
        const queryString = requestParams.toString()
        const response = await fetch(
          `${API_BASE_URL}/homestays${queryString ? `?${queryString}` : ''}`,
          { signal: controller.signal },
        )
        const data = await response.json().catch(() => null)

        if (!response.ok) {
          throw new Error(
            data?.message || 'Không thể tải danh sách homestay.',
          )
        }

        setHomestays(Array.isArray(data?.homestays) ? data.homestays : [])
      } catch (requestError) {
        if (requestError.name !== 'AbortError') {
          setHomestays([])
          setError(
            requestError.message === 'Failed to fetch'
              ? 'Không thể kết nối tới máy chủ. Vui lòng kiểm tra API đang chạy.'
              : requestError.message,
          )
        }
      } finally {
        if (!controller.signal.aborted) {
          setLoading(false)
        }
      }
    }

    loadHomestays()

    return () => controller.abort()
  }, [searchParams])

  const sortedHomestays = useMemo(() => {
    const result = [...homestays]

    if (sortBy === 'price-low') {
      return result.sort(
        (first, second) => Number(first.priceFrom) - Number(second.priceFrom),
      )
    }

    if (sortBy === 'price-high') {
      return result.sort(
        (first, second) => Number(second.priceFrom) - Number(first.priceFrom),
      )
    }

    return result
  }, [homestays, sortBy])

  const handleSearch = (event) => {
    event.preventDefault()

    const nextParams = new URLSearchParams()

    if (province.trim()) {
      nextParams.set('province', province.trim())
    }

    if (destination.trim()) {
      nextParams.set('destination', destination.trim())
    }

    if (guests) {
      nextParams.set('guests', guests)
    }

    setSearchParams(nextParams)
  }

  const handleReset = () => {
    setProvince('')
    setDestination('')
    setGuests('')
    setSortBy('newest')
    setSearchParams({})
  }

  return (
    <div className="homestays-page">
      <Header />

      <main>
        <section className="homestays-hero">
          <div className="homestays-container">
            <p className="homestays-eyebrow">KHÁM PHÁ CHỖ Ở</p>
            <h1>Tìm homestay dành cho chuyến đi của bạn</h1>
            <p>
              Những căn phòng riêng tư gần các điểm đến nổi bật trên khắp
              Việt Nam.
            </p>

            <form className="homestays-search" onSubmit={handleSearch}>
              <label className="homestays-field">
                <span>Tỉnh / thành phố</span>
                <div>
                  <MapPin size={19} />
                  <input
                    type="text"
                    value={province}
                    onChange={(event) => setProvince(event.target.value)}
                    placeholder="Ví dụ: Hà Nội"
                  />
                </div>
              </label>

              <label className="homestays-field">
                <span>Điểm du lịch</span>
                <div>
                  <Search size={19} />
                  <input
                    type="text"
                    value={destination}
                    onChange={(event) => setDestination(event.target.value)}
                    placeholder="Ví dụ: Tây Hồ"
                  />
                </div>
              </label>

              <label className="homestays-field homestays-guests-field">
                <span>Số khách</span>
                <div>
                  <Users size={19} />
                  <select
                    value={guests}
                    onChange={(event) => setGuests(event.target.value)}
                  >
                    <option value="">Tất cả</option>
                    <option value="1">1 khách</option>
                    <option value="2">2 khách</option>
                    <option value="3">3 khách</option>
                    <option value="4">4 khách</option>
                  </select>
                </div>
              </label>

              <button className="homestays-search-button" type="submit">
                <Search size={20} />
                Tìm kiếm
              </button>
            </form>
          </div>
        </section>

        <section className="homestays-results">
          <div className="homestays-container">
            <div className="homestays-results-heading">
              <div>
                <p className="homestays-eyebrow">GỢI Ý DÀNH CHO BẠN</p>
                <h2>Danh sách homestay</h2>
                <span>
                  {loading
                    ? 'Đang tìm kiếm...'
                    : `${sortedHomestays.length} chỗ ở phù hợp`}
                </span>
              </div>

              <div className="homestays-toolbar">
                <label>
                  <SlidersHorizontal size={18} />
                  <select
                    value={sortBy}
                    onChange={(event) => setSortBy(event.target.value)}
                    aria-label="Sắp xếp danh sách"
                  >
                    <option value="newest">Mới nhất</option>
                    <option value="price-low">Giá thấp đến cao</option>
                    <option value="price-high">Giá cao đến thấp</option>
                  </select>
                </label>

                <button type="button" onClick={handleReset}>
                  <RotateCcw size={17} />
                  Xóa bộ lọc
                </button>
              </div>
            </div>

            {loading && (
              <div className="homestays-grid" aria-label="Đang tải">
                {[1, 2, 3, 4].map((item) => (
                  <div className="homestay-skeleton" key={item}>
                    <div />
                    <span />
                    <span />
                    <span />
                  </div>
                ))}
              </div>
            )}

            {!loading && error && (
              <div className="homestays-message homestays-error">
                <h3>Chưa tải được danh sách</h3>
                <p>{error}</p>
                <button type="button" onClick={() => window.location.reload()}>
                  Thử lại
                </button>
              </div>
            )}

            {!loading && !error && sortedHomestays.length === 0 && (
              <div className="homestays-message">
                <Search size={34} />
                <h3>Không tìm thấy homestay phù hợp</h3>
                <p>Hãy thử đổi địa điểm hoặc số lượng khách.</p>
                <button type="button" onClick={handleReset}>
                  Xem tất cả homestay
                </button>
              </div>
            )}

            {!loading && !error && sortedHomestays.length > 0 && (
              <div className="homestays-grid">
                {sortedHomestays.map((homestay) => (
                  <article className="homestay-card" key={homestay.id}>
                    <Link
                      className="homestay-card-image"
                      to={`/homestays/${homestay.slug}`}
                      aria-label={`Xem ${homestay.name}`}
                    >
                      {homestay.coverImageUrl ? (
                        <img
                          src={homestay.coverImageUrl}
                          alt={homestay.name}
                          loading="lazy"
                        />
                      ) : (
                        <div className="homestay-no-image">
                          <BedDouble size={36} />
                          <span>Chưa có ảnh</span>
                        </div>
                      )}

                      <span className="homestay-rank">
                        {getRankLabel(homestay.roomRank)}
                      </span>
                    </Link>

                    <div className="homestay-card-body">
                      <div className="homestay-location">
                        <MapPin size={17} />
                        <span>
                          {homestay.touristDestination}, {homestay.province}
                        </span>
                      </div>

                      <h3>
                        <Link to={`/homestays/${homestay.slug}`}>
                          {homestay.name}
                        </Link>
                      </h3>

                      <div className="homestay-capacity">
                        <Users size={17} />
                        Tối đa {homestay.maxGuests} khách
                      </div>

                      <div className="homestay-card-footer">
                        <div>
                          <span>Giá từ</span>
                          <strong>{formatMoney(homestay.priceFrom)}</strong>
                          <small>/ giờ</small>
                        </div>

                        <Link to={`/homestays/${homestay.slug}`}>
                          Xem phòng
                          <ArrowRight size={18} />
                        </Link>
                      </div>

                      <p className="homestay-overnight-price">
                        Qua đêm từ {formatMoney(homestay.priceTo)}
                      </p>
                    </div>
                  </article>
                ))}
              </div>
            )}
          </div>
        </section>
      </main>

      <Footer />
    </div>
  )
}

export default HomestaysPage
