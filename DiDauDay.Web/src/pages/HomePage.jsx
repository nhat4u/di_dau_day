import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  BadgeCheck,
  CalendarCheck,
  CalendarDays,
  ChevronRight,
  Clock3,
  Heart,
  House,
  MapPin,
  Search,
  Sparkles,
  Star,
  Users,
} from 'lucide-react'
import Header from '../components/Header'
import Footer from '../components/Footer'
import DestinationCarousel from '../components/DestinationCarousel'
import api from '../services/api'
import '../styles/home.css'

const destinations = [
  {
    name: 'Hà Nội',
    description: 'Thủ đô nghìn năm văn hiến',
    image: '/images/ha-noi.jpg',
  },
  {
    name: 'Đà Nẵng',
    description: 'Thành phố biển đáng sống',
    image: '/images/da-nang.jpg',
  },
  {
    name: 'Đà Lạt',
    description: 'Thành phố ngàn hoa',
    image: '/images/da-lat.jpg',
  },
  {
    name: 'Sa Pa',
    description: 'Nơi gặp gỡ đất trời',
    image: '/images/sa-pa.jpg',
  },
  {
    name: 'Hội An',
    description: 'Phố cổ đầy hoài niệm',
    image: '/images/hoi-an.jpg',
  },
  {
    name: 'Nha Trang',
    description: 'Biển xanh và nắng vàng',
    image: '/images/nha-trang.jpg',
  },
]

const roomRankLabels = {
  standard: 'Tiêu chuẩn',
  deluxe: 'Cao cấp',
  premium: 'Thượng hạng',
}

function formatPrice(value) {
  return `${new Intl.NumberFormat('vi-VN').format(value || 0)}đ`
}

function getImageUrl(imageUrl) {
  if (!imageUrl) {
    return '/images/da-lat.jpg'
  }

  if (imageUrl.startsWith('http://') || imageUrl.startsWith('https://')) {
    return imageUrl
  }

  return imageUrl.startsWith('/') ? imageUrl : `/${imageUrl}`
}

function normalizeText(value = '') {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd')
    .replace(/Đ/g, 'D')
    .toLowerCase()
    .trim()
}

