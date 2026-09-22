import { useEffect, useState } from 'react'
import {
  ArrowLeft,
  CalendarDays,
  Check,
  ChevronLeft,
  ChevronRight,
  Clock3,
  Heart,
  House,
  MapPin,
  ShieldCheck,
  Star,
  Users,
  X,
} from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api from '../services/api'
import '../styles/homestay-detail.css'
import '../styles/booking-modal.css'

const roomRankLabels = {
  standard: 'Tiêu chuẩn',
  deluxe: 'Cao cấp',
  premium: 'Thượng hạng',
}

const bookingTypeLabels = {
  hourly: 'Thuê theo giờ',
  daytime: 'Thuê ban ngày',
  overnight: 'Thuê qua đêm',
  day_night: 'Thuê ngày và đêm',
}

const HOURLY_OPEN_MINUTE = 11 * 60
const HOURLY_CLOSE_MINUTE = 21 * 60

function formatPrice(value) {
  if (value === null || value === undefined) {
    return 'Liên hệ'
  }

  return `${new Intl.NumberFormat('vi-VN').format(value)}đ`
}

function getImageUrl(imageUrl) {
  if (!imageUrl) {
    return '/images/da-lat.jpg'
  }

  if (
    imageUrl.startsWith('http://') ||
    imageUrl.startsWith('https://')
  ) {
    return imageUrl
  }

  return imageUrl.startsWith('/') ? imageUrl : `/${imageUrl}`
}

function padNumber(value) {
  return String(value).padStart(2, '0')
}

function formatDateInput(date) {
  return `${date.getFullYear()}-${padNumber(
    date.getMonth() + 1,
  )}-${padNumber(date.getDate())}`
}

function formatDateTimeInput(date) {
  return `${formatDateInput(date)}T${padNumber(
    date.getHours(),
  )}:${padNumber(date.getMinutes())}`
}

function createInitialBookingForm() {
  const checkIn = new Date()
  checkIn.setDate(checkIn.getDate() + 1)
  checkIn.setHours(14, 0, 0, 0)

  const checkOut = new Date(checkIn)
  checkOut.setHours(16, 0, 0, 0)

  return {
    bookingType: 'hourly',
    bookingDate: formatDateInput(checkIn),
    checkIn: formatDateTimeInput(checkIn),
    checkOut: formatDateTimeInput(checkOut),
    guestCount: '1',
  }
}

function addOneDay(dateValue) {
  const [year, month, day] = dateValue
    .split('-')
    .map(Number)
  const date = new Date(year, month - 1, day)
  date.setDate(date.getDate() + 1)
  return formatDateInput(date)
}

function includeSeconds(dateTimeValue) {
  if (!dateTimeValue) {
    return ''
  }

  return dateTimeValue.length === 16
    ? `${dateTimeValue}:00`
    : dateTimeValue
}

function buildBookingTimes(form) {
  if (form.bookingType === 'hourly') {
    return {
      checkIn: includeSeconds(form.checkIn),
      checkOut: includeSeconds(form.checkOut),
    }
  }

  if (!form.bookingDate) {
    return null
  }

  if (form.bookingType === 'daytime') {
    return {
      checkIn: `${form.bookingDate}T11:00:00`,
      checkOut: `${form.bookingDate}T21:00:00`,
    }
  }

  const nextDate = addOneDay(form.bookingDate)

  if (form.bookingType === 'overnight') {
    return {
      checkIn: `${form.bookingDate}T22:00:00`,
      checkOut: `${nextDate}T10:00:00`,
    }
  }

  if (form.bookingType === 'day_night') {
    return {
      checkIn: `${form.bookingDate}T15:00:00`,
      checkOut: `${nextDate}T10:00:00`,
    }
  }

  return null
}

function formatDateTime(value) {
  if (!value) {
    return ''
  }

  return new Intl.DateTimeFormat('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(value))
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

function calculateEstimatedTotal(homestay, form, times) {
  if (!times) {
    return null
  }

  const prices = homestay.detailedPrices || {}

  if (form.bookingType === 'hourly') {
    const checkIn = new Date(times.checkIn)
    const checkOut = new Date(times.checkOut)
    const hours = (checkOut - checkIn) / 3600000
    const minimumHours = Math.max(
      2,
      Number(homestay.minimumHours || 2),
    )

    if (
      !Number.isInteger(hours * 2) ||
      hours < minimumHours ||
      hours > 24
    ) {
      return null
    }

    if (hours <= 2) {
      return Number(prices.priceFirst2Hours)
    }

    if (hours < 4) {
      return (
        Number(prices.priceFirst2Hours) +
        (hours - 2) * Number(prices.priceExtraHour)
      )
    }

    return (
      Number(prices.priceCombo4Hours) +
      (hours - 4) * Number(prices.priceExtraHour)
    )
  }

  const selectedDate = new Date(
    `${form.bookingDate}T12:00:00`,
  )
  const dayOfWeek = selectedDate.getDay()
  const isWeekend =
    dayOfWeek === 5 || dayOfWeek === 6 || dayOfWeek === 0

  const priceKeys = {
    daytime: isWeekend
      ? 'priceDayWeekend'
      : 'priceDayWeekday',
    overnight: isWeekend
      ? 'priceOvernightWeekend'
      : 'priceOvernightWeekday',
    day_night: isWeekend
      ? 'priceDayNightWeekend'
      : 'priceDayNightWeekday',
  }

  const selectedPrice = prices[priceKeys[form.bookingType]]

  return selectedPrice === null || selectedPrice === undefined
    ? null
    : Number(selectedPrice)
}

function HomestayDetailPage() {
  const { slug } = useParams()
  const navigate = useNavigate()

  const [homestay, setHomestay] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [selectedImageIndex, setSelectedImageIndex] = useState(0)
  const [bookingModalOpen, setBookingModalOpen] = useState(false)
  const [bookingForm, setBookingForm] = useState(
    createInitialBookingForm,
  )
  const [bookingAction, setBookingAction] = useState('')
  const [availability, setAvailability] = useState(null)
  const [bookingMessage, setBookingMessage] = useState('')
  const [bookingMessageType, setBookingMessageType] =
    useState('')
  const [createdBooking, setCreatedBooking] = useState(null)

  useEffect(() => {
    async function loadHomestay() {
      try {
        setLoading(true)
        setError('')

        const response = await api.get(
          `/homestays/${encodeURIComponent(slug)}`,
        )

        if (!response.data.success || !response.data.homestay) {
          throw new Error(
            response.data.message || 'Không tìm thấy homestay.',
          )
        }

        const homestayData = response.data.homestay
        const images = homestayData.images || []

        setHomestay(homestayData)

        const coverIndex = images.findIndex(
          (image) => image.isCover,
        )

        setSelectedImageIndex(coverIndex >= 0 ? coverIndex : 0)
      } catch (requestError) {
        setError(
          requestError.response?.data?.message ||
            requestError.message ||
            'Không thể tải thông tin homestay.',
        )
      } finally {
        setLoading(false)
      }
    }

    loadHomestay()
  }, [slug])

  useEffect(() => {
    if (!bookingModalOpen) {
      return undefined
    }

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    function handleEscape(event) {
      if (event.key === 'Escape' && !bookingAction) {
        setBookingModalOpen(false)
      }
    }

    window.addEventListener('keydown', handleEscape)

    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', handleEscape)
    }
  }, [bookingModalOpen, bookingAction])

  function showPreviousImage() {
    if (!homestay?.images?.length) {
      return
    }

    setSelectedImageIndex((currentIndex) =>
      currentIndex === 0
        ? homestay.images.length - 1
        : currentIndex - 1,
    )
  }

  function showNextImage() {
    if (!homestay?.images?.length) {
      return
    }

    setSelectedImageIndex((currentIndex) =>
      currentIndex === homestay.images.length - 1
        ? 0
        : currentIndex + 1,
    )
  }

  function handleImageError(event) {
    if (event.currentTarget.dataset.hasFallback === 'true') {
      return
    }

    event.currentTarget.dataset.hasFallback = 'true'
    event.currentTarget.src = '/images/da-lat.jpg'
  }

  function openBookingModal() {
    const accessToken = localStorage.getItem('accessToken')

    if (!accessToken) {
      navigate('/login')
      return
    }

    const currentUser = getStoredUser()

    setBookingModalOpen(true)
    setAvailability(null)
    setCreatedBooking(null)

    if (currentUser?.role?.toLowerCase() !== 'guest') {
      setBookingMessage(
        'Bạn đang dùng tài khoản chủ homestay. Chỉ tài khoản khách hàng mới có thể đặt phòng.',
      )
      setBookingMessageType('error')
    } else {
      setBookingMessage('')
      setBookingMessageType('')
    }
  }

  function closeBookingModal() {
    if (!bookingAction) {
      setBookingModalOpen(false)
    }
  }

  function resetBookingResult() {
    setAvailability(null)
    setCreatedBooking(null)
    setBookingMessage('')
    setBookingMessageType('')
  }

  function handleBookingFieldChange(event) {
    const { name, value } = event.target

    setBookingForm((currentForm) => ({
      ...currentForm,
      [name]: value,
    }))

    resetBookingResult()
  }

  function validateBookingForm() {
    const times = buildBookingTimes(bookingForm)

    if (!times?.checkIn || !times?.checkOut) {
      return {
        valid: false,
        message: 'Vui lòng chọn đầy đủ ngày giờ đặt phòng.',
      }
    }

    const checkInDate = new Date(times.checkIn)
    const checkOutDate = new Date(times.checkOut)

    if (
      Number.isNaN(checkInDate.getTime()) ||
      Number.isNaN(checkOutDate.getTime())
    ) {
      return {
        valid: false,
        message: 'Ngày giờ đặt phòng không hợp lệ.',
      }
    }

    if (checkInDate <= new Date()) {
      return {
        valid: false,
        message: 'Thời gian nhận phòng phải ở tương lai.',
      }
    }

    if (checkOutDate <= checkInDate) {
      return {
        valid: false,
        message: 'Thời gian trả phòng phải sau thời gian nhận phòng.',
      }
    }

    const guestCount = Number(bookingForm.guestCount)

    if (
      !Number.isInteger(guestCount) ||
      guestCount < 1 ||
      guestCount > homestay.maxGuests
    ) {
      return {
        valid: false,
        message:
          'Số khách phải từ 1 đến ' +
          homestay.maxGuests +
          '.',
      }
    }

    if (bookingForm.bookingType === 'hourly') {
      const checkInMinute =
        checkInDate.getHours() * 60 + checkInDate.getMinutes()
      const checkOutMinute =
        checkOutDate.getHours() * 60 + checkOutDate.getMinutes()
      const hours = (checkOutDate - checkInDate) / 3600000
      const minimumHours = Math.max(
        2,
        Number(homestay.minimumHours || 2),
      )

      if (
        checkInDate.toDateString() !== checkOutDate.toDateString() ||
        checkInMinute < HOURLY_OPEN_MINUTE ||
        checkOutMinute > HOURLY_CLOSE_MINUTE
      ) {
        return {
          valid: false,
          message:
            'Thuê theo giờ chỉ được đặt trong khung 11:00–21:00.',
        }
      }

      if (!Number.isInteger(hours * 2)) {
        return {
          valid: false,
          message:
            'Thời gian thuê phải theo mốc :00 hoặc :30.',
        }
      }

      if (hours < minimumHours) {
        return {
          valid: false,
          message:
            'Phải đặt tối thiểu ' + minimumHours + ' giờ.',
        }
      }

      if (hours > 24) {
        return {
          valid: false,
          message: 'Thuê theo giờ không được vượt quá 24 giờ.',
        }
      }
    }

    return {
      valid: true,
      times,
      guestCount,
    }
  }

  async function checkBookingAvailability() {
    const validation = validateBookingForm()

    if (!validation.valid) {
      setAvailability(false)
      setBookingMessage(validation.message)
      setBookingMessageType('error')
      return
    }

    try {
      setBookingAction('checking')
      setBookingMessage('')
      setBookingMessageType('')
      setCreatedBooking(null)

      const response = await api.get('/bookings/availability', {
        params: {
          homestayId: homestay.id,
          checkIn: validation.times.checkIn,
          checkOut: validation.times.checkOut,
        },
      })

      const isAvailable = response.data.available === true

      setAvailability(isAvailable)
      setBookingMessage(
        response.data.message ||
          (isAvailable
            ? 'Khoảng thời gian này còn trống.'
            : 'Khoảng thời gian này đã có người đặt.'),
      )
      setBookingMessageType(isAvailable ? 'success' : 'error')
    } catch (requestError) {
      setAvailability(false)
      setBookingMessage(
        requestError.response?.data?.message ||
          'Không thể kiểm tra lịch trống.',
      )
      setBookingMessageType('error')
    } finally {
      setBookingAction('')
    }
  }

  async function createBooking(event) {
    event.preventDefault()

    const accessToken = localStorage.getItem('accessToken')
    const currentUser = getStoredUser()

    if (!accessToken) {
      navigate('/login')
      return
    }

    if (currentUser?.role?.toLowerCase() !== 'guest') {
      setBookingMessage(
        'Bạn đang dùng tài khoản chủ homestay. Chỉ tài khoản khách hàng mới có thể đặt phòng.',
      )
      setBookingMessageType('error')
      return
    }

    if (availability !== true) {
      setBookingMessage(
        'Bạn cần kiểm tra lịch trống trước khi xác nhận đặt phòng.',
      )
      setBookingMessageType('error')
      return
    }

    const validation = validateBookingForm()

    if (!validation.valid) {
      setAvailability(false)
      setBookingMessage(validation.message)
      setBookingMessageType('error')
      return
    }

    try {
      setBookingAction('creating')
      setBookingMessage('')
      setBookingMessageType('')

      const response = await api.post('/bookings', {
        homestayId: homestay.id,
        bookingType: bookingForm.bookingType,
        checkIn: validation.times.checkIn,
        checkOut: validation.times.checkOut,
        guestCount: validation.guestCount,
      })

      setCreatedBooking(response.data.booking)
      setBookingMessage(
        response.data.message || 'Tạo đơn đặt phòng thành công.',
      )
      setBookingMessageType('success')
    } catch (requestError) {
      if (requestError.response?.status === 409) {
        setAvailability(false)
      }

      setBookingMessage(
        requestError.response?.data?.message ||
          'Không thể tạo đơn đặt phòng.',
      )
      setBookingMessageType('error')
    } finally {
      setBookingAction('')
    }
  }

  if (loading) {
    return (
      <>
        <Header />

        <main className="detail-state-page">
          <div className="detail-loader" />
          <h2>Đang tải thông tin homestay...</h2>
        </main>

        <Footer />
      </>
    )
  }

  if (error || !homestay) {
    return (
      <>
        <Header />

        <main className="detail-state-page">
          <House size={52} />
          <h2>Không tìm thấy homestay</h2>
          <p>{error}</p>

          <Link className="detail-back-button" to="/">
            <ArrowLeft size={18} />
            Quay lại trang chủ
          </Link>
        </main>

        <Footer />
      </>
    )
  }

  const images = homestay.images || []
  const selectedImage = images[selectedImageIndex]
  const detailedPrices = homestay.detailedPrices || {}

  const hourlyPrices = [
    {
      label: 'Giá 2 giờ đầu',
      value: detailedPrices.priceFirst2Hours,
    },
    {
      label: 'Combo 4 giờ',
      value: detailedPrices.priceCombo4Hours,
    },
    {
      label: 'Mỗi giờ phát sinh',
      value: detailedPrices.priceExtraHour,
    },
  ].filter((price) => price.value !== null && price.value !== undefined)

  const overnightPrices = [
    {
      label: 'Qua đêm ngày thường',
      value: detailedPrices.priceOvernightWeekday,
    },
    {
      label: 'Qua đêm cuối tuần',
      value: detailedPrices.priceOvernightWeekend,
    },
    {
      label: 'Trong ngày ngày thường',
      value: detailedPrices.priceDayWeekday,
    },
    {
      label: 'Trong ngày cuối tuần',
      value: detailedPrices.priceDayWeekend,
    },
    {
      label: 'Ngày đêm ngày thường',
      value: detailedPrices.priceDayNightWeekday,
    },
    {
      label: 'Ngày đêm cuối tuần',
      value: detailedPrices.priceDayNightWeekend,
    },
  ].filter((price) => price.value !== null && price.value !== undefined)

  const amenities = Array.isArray(homestay.amenities)
    ? homestay.amenities
    : [
      ...(homestay.defaultAmenities || []),
      ...(homestay.optionalAmenities || []),
    ]

  const bookingTimes = buildBookingTimes(bookingForm)
  const estimatedTotal = calculateEstimatedTotal(
    homestay,
    bookingForm,
    bookingTimes,
  )
  const currentDate = formatDateInput(new Date())
  const canCurrentUserBook =
    getStoredUser()?.role?.toLowerCase() === 'guest'

  const bookingTypeNotes = {
    hourly:
      'Đặt trong khung 11:00–21:00, tối thiểu ' +
      homestay.minimumHours +
      ' giờ.',
    daytime: 'Nhận phòng lúc 11:00 và trả phòng lúc 21:00.',
    overnight:
      'Nhận phòng lúc 22:00 và trả phòng lúc 10:00 sáng hôm sau.',
    day_night:
      'Nhận phòng lúc 15:00 và trả phòng lúc 10:00 sáng hôm sau.',
  }

  return (
    <>
      <Header />

      <main className="homestay-detail-page">
        <div className="container">
          <Link className="detail-return-link" to="/">
            <ArrowLeft size={18} />
            Quay lại danh sách
          </Link>

          <header className="detail-heading">
            <div>
              <div className="detail-badges">
                <span className="detail-rank">
                  {roomRankLabels[homestay.roomRank] ||
                    homestay.roomRank}
                </span>

                <span className="detail-rating">
                  <Star size={15} fill="currentColor" />
                  4.9
                </span>
              </div>

              <h1>{homestay.name}</h1>

              <div className="detail-location">
                <MapPin size={18} />

                <span>
                  {homestay.address}, {homestay.province}
                </span>
              </div>
            </div>

            <button
              className="detail-favorite-button"
              type="button"
            >
              <Heart size={20} />
              Yêu thích
            </button>
          </header>

          <section className="detail-gallery">
            <div className="detail-main-image">
              <img
                src={getImageUrl(selectedImage?.imageUrl)}
                alt={`${homestay.name} - ảnh ${
                  selectedImageIndex + 1
                }`}
                onError={handleImageError}
              />

              {images.length > 1 && (
                <>
                  <button
                    className="gallery-arrow gallery-arrow-left"
                    type="button"
                    onClick={showPreviousImage}
                    aria-label="Ảnh trước"
                  >
                    <ChevronLeft size={25} />
                  </button>

                  <button
                    className="gallery-arrow gallery-arrow-right"
                    type="button"
                    onClick={showNextImage}
                    aria-label="Ảnh tiếp theo"
                  >
                    <ChevronRight size={25} />
                  </button>
                </>
              )}

              <span className="gallery-counter">
                {images.length > 0
                  ? `${selectedImageIndex + 1} / ${images.length}`
                  : 'Chưa có ảnh'}
              </span>
            </div>

            {images.length > 1 && (
              <div className="detail-thumbnails">
                {images.map((image, index) => (
                  <button
                    className={
                      index === selectedImageIndex
                        ? 'detail-thumbnail active'
                        : 'detail-thumbnail'
                    }
                    type="button"
                    key={image.id}
                    onClick={() => setSelectedImageIndex(index)}
                  >
                    <img
                      src={getImageUrl(image.imageUrl)}
                      alt={`Ảnh nhỏ ${index + 1}`}
                      onError={handleImageError}
                    />
                  </button>
                ))}
              </div>
            )}
          </section>

          <div className="detail-content-layout">
            <div className="detail-main-content">
              <section className="detail-overview">
                <div>
                  <span className="overview-icon">
                    <Users size={23} />
                  </span>

                  <span>
                    <small>Sức chứa</small>
                    <strong>
                      Tối đa {homestay.maxGuests} khách
                    </strong>
                  </span>
                </div>

                <div>
                  <span className="overview-icon">
                    <Clock3 size={23} />
                  </span>

                  <span>
                    <small>Thuê theo giờ</small>
                    <strong>
                      Tối thiểu {homestay.minimumHours} giờ
                    </strong>
                  </span>
                </div>

                <div>
                  <span className="overview-icon">
                    <ShieldCheck size={23} />
                  </span>

                  <span>
                    <small>Nhận phòng</small>
                    <strong>
                      {homestay.autoCheckin
                        ? 'Tự check-in/out'
                        : 'Có người hỗ trợ'}
                    </strong>
                  </span>
                </div>
              </section>

              <section className="detail-section">
                <span className="detail-section-eyebrow">
                  Giới thiệu
                </span>

                <h2>Về {homestay.name}</h2>

                <p className="detail-description">
                  {homestay.description}
                </p>
              </section>

              <section className="detail-section">
                <span className="detail-section-eyebrow">
                  Tiện nghi
                </span>

                <h2>Homestay có những gì?</h2>

                {amenities.length > 0 ? (
                  <div className="amenities-grid">
                    {amenities.map((amenity) => (
                      <div className="amenity-item" key={amenity}>
                        <span>
                          <Check size={17} />
                        </span>

                        {amenity}
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="detail-description">
                    Chủ homestay chưa cập nhật danh sách tiện ích.
                  </p>
                )}
              </section>

              <section className="detail-section">
                <span className="detail-section-eyebrow">
                  Bảng giá
                </span>

                <h2>Giá thuê homestay</h2>

                <div className="price-groups">
                  <div className="price-group">
                    <h3>
                      <Clock3 size={20} />
                      Thuê theo giờ
                    </h3>

                    <div className="price-list">
                      {hourlyPrices.map((price) => (
                        <div
                          className="price-row"
                          key={price.label}
                        >
                          <span>{price.label}</span>
                          <strong>{formatPrice(price.value)}</strong>
                        </div>
                      ))}
                    </div>
                  </div>

                  <div className="price-group">
                    <h3>
                      <CalendarDays size={20} />
                      Thuê trong ngày và qua đêm
                    </h3>

                    <div className="price-list">
                      {overnightPrices.map((price) => (
                        <div
                          className="price-row"
                          key={price.label}
                        >
                          <span>{price.label}</span>
                          <strong>{formatPrice(price.value)}</strong>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              </section>

              <section className="detail-owner">
                <span className="owner-avatar">
                  {homestay.owner?.fullName
                    ?.charAt(0)
                    .toUpperCase() || 'C'}
                </span>

                <div>
                  <small>Chủ homestay</small>
                  <h3>
                    {homestay.owner?.fullName ||
                      'Chủ homestay Đi Đâu Đây'}
                  </h3>
                  <p>Thông tin chủ homestay đã được QTV xác minh.</p>
                </div>

                <ShieldCheck size={25} />
              </section>
            </div>

            <aside className="detail-booking-card" id="booking-box">
              <span className="booking-card-label">
                Giá thuê từ
              </span>

              <div className="booking-main-price">
                <strong>{formatPrice(homestay.pricePerHour)}</strong>
                <span>/ giờ</span>
              </div>

              <div className="booking-price-information">
                <div>
                  <span>Thuê tối thiểu</span>
                  <strong>{homestay.minimumHours} giờ</strong>
                </div>

                <div>
                  <span>Giá qua đêm</span>
                  <strong>
                    {formatPrice(homestay.overnightPrice)}
                  </strong>
                </div>

                <div>
                  <span>Sức chứa</span>
                  <strong>{homestay.maxGuests} khách</strong>
                </div>
              </div>

              <button
                className="booking-button"
                type="button"
                onClick={openBookingModal}
              >
                <CalendarDays size={20} />
                Chọn thời gian đặt phòng
              </button>

              <p className="booking-note">
                Bạn chưa bị tính phí ở bước này.
              </p>
            </aside>
          </div>
        </div>
      </main>

      {bookingModalOpen && (
        <div
          className="booking-modal-backdrop"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) {
              closeBookingModal()
            }
          }}
        >
          <section
            className="booking-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="booking-modal-title"
          >
            <header className="booking-modal-header">
              <div>
                <span>Đặt homestay</span>
                <h2 id="booking-modal-title">{homestay.name}</h2>
              </div>

              <button
                className="booking-modal-close"
                type="button"
                aria-label="Đóng cửa sổ đặt phòng"
                disabled={Boolean(bookingAction)}
                onClick={closeBookingModal}
              >
                <X size={22} />
              </button>
            </header>

            {createdBooking ? (
              <div className="booking-success-panel">
                <span className="booking-success-icon">
                  <Check size={32} />
                </span>

                <h3>Đã giữ chỗ thành công!</h3>
                <p>
                   Đơn chưa được thanh toán. Vui lòng hoàn tất thanh toán
                  trong vòng 5 phút để xác nhận đặt phòng.
                </p>

                <div className="created-booking-information">
                  <div>
                    <span>Mã đặt phòng</span>
                    <strong>{createdBooking.bookingCode}</strong>
                  </div>

                  <div>
                    <span>Loại thuê</span>
                    <strong>
                      {bookingTypeLabels[
                        createdBooking.bookingType
                      ] || createdBooking.bookingType}
                    </strong>
                  </div>

                  <div>
                    <span>Nhận phòng</span>
                    <strong>
                      {formatDateTime(createdBooking.checkIn)}
                    </strong>
                  </div>

                  <div>
                    <span>Trả phòng</span>
                    <strong>
                      {formatDateTime(createdBooking.checkOut)}
                    </strong>
                  </div>

                  <div>
                    <span>Số khách</span>
                    <strong>
                      {createdBooking.guestCount} khách
                    </strong>
                  </div>

                  <div>
                    <span>Tổng tiền</span>
                    <strong className="created-booking-total">
                      {formatPrice(createdBooking.totalAmount)}
                    </strong>
                  </div>
                </div>

                <button
                  className="booking-finish-button"
                  type="button"
                  onClick={() =>
                    navigate(
                       `/my-bookings?bookingId=${createdBooking.id}`,
                            )
                          }
                >
                  Tiếp tục thanh toán
                </button>
              </div>
            ) : (
              <form className="booking-form" onSubmit={createBooking}>
                {!canCurrentUserBook && (
                  <div className="booking-role-warning">
                    Bạn đang đăng nhập bằng tài khoản chủ homestay.
                    Hãy đăng xuất và đăng nhập tài khoản khách hàng
                    để tạo đơn.
                  </div>
                )}

                <label className="booking-field booking-field-full">
                  <span>Loại đặt phòng</span>

                  <select
                    name="bookingType"
                    value={bookingForm.bookingType}
                    onChange={handleBookingFieldChange}
                  >
                    <option value="hourly">Thuê theo giờ</option>
                    <option value="daytime">Thuê ban ngày</option>
                    <option value="overnight">Thuê qua đêm</option>
                    <option value="day_night">
                      Thuê ngày và đêm
                    </option>
                  </select>

                  <small>
                    {bookingTypeNotes[bookingForm.bookingType]}
                  </small>
                </label>

                <div className="booking-form-grid">
                  {bookingForm.bookingType === 'hourly' ? (
                <>
                  <label className="booking-field booking-field-full">
                    <span>Ngày thuê</span>
                    <input
                      type="date"
                      min={currentDate}
                      value={bookingForm.checkIn.slice(0, 10)}
                      onChange={(event) => {
                        const selectedDate = event.target.value

                        setBookingForm((currentForm) => ({
                          ...currentForm,
                          checkIn: `${selectedDate}T${
                            currentForm.checkIn.slice(11, 16) || '14:00'
                          }`,
                          checkOut: `${selectedDate}T${
                            currentForm.checkOut.slice(11, 16) || '16:00'
                          }`,
                        }))

                        resetBookingResult()
                      }}
                      required
                    />
                  </label>

                  <label className="booking-field">
                    <span>Giờ nhận phòng</span>
                    <select
                      value={bookingForm.checkIn.slice(11, 16)}
                      onChange={(event) => {
                        const selectedTime = event.target.value
                        const [hour, minute] = selectedTime
                          .split(':')
                          .map(Number)

                        const endTotalMinutes =
                          hour * 60 + minute + 120

                        const nextEndTime =
                          `${padNumber(Math.floor(endTotalMinutes / 60))}:` +
                          `${padNumber(endTotalMinutes % 60)}`

                        setBookingForm((currentForm) => {
                          const selectedDate =
                            currentForm.checkIn.slice(0, 10) ||
                            currentDate

                          return {
                            ...currentForm,
                            checkIn: `${selectedDate}T${selectedTime}`,
                            checkOut: `${selectedDate}T${nextEndTime}`,
                          }
                        })

                        resetBookingResult()
                      }}
                      required
                    >
                      {Array.from({ length: 17 }, (_, index) => {
                        const totalMinutes =
                          HOURLY_OPEN_MINUTE + index * 30
                        const time =
                          `${padNumber(Math.floor(totalMinutes / 60))}:` +
                          `${padNumber(totalMinutes % 60)}`

                        return (
                          <option value={time} key={time}>
                            {time}
                          </option>
                        )
                      })}
                    </select>
                  </label>

                  <label className="booking-field">
                    <span>Giờ trả phòng</span>
                    <select
                      value={bookingForm.checkOut.slice(11, 16)}
                      onChange={(event) => {
                        const selectedTime = event.target.value

                        setBookingForm((currentForm) => {
                          const selectedDate =
                            currentForm.checkIn.slice(0, 10) ||
                            currentDate

                          return {
                            ...currentForm,
                            checkOut: `${selectedDate}T${selectedTime}`,
                          }
                        })

                        resetBookingResult()
                      }}
                      required
                    >
                      {Array.from({ length: 17 }, (_, index) => {
                        const totalMinutes =
                          HOURLY_OPEN_MINUTE + 120 + index * 30
                        const time =
                          `${padNumber(Math.floor(totalMinutes / 60))}:` +
                          `${padNumber(totalMinutes % 60)}`

                        const [startHour, startMinute] =
                          bookingForm.checkIn
                            .slice(11, 16)
                            .split(':')
                            .map(Number)

                        const minimumEndMinutes =
                          startHour * 60 + startMinute + 120

                        return (
                          <option
                            value={time}
                            key={time}
                            disabled={totalMinutes < minimumEndMinutes}
                          >
                            {time}
                          </option>
                        )
                      })}
                    </select>
                  </label>
                </>
                  ) : (
                    <label className="booking-field">
                      <span>Ngày bắt đầu</span>
                      <input
                        type="date"
                        name="bookingDate"
                        min={currentDate}
                        value={bookingForm.bookingDate}
                        onChange={handleBookingFieldChange}
                        required
                      />
                    </label>
                  )}

                  <label className="booking-field">
                    <span>Số khách</span>
                    <select
                      name="guestCount"
                      value={bookingForm.guestCount}
                      onChange={handleBookingFieldChange}
                    >
                      {Array.from(
                        { length: homestay.maxGuests },
                        (_, index) => index + 1,
                      ).map((guestNumber) => (
                        <option
                          value={guestNumber}
                          key={guestNumber}
                        >
                          {guestNumber} khách
                        </option>
                      ))}
                    </select>
                  </label>
                </div>

                {bookingTimes?.checkIn &&
                  bookingTimes?.checkOut && (
                    <div className="booking-time-summary">
                      <div>
                        <span>Nhận phòng</span>
                        <strong>
                          {formatDateTime(bookingTimes.checkIn)}
                        </strong>
                      </div>

                      <div>
                        <span>Trả phòng</span>
                        <strong>
                          {formatDateTime(bookingTimes.checkOut)}
                        </strong>
                      </div>
                    </div>
                  )}

                {estimatedTotal !== null &&
                  Number.isFinite(estimatedTotal) && (
                    <div className="booking-estimated-total">
                      <span>Tổng tiền dự kiến</span>
                      <strong>{formatPrice(estimatedTotal)}</strong>
                    </div>
                  )}

                {bookingMessage && (
                  <div
                    className={
                      bookingMessageType === 'success'
                        ? 'booking-feedback success'
                        : 'booking-feedback error'
                    }
                  >
                    {bookingMessage}
                  </div>
                )}

                <div className="booking-form-actions">
                  <button
                    className="booking-check-button"
                    type="button"
                    disabled={Boolean(bookingAction)}
                    onClick={checkBookingAvailability}
                  >
                    {bookingAction === 'checking'
                      ? 'Đang kiểm tra...'
                      : 'Kiểm tra lịch trống'}
                  </button>

                  <button
                    className="booking-confirm-button"
                    type="submit"
                    disabled={
                      availability !== true ||
                      Boolean(bookingAction) ||
                      !canCurrentUserBook
                    }
                  >
                    {bookingAction === 'creating'
                      ? 'Đang tạo đơn...'
                      : 'Xác nhận đặt phòng'}
                  </button>
                </div>

                <p className="booking-form-note">
                  Hệ thống chỉ tạo đơn chờ thanh toán, chưa trừ
                  tiền ở bước này.
                </p>
              </form>
            )}
          </section>
        </div>
      )}

      <Footer />
    </>
  )
}

export default HomestayDetailPage