function HomePage() {
  const [homestays, setHomestays] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [searchKeyword, setSearchKeyword] = useState('')

  const [searchForm, setSearchForm] = useState({
    destination: '',
    checkIn: '',
    checkOut: '',
    guests: '2',
  })

  const today = new Date().toISOString().split('T')[0]

  useEffect(() => {
    async function loadHomestays() {
      try {
        const response = await api.get('/homestays')
        setHomestays(response.data.homestays || [])
      } catch {
        setLoadError(
          'Chưa tải được danh sách homestay. Hãy kiểm tra API .NET đang chạy.',
        )
      } finally {
        setLoading(false)
      }
    }

    loadHomestays()
  }, [])

  function handleSearchChange(event) {
    const { name, value } = event.target

    setSearchForm((currentForm) => ({
      ...currentForm,
      [name]: value,
    }))
  }

  function handleSearch(event) {
    event.preventDefault()

    const query = new URLSearchParams()

    if (searchForm.destination) {
      query.set('destination', searchForm.destination)
    }

    if (searchForm.checkIn) {
      query.set('checkIn', searchForm.checkIn)
    }

    if (searchForm.checkOut) {
      query.set('checkOut', searchForm.checkOut)
    }

    query.set('guests', searchForm.guests)

    navigate(`/homestays?${query.toString()}`)
  }

  return (
    <>
      <Header />

      <main>
        <section className="home-hero">
          <span className="hero-decoration hero-decoration-one" />
          <span className="hero-decoration hero-decoration-two" />

          <div className="container hero-content">
            <div className="hero-eyebrow">
              <Sparkles size={16} />
              Khám phá những chốn dừng chân tuyệt vời
            </div>

            <h1>Hôm nay mình đi đâu đây?</h1>

            <p className="hero-description">
            <p>
              Một chốn nhỏ cho chuyến đi lớn.
              <br />
              Tìm và đặt homestay tại những điểm đến tuyệt đẹp trên khắp Việt Nam.
            </p>
            </p>

            <form className="search-panel" onSubmit={handleSearch}>
              <label className="search-field">
                <span className="search-field-icon">
                  <MapPin size={20} />
                </span>

                <span className="search-field-content">
                  <small>Điểm đến</small>

                <input
                  type="text"
                  name="destination"
                  value={searchForm.destination}
                  onChange={handleSearchChange}
                  placeholder="Nhập địa điểm bạn muốn đến"
                  autoComplete="off"
                />
                </span>
              </label>

              <label className="search-field">
                <span className="search-field-icon">
                  <CalendarDays size={20} />
                </span>

                <span className="search-field-content">
                  <small>Nhận phòng</small>

                  <input
                    type="date"
                    name="checkIn"
                    min={today}
                    value={searchForm.checkIn}
                    onChange={handleSearchChange}
                  />
                </span>
              </label>

              <label className="search-field">
                <span className="search-field-icon">
                  <CalendarDays size={20} />
                </span>

                <span className="search-field-content">
                  <small>Trả phòng</small>

                  <input
                    type="date"
                    name="checkOut"
                    min={searchForm.checkIn || today}
                    value={searchForm.checkOut}
                    onChange={handleSearchChange}
                  />
                </span>
              </label>

              <label className="search-field">
                <span className="search-field-icon">
                  <Users size={20} />
                </span>

                <span className="search-field-content">
                  <small>Số khách</small>

                  <select
                    name="guests"
                    value={searchForm.guests}
                    onChange={handleSearchChange}
                  >
                    <option value="1">1 khách</option>
                    <option value="2">2 khách</option>
                    <option value="3">3 khách</option>
                    <option value="4">4 khách</option>
                  </select>
                </span>
              </label>

              <button className="search-button" type="submit">
                <Search size={20} />
                <span>Tìm homestay</span>
              </button>
            </form>

            <div className="hero-popular">
              <span>Được tìm nhiều:</span>
              <Link to="/homestays?destination=Hà Nội">Hà Nội</Link>
              <Link to="/homestays?destination=Đà Nẵng">Đà Nẵng</Link>
              <Link to="/homestays?destination=Đà Lạt">Đà Lạt</Link>
            </div>
          </div>
        </section>

        <section className="home-section" id="destinations">
          <div className="container">
            <div className="section-heading">
              <div>
                <span className="section-eyebrow">
                  Điểm đến nổi bật
                </span>

                <h2>Bạn muốn thức dậy ở đâu?</h2>

                <p>
                  Những điểm đến được yêu thích dành cho chuyến đi tiếp theo.
                </p>
              </div>

              <Link className="section-link" to="/homestays">
                Khám phá homestay
                <ChevronRight size={18} />
              </Link>
            </div>

            <DestinationCarousel destinations={destinations} />
          </div>
        </section>

        <section className="home-section featured-section">
          <div className="container">
            <div className="section-heading">
              <div>
                <span className="section-eyebrow">
                  Gợi ý dành cho bạn
                </span>

                <h2>Homestay nổi bật</h2>

                <p>
                  Những căn phòng được yêu thích với không gian riêng tư.
                </p>
              </div>

              <Link className="section-link" to="/homestays">
                Khám phá thêm
                <ChevronRight size={18} />
              </Link>
            </div>

            {loadError && (
              <div className="home-notice">
                {loadError}
              </div>
            )}

            <div className="homestay-grid">
              {loading &&
                [1, 2, 3, 4].map((item) => (
                  <div
                    className="homestay-card homestay-skeleton"
                    key={item}
                  >
                    <div className="skeleton-image" />

                    <div className="skeleton-content">
                      <span />
                      <span />
                      <span />
                    </div>
                  </div>
                ))}

              {!loading &&
                homestays.slice(0, 4).map((homestay) => (
                  <article className="homestay-card" key={homestay.id}>
                    <div className="homestay-image-wrapper">
                      <Link to={`/homestays/${homestay.slug}`}>
                        <img
                          src={getImageUrl(homestay.coverImageUrl)}
                          alt={homestay.name}
                          onError={(event) => {
                            event.currentTarget.src = '/images/da-lat.jpg'
                          }}
                        />
                      </Link>

                      <span className="room-rank">
                        {roomRankLabels[homestay.roomRank] ||
                          homestay.roomRank}
                      </span>

                      <button
                        className="favorite-button"
                        type="button"
                        aria-label="Thêm vào yêu thích"
                      >
                        <Heart size={19} />
                      </button>
                    </div>

                    <div className="homestay-card-content">
                      <div className="homestay-location">
                        <MapPin size={15} />

                        <span>
                          {homestay.touristDestination
                            ? `${homestay.touristDestination}, `
                            : ''}

                          {homestay.province}
                        </span>
                      </div>

                      <Link
                        className="homestay-name"
                       to={`/homestays/${homestay.slug}`}
                      >
                        {homestay.name}
                      </Link>

                      <div className="homestay-meta">
                        <span>
                          <Users size={15} />
                          Tối đa {homestay.maxGuests} khách
                        </span>

                        <span>
                          <Star size={15} fill="currentColor" />
                          4.9
                        </span>
                      </div>

                      <div className="homestay-price">
                        <div>
                          <small>Giá từ</small>
                          <strong>
                            {formatPrice(homestay.priceFrom)}
                          </strong>
                        </div>

                        <span>-</span>

                        <div>
                          <small>Đến</small>
                          <strong>
                            {formatPrice(homestay.priceTo)}
                          </strong>
                        </div>
                      </div>
                    </div>
                  </article>
                ))}
            </div>

            {!loading && !loadError && homestays.length === 0 && (
              <div className="home-empty">
                Hiện chưa có homestay nào để hiển thị.
              </div>
            )}
          </div>
        </section>

        <section
          className="home-section how-it-works"
          id="how-it-works"
        >
          <div className="container">
            <div className="centered-heading">
              <span className="section-eyebrow">
                Vì sao chọn chúng tôi?
              </span>

              <h2>Một chuyến đi thật dễ dàng</h2>

              <p>
                Đi Đâu Đây giúp bạn tìm được nơi nghỉ phù hợp chỉ trong
                vài bước đơn giản.
              </p>
            </div>

            <div className="benefit-grid">
              <article className="benefit-card">
                <span className="benefit-icon">
                  <House size={27} />
                </span>

                <h3>Nhiều sự lựa chọn</h3>

                <p>
                  Homestay đa dạng tại những điểm du lịch nổi tiếng trên
                  khắp Việt Nam.
                </p>
              </article>

              <article className="benefit-card">
                <span className="benefit-icon">
                  <BadgeCheck size={27} />
                </span>

                <h3>Thông tin rõ ràng</h3>

                <p>
                  Giá thuê, tiện nghi và hình ảnh được trình bày đầy đủ,
                  dễ dàng so sánh.
                </p>
              </article>

              <article className="benefit-card">
                <span className="benefit-icon">
                  <CalendarCheck size={27} />
                </span>

                <h3>Đặt phòng thuận tiện</h3>

                <p>
                  Chọn thời gian, kiểm tra lịch trống và đặt phòng nhanh
                  chóng trên một màn hình.
                </p>
              </article>
            </div>

            <div className="home-trust">
              <span>
                <Clock3 size={19} />
                Đặt phòng nhanh chóng
              </span>

              <span>
                <BadgeCheck size={19} />
                Thông tin minh bạch
              </span>

              <span>
                <Users size={19} />
                Không gian riêng tư
              </span>
            </div>
          </div>
        </section>
      </main>

      <Footer />
    </>
  )
}

export default HomePage