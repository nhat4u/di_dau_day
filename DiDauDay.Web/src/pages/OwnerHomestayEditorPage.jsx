import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  AlertCircle,
  ArrowLeft,
  BedDouble,
  Check,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Crown,
  Gift,
  ImagePlus,
  LoaderCircle,
  MapPin,
  Plus,
  Save,
  Sparkles,
  Trash2,
  UploadCloud,
  Users,
  X,
} from 'lucide-react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import Header from '../components/Header'
import Footer from '../components/Footer'
import api, { getApiErrorMessage } from '../services/api'
import '../styles/owner-homestays.css'

const emptyInformation = {
  name: '',
  roomRank: 'standard',
  description: '',
  address: '',
  province: '',
  touristDestination: '',
  maxGuests: '2',
  amenities: [],
}

const emptyPrices = {
  priceFirst2Hours: '',
  priceCombo4Hours: '',
  priceExtraHour: '',
  priceOvernightWeekday: '',
  priceOvernightWeekend: '',
  priceDayNightWeekday: '',
  priceDayNightWeekend: '',
  priceDayWeekday: '',
  priceDayWeekend: '',
}

const priceFields = [
  ['priceFirst2Hours', 'Giá 2 giờ đầu', 'Khung giá tối thiểu 2 giờ'],
  ['priceCombo4Hours', 'Combo 4 giờ', 'Giá trọn gói trong 4 giờ'],
  ['priceExtraHour', 'Phụ thu mỗi giờ', 'Áp dụng từ giờ tiếp theo'],
  ['priceOvernightWeekday', 'Qua đêm ngày thường', '22:00 – 10:00 hôm sau'],
  ['priceOvernightWeekend', 'Qua đêm cuối tuần', '22:00 – 10:00 hôm sau'],
  ['priceDayNightWeekday', 'Ngày và đêm ngày thường', '15:00 – 10:00 hôm sau'],
  ['priceDayNightWeekend', 'Ngày và đêm cuối tuần', '15:00 – 10:00 hôm sau'],
  ['priceDayWeekday', 'Ban ngày ngày thường', '11:00 – 21:00'],
  ['priceDayWeekend', 'Ban ngày cuối tuần', '11:00 – 21:00'],
]

const amenityOptions = [
  'Wi-Fi',
  'Điều hòa',
  'Bếp riêng',
  'Máy giặt',
  'Bãi đỗ xe',
  'Máy chiếu Netflix',
  'Gương toàn thân',
  'Board game',
  'Nhà vệ sinh khép kín',
  'Tự check-in/out',
  'Một giường đôi',
  'Bồn tắm',
  'Ban công',
  'Hồ bơi mini',
]

const amenityOptionKeys = new Set(
  amenityOptions.map((item) => item.toLocaleLowerCase('vi')),
)

function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('authUser') || 'null')
  } catch {
    return null
  }
}

function toPricePayload(prices) {
  return Object.fromEntries(
    Object.entries(prices).map(([key, value]) => [key, Number(value)]),
  )
}

function formatPrice(value) {
  return `${new Intl.NumberFormat('vi-VN').format(Number(value || 0))}đ`
}

function OwnerHomestayEditorPage({ mode = 'draft' }) {
  const isChangeRequest = mode === 'change-request'
  const { id } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const homestayId = id ? Number(id) : null

  const [step, setStep] = useState(Number(location.state?.step || 1))
  const [information, setInformation] = useState(emptyInformation)
  const [prices, setPrices] = useState(emptyPrices)
  const [images, setImages] = useState([])
  const [homestay, setHomestay] = useState(null)
  const [reason, setReason] = useState('')
  const [customAmenity, setCustomAmenity] = useState('')
  const [loading, setLoading] = useState(Boolean(homestayId))
  const [saving, setSaving] = useState(false)
  const [pageError, setPageError] = useState('')
  const [formError, setFormError] = useState('')
  const [notice, setNotice] = useState('')

  const editorTitle = isChangeRequest
    ? 'Yêu cầu chỉnh sửa homestay'
    : homestayId
      ? 'Hoàn thiện homestay'
      : 'Đăng homestay mới'

  const loadHomestay = useCallback(async () => {
    if (!homestayId) return

    try {
      setLoading(true)
      setPageError('')
      const response = await api.get(`/owner/homestays/${homestayId}`)
      const item = response.data.homestay

      if (!isChangeRequest && item.status !== 'draft') {
        setPageError(
          'Homestay này đã đăng. Hãy dùng chức năng “Yêu cầu chỉnh sửa”.',
        )
      }

      if (isChangeRequest && item.status === 'draft') {
        setPageError('Bản nháp có thể sửa trực tiếp, không cần QTV duyệt.')
      }

      setHomestay(item)
      setInformation({
        name: item.name || '',
        roomRank: item.roomRank || 'standard',
        description: item.description || '',
        address: item.address || '',
        province: item.province || '',
        touristDestination: item.touristDestination || '',
        maxGuests: String(item.maxGuests || 2),
        amenities: Array.isArray(item.amenities) ? item.amenities : [],
      })

      if (item.prices) {
        setPrices(
          Object.fromEntries(
            Object.keys(emptyPrices).map((key) => [
              key,
              String(item.prices[key] ?? ''),
            ]),
          ),
        )
      }

      setImages(item.images || [])
    } catch (error) {
      if (error.response?.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      setPageError(
        getApiErrorMessage(error, 'Không thể tải thông tin homestay.'),
      )
    } finally {
      setLoading(false)
    }
  }, [homestayId, isChangeRequest, navigate])

  useEffect(() => {
    const user = getStoredUser()
    if (!localStorage.getItem('accessToken')) {
      navigate('/login', { replace: true })
      return
    }

    if (user?.role?.toLowerCase() !== 'owner') {
      setPageError('Trang này chỉ dành cho tài khoản chủ homestay.')
      setLoading(false)
      return
    }

    loadHomestay()
  }, [loadHomestay, navigate])

  const completedSteps = useMemo(() => {
    const values = []
    if (homestayId || homestay) values.push(1)
    if (Object.values(prices).every((value) => Number(value) > 0)) values.push(2)
    if (images.length >= 3) values.push(3)
    return values
  }, [homestay, homestayId, images.length, prices])

  function updateInformation(event) {
    const { name, value, type, checked } = event.target
    setInformation((current) => ({
      ...current,
      [name]: type === 'checkbox' ? checked : value,
    }))
  }

  function toggleAmenity(amenity) {
    setInformation((current) => {
      const selected = current.amenities.some(
        (item) => item.toLocaleLowerCase('vi') === amenity.toLocaleLowerCase('vi'),
      )

      return {
        ...current,
        amenities: selected
          ? current.amenities.filter(
            (item) => item.toLocaleLowerCase('vi') !== amenity.toLocaleLowerCase('vi'),
          )
          : [...current.amenities, amenity],
      }
    })
  }

  function addCustomAmenity() {
    const value = customAmenity.trim().replace(/\s+/g, ' ')
    if (!value) {
      setFormError('Vui lòng nhập tên tiện ích hoặc ưu đãi muốn thêm.')
      return
    }
    if (value.length > 80) {
      setFormError('Tên tiện ích hoặc ưu đãi không được vượt quá 80 ký tự.')
      return
    }
    if (information.amenities.length >= 30) {
      setFormError('Mỗi homestay chỉ được có tối đa 30 tiện ích và ưu đãi.')
      return
    }

    const preset = amenityOptions.find(
      (item) => item.toLocaleLowerCase('vi') === value.toLocaleLowerCase('vi'),
    )
    const finalValue = preset || value
    const alreadySelected = information.amenities.some(
      (item) => item.toLocaleLowerCase('vi') === finalValue.toLocaleLowerCase('vi'),
    )

    if (alreadySelected) {
      setFormError('Tiện ích hoặc ưu đãi này đã được chọn.')
      return
    }

    setInformation((current) => ({
      ...current,
      amenities: [...current.amenities, finalValue],
    }))
    setCustomAmenity('')
    setFormError('')
  }

  function handleCustomAmenityKeyDown(event) {
    if (event.key !== 'Enter') return
    event.preventDefault()
    addCustomAmenity()
  }

  function updatePrice(event) {
    const { name, value } = event.target
    setPrices((current) => ({ ...current, [name]: value }))
  }

  async function saveInformation(event) {
    event.preventDefault()
    setFormError('')
    setNotice('')

    if (information.description.trim().length < 20) {
      setFormError('Phần giới thiệu phải có ít nhất 20 ký tự.')
      return
    }

    if (isChangeRequest) {
      setStep(2)
      window.scrollTo({ top: 0, behavior: 'smooth' })
      return
    }

    try {
      setSaving(true)
      const payload = {
        ...information,
        maxGuests: Number(information.maxGuests),
      }

      if (homestayId) {
        await api.put(`/owner/homestays/${homestayId}`, payload)
        setNotice('Đã lưu thông tin homestay.')
        setStep(2)
      } else {
        const response = await api.post('/owner/homestays', payload)
        const newId = response.data.homestay.id
        setStep(2)
        navigate(`/owner/homestays/${newId}/setup`, {
          replace: true,
          state: { step: 2 },
        })
      }
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (error) {
      setFormError(
        getApiErrorMessage(error, 'Không thể lưu thông tin homestay.'),
      )
    } finally {
      setSaving(false)
    }
  }

  async function savePrices(event) {
    event.preventDefault()
    setFormError('')
    setNotice('')

    const payload = toPricePayload(prices)
    if (Object.values(payload).some((value) => !Number.isFinite(value) || value < 1000)) {
      setFormError('Vui lòng nhập đầy đủ các mức giá, mỗi giá từ 1.000đ trở lên.')
      return
    }

    if (payload.priceCombo4Hours < payload.priceFirst2Hours) {
      setFormError('Giá combo 4 giờ không được thấp hơn giá 2 giờ đầu.')
      return
    }

    if (isChangeRequest) {
      setStep(3)
      window.scrollTo({ top: 0, behavior: 'smooth' })
      return
    }

    try {
      setSaving(true)
      await api.put(`/owner/homestays/${homestayId}/prices`, payload)
      setNotice('Đã lưu đầy đủ bảng giá.')
      setStep(3)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (error) {
      setFormError(getApiErrorMessage(error, 'Không thể lưu bảng giá.'))
    } finally {
      setSaving(false)
    }
  }

  async function uploadImages(event) {
    const files = Array.from(event.target.files || [])
    event.target.value = ''
    if (!files.length) return

    if (images.length + files.length > 10) {
      setFormError(`Bạn chỉ có thể tải thêm ${10 - images.length} ảnh.`)
      return
    }

    const invalidFile = files.find(
      (file) =>
        file.size > 5 * 1024 * 1024 ||
        !['image/jpeg', 'image/png', 'image/webp'].includes(file.type),
    )
    if (invalidFile) {
      setFormError('Ảnh phải là JPG, PNG hoặc WEBP và không vượt quá 5MB.')
      return
    }

    const formData = new FormData()
    files.forEach((file) => formData.append('files', file))

    try {
      setSaving(true)
      setFormError('')
      await api.post(`/owner/homestays/${homestayId}/images`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      await loadHomestay()
      setNotice(`Đã tải lên ${files.length} ảnh.`)
    } catch (error) {
      setFormError(getApiErrorMessage(error, 'Không thể tải ảnh lên.'))
    } finally {
      setSaving(false)
    }
  }

  async function setCover(imageId) {
    try {
      setSaving(true)
      setFormError('')
      await api.put(`/owner/homestays/${homestayId}/images/${imageId}/cover`)
      setImages((items) =>
        items.map((image) => ({ ...image, isCover: image.id === imageId })),
      )
    } catch (error) {
      setFormError(getApiErrorMessage(error, 'Không thể đổi ảnh bìa.'))
    } finally {
      setSaving(false)
    }
  }

  async function deleteImage(imageId) {
    try {
      setSaving(true)
      setFormError('')
      await api.delete(`/owner/homestays/${homestayId}/images/${imageId}`)
      await loadHomestay()
    } catch (error) {
      setFormError(getApiErrorMessage(error, 'Không thể xóa ảnh.'))
    } finally {
      setSaving(false)
    }
  }

  function continueFromImages() {
    if (!isChangeRequest && images.length < 3) {
      setFormError('Vui lòng tải tối thiểu 3 ảnh trước khi tiếp tục.')
      return
    }
    setFormError('')
    setStep(4)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  async function finishProcess() {
    setFormError('')
    setNotice('')

    try {
      setSaving(true)
      if (isChangeRequest) {
        if (reason.trim().length < 5) {
          setFormError('Vui lòng nhập lý do chỉnh sửa có ít nhất 5 ký tự.')
          return
        }

        const response = await api.post('/owner/homestay-change-requests', {
          homestayId,
          requestType: 'update',
          reason: reason.trim(),
          proposedHomestay: {
            ...information,
            maxGuests: Number(information.maxGuests),
          },
          proposedPrices: toPricePayload(prices),
        })

        navigate('/owner/homestays', {
          replace: true,
          state: { message: response.data.message },
        })
      } else {
        const response = await api.post(`/owner/homestays/${homestayId}/publish`)
        navigate('/owner/homestays', {
          replace: true,
          state: { message: response.data.message },
        })
      }
    } catch (error) {
      setFormError(
        getApiErrorMessage(
          error,
          isChangeRequest
            ? 'Không thể gửi yêu cầu chỉnh sửa.'
            : 'Không thể đăng homestay.',
        ),
      )
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <>
        <Header />
        <main className="owner-homestays-page">
          <div className="owner-page-state">
            <LoaderCircle className="spin" size={34} />
            <p>Đang tải dữ liệu homestay...</p>
          </div>
        </main>
        <Footer />
      </>
    )
  }

  if (pageError) {
    return (
      <>
        <Header />
        <main className="owner-homestays-page">
          <div className="owner-page-state error">
            <AlertCircle size={40} />
            <p>{pageError}</p>
            <Link className="owner-primary-button" to="/owner/homestays">
              Về danh sách homestay
            </Link>
          </div>
        </main>
        <Footer />
      </>
    )
  }

  return (
    <>
      <Header />
      <main className="owner-homestays-page owner-editor-page">
        <div className="owner-editor-container">
          <Link className="owner-page-back" to="/owner/homestays">
            <ArrowLeft size={17} /> Homestay của tôi
          </Link>

          <header className="owner-editor-heading">
            <span><Sparkles size={18} /> KHU VỰC CHỦ HOMESTAY</span>
            <h1>{editorTitle}</h1>
            <p>
              {isChangeRequest
                ? 'Thông tin hiện tại vẫn được giữ nguyên cho đến khi QTV duyệt.'
                : 'Hoàn thành lần lượt các bước để home được hiển thị cho khách.'}
            </p>
          </header>

          <nav className="owner-editor-steps" aria-label="Các bước đăng homestay">
            {[
              [1, 'Thông tin'],
              [2, 'Bảng giá'],
              [3, 'Hình ảnh'],
              [4, isChangeRequest ? 'Gửi QTV' : 'Kiểm tra & đăng'],
            ].map(([number, label]) => (
              <button
                key={number}
                type="button"
                className={step === number ? 'active' : ''}
                disabled={!isChangeRequest && number > 1 && !homestayId}
                onClick={() => setStep(number)}
              >
                <i>
                  {completedSteps.includes(number) ? <Check size={15} /> : number}
                </i>
                <span>{label}</span>
              </button>
            ))}
          </nav>

          {notice && (
            <div className="owner-notice success">
              <CheckCircle2 size={19} /> {notice}
            </div>
          )}

          {formError && (
            <div className="owner-notice error">
              <AlertCircle size={19} /> {formError}
            </div>
          )}

          <section className="owner-editor-card">
            {step === 1 && (
              <form className="owner-editor-form" onSubmit={saveInformation}>
                <div className="owner-form-section-heading">
                  <span><BedDouble size={21} /></span>
                  <div>
                    <h2>Thông tin homestay</h2>
                    <p>Mô tả chính xác nơi ở mà khách sẽ đặt.</p>
                  </div>
                </div>

                <div className="owner-form-grid">
                  <label className="wide">
                    <span>Tên homestay *</span>
                    <input
                      required
                      minLength={2}
                      maxLength={150}
                      name="name"
                      value={information.name}
                      placeholder="Ví dụ: Nana Homestay Tây Hồ"
                      onChange={updateInformation}
                    />
                  </label>

                  <label>
                    <span>Hạng phòng *</span>
                    <select
                      name="roomRank"
                      value={information.roomRank}
                      onChange={updateInformation}
                    >
                      <option value="standard">Tiêu chuẩn</option>
                      <option value="deluxe">Deluxe</option>
                      <option value="premium">Premium</option>
                    </select>
                  </label>

                  <label>
                    <span>Số khách tối đa *</span>
                    <select
                      name="maxGuests"
                      value={information.maxGuests}
                      onChange={updateInformation}
                    >
                      {[1, 2, 3, 4].map((number) => (
                        <option key={number} value={number}>{number} khách</option>
                      ))}
                    </select>
                  </label>

                  <label className="wide">
                    <span>Giới thiệu homestay *</span>
                    <textarea
                      required
                      minLength={20}
                      maxLength={5000}
                      rows={6}
                      name="description"
                      value={information.description}
                      placeholder="Không gian, phong cách, vị trí và trải nghiệm nổi bật..."
                      onChange={updateInformation}
                    />
                    <small>{information.description.length}/5000 ký tự</small>
                  </label>

                  <label className="wide">
                    <span>Địa chỉ đầy đủ *</span>
                    <div className="owner-input-icon">
                      <MapPin size={18} />
                      <input
                        required
                        minLength={5}
                        maxLength={255}
                        name="address"
                        value={information.address}
                        placeholder="Số nhà, đường, phường/xã, quận/huyện"
                        onChange={updateInformation}
                      />
                    </div>
                  </label>

                  <label>
                    <span>Tỉnh/thành phố *</span>
                    <input
                      required
                      name="province"
                      value={information.province}
                      placeholder="Ví dụ: Hà Nội"
                      onChange={updateInformation}
                    />
                  </label>

                  <label>
                    <span>Điểm du lịch gần nhất *</span>
                    <input
                      required
                      name="touristDestination"
                      value={information.touristDestination}
                      placeholder="Ví dụ: Hồ Tây"
                      onChange={updateInformation}
                    />
                  </label>
                </div>

                <div className="owner-amenities-section">
                  <div className="owner-amenities-heading">
                    <div>
                      <h3>Tiện ích của homestay</h3>
                      <p>Chọn đúng những tiện ích home đang có. Khi mở lại để sửa, các mục đã chọn sẽ tự được tích.</p>
                    </div>
                    <span>{information.amenities.length}/30 mục</span>
                  </div>

                  <div className="owner-checkbox-grid">
                    {amenityOptions.map((item) => {
                      const checked = information.amenities.some(
                        (amenity) => amenity.toLocaleLowerCase('vi') === item.toLocaleLowerCase('vi'),
                      )
                      return (
                        <label className={checked ? 'selected' : ''} key={item}>
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={() => toggleAmenity(item)}
                          />
                          <Check size={18} /> {item}
                        </label>
                      )
                    })}
                  </div>

                  <div className="owner-custom-amenities">
                    <div className="owner-custom-amenities-title">
                      <Gift size={19} />
                      <div>
                        <strong>Tiện ích hoặc ưu đãi khác</strong>
                        <small>Ví dụ: Miễn phí vé tham quan, tặng đồ uống, đưa đón miễn phí...</small>
                      </div>
                    </div>

                    <div className="owner-custom-amenity-input">
                      <input
                        type="text"
                        maxLength={80}
                        value={customAmenity}
                        placeholder="Nhập một tiện ích hoặc ưu đãi"
                        onChange={(event) => setCustomAmenity(event.target.value)}
                        onKeyDown={handleCustomAmenityKeyDown}
                      />
                      <button type="button" onClick={addCustomAmenity}>
                        <Plus size={18} /> Thêm
                      </button>
                    </div>

                    {information.amenities.some(
                      (item) => !amenityOptionKeys.has(item.toLocaleLowerCase('vi')),
                    ) && (
                      <div className="owner-custom-amenity-tags">
                        {information.amenities
                          .filter((item) => !amenityOptionKeys.has(item.toLocaleLowerCase('vi')))
                          .map((item) => (
                            <span key={item}>
                              <Check size={15} /> {item}
                              <button
                                type="button"
                                aria-label={`Xóa ${item}`}
                                onClick={() => toggleAmenity(item)}
                              >
                                <X size={14} />
                              </button>
                            </span>
                          ))}
                      </div>
                    )}
                  </div>
                </div>

                <div className="owner-editor-actions end">
                  <button className="primary" type="submit" disabled={saving}>
                    {saving
                      ? <LoaderCircle className="spin" size={18} />
                      : <Save size={18} />}
                    {isChangeRequest ? 'Tiếp tục bảng giá' : 'Lưu và tiếp tục'}
                    <ChevronRight size={18} />
                  </button>
                </div>
              </form>
            )}

            {step === 2 && (
              <form className="owner-editor-form" onSubmit={savePrices}>
                <div className="owner-form-section-heading">
                  <span><Crown size={21} /></span>
                  <div>
                    <h2>Bảng giá đầy đủ</h2>
                    <p>Nhập giá bằng VNĐ, không cần dấu chấm hoặc ký hiệu tiền tệ.</p>
                  </div>
                </div>

                <div className="owner-price-grid">
                  {priceFields.map(([key, label, hint]) => (
                    <label key={key}>
                      <span>{label} *</span>
                      <div className="owner-price-input">
                        <input
                          required
                          min="1000"
                          step="1000"
                          type="number"
                          name={key}
                          value={prices[key]}
                          placeholder="150000"
                          onChange={updatePrice}
                        />
                        <b>đ</b>
                      </div>
                      <small>{hint}</small>
                    </label>
                  ))}
                </div>

                <div className="owner-editor-actions">
                  <button type="button" onClick={() => setStep(1)}>
                    <ChevronLeft size={18} /> Quay lại
                  </button>
                  <button className="primary" type="submit" disabled={saving}>
                    {saving
                      ? <LoaderCircle className="spin" size={18} />
                      : <Save size={18} />}
                    Lưu bảng giá <ChevronRight size={18} />
                  </button>
                </div>
              </form>
            )}

            {step === 3 && (
              <div className="owner-editor-form">
                <div className="owner-form-section-heading">
                  <span><ImagePlus size={21} /></span>
                  <div>
                    <h2>Hình ảnh homestay</h2>
                    <p>
                      {isChangeRequest
                        ? 'Các ảnh hiện tại được giữ nguyên trong yêu cầu chỉnh sửa này.'
                        : 'Tải tối thiểu 3 và tối đa 10 ảnh. Ảnh đầu tiên tự động làm ảnh bìa.'}
                    </p>
                  </div>
                </div>

                {!isChangeRequest && (
                  <label className={`owner-image-uploader ${saving ? 'disabled' : ''}`}>
                    <UploadCloud size={37} />
                    <strong>Chọn ảnh từ máy tính</strong>
                    <span>JPG, PNG, WEBP · tối đa 5MB/ảnh · còn {10 - images.length} ảnh</span>
                    <input
                      type="file"
                      multiple
                      accept="image/jpeg,image/png,image/webp"
                      disabled={saving || images.length >= 10}
                      onChange={uploadImages}
                    />
                  </label>
                )}

                <div className="owner-image-summary">
                  <strong>{images.length}/10 ảnh</strong>
                  <span className={images.length >= 3 ? 'valid' : ''}>
                    {images.length >= 3
                      ? 'Đã đủ số ảnh để đăng'
                      : `Cần thêm ${3 - images.length} ảnh`}
                  </span>
                </div>

                <div className="owner-image-grid">
                  {images.map((image, index) => (
                    <article key={image.id} className={image.isCover ? 'is-cover' : ''}>
                      <img src={image.imageUrl} alt={`Ảnh homestay ${index + 1}`} />
                      <span className="owner-image-order">#{index + 1}</span>
                      {image.isCover && <em><Crown size={14} /> Ảnh bìa</em>}
                      {!isChangeRequest && (
                        <div>
                          {!image.isCover && (
                            <button type="button" disabled={saving} onClick={() => setCover(image.id)}>
                              Đặt làm bìa
                            </button>
                          )}
                          <button
                            className="danger"
                            type="button"
                            disabled={saving}
                            onClick={() => deleteImage(image.id)}
                          >
                            <Trash2 size={16} />
                          </button>
                        </div>
                      )}
                    </article>
                  ))}
                </div>

                {isChangeRequest && (
                  <div className="owner-information-box">
                    <AlertCircle size={20} />
                    <p>
                      Để tránh thay ảnh đang hiển thị trước khi QTV duyệt, yêu cầu
                      này chỉ thay đổi thông tin và bảng giá. Khi cần đổi bộ ảnh,
                      hãy ghi rõ trong lý do để QTV hỗ trợ.
                    </p>
                  </div>
                )}

                <div className="owner-editor-actions">
                  <button type="button" onClick={() => setStep(2)}>
                    <ChevronLeft size={18} /> Quay lại
                  </button>
                  <button className="primary" type="button" onClick={continueFromImages}>
                    Tiếp tục <ChevronRight size={18} />
                  </button>
                </div>
              </div>
            )}

            {step === 4 && (
              <div className="owner-editor-form">
                <div className="owner-form-section-heading">
                  <span><CheckCircle2 size={21} /></span>
                  <div>
                    <h2>{isChangeRequest ? 'Gửi yêu cầu cho QTV' : 'Kiểm tra lần cuối'}</h2>
                    <p>
                      {isChangeRequest
                        ? 'QTV sẽ so sánh thông tin hiện tại và dữ liệu mới trước khi duyệt.'
                        : 'Sau khi đăng, mọi thay đổi sẽ cần QTV phê duyệt.'}
                    </p>
                  </div>
                </div>

                <div className="owner-review-grid">
                  <article>
                    <small>Tên homestay</small>
                    <strong>{information.name}</strong>
                    <span>{information.address}, {information.province}</span>
                  </article>
                  <article>
                    <small>Hạng và sức chứa</small>
                    <strong>{information.roomRank.toUpperCase()}</strong>
                    <span><Users size={15} /> Tối đa {information.maxGuests} khách</span>
                  </article>
                  <article>
                    <small>Khoảng giá</small>
                    <strong>
                      {formatPrice(prices.priceFirst2Hours)} –{' '}
                      {formatPrice(Math.max(...Object.values(prices).map(Number)))}
                    </strong>
                    <span>Đã nhập đủ 9 mức giá</span>
                  </article>
                  <article>
                    <small>Hình ảnh</small>
                    <strong>{images.length} ảnh</strong>
                    <span>{images.find((image) => image.isCover) ? 'Đã có ảnh bìa' : 'Chưa có ảnh bìa'}</span>
                  </article>
                </div>

                {isChangeRequest && (
                  <label className="owner-change-reason">
                    <span>Lý do chỉnh sửa gửi QTV *</span>
                    <textarea
                      required
                      minLength={5}
                      maxLength={1000}
                      rows={5}
                      value={reason}
                      placeholder="Ví dụ: Home vừa bổ sung ban công và cập nhật bảng giá mới..."
                      onChange={(event) => setReason(event.target.value)}
                    />
                    <small>{reason.length}/1000 ký tự</small>
                  </label>
                )}

                <div className="owner-final-note">
                  <CheckCircle2 size={22} />
                  <div>
                    <strong>
                      {isChangeRequest
                        ? 'Dữ liệu hiện tại chưa bị thay đổi'
                        : 'Home sẽ được hiển thị ngay sau khi đăng'}
                    </strong>
                    <p>
                      {isChangeRequest
                        ? 'Hệ thống chỉ áp dụng dữ liệu mới khi QTV đồng ý.'
                        : 'Bạn vẫn có thể gửi yêu cầu sửa, tạm ngừng hoặc đóng home sau này.'}
                    </p>
                  </div>
                </div>

                <div className="owner-editor-actions">
                  <button type="button" onClick={() => setStep(3)}>
                    <ChevronLeft size={18} /> Quay lại
                  </button>
                  <button className="primary final" type="button" disabled={saving} onClick={finishProcess}>
                    {saving
                      ? <LoaderCircle className="spin" size={19} />
                      : <CheckCircle2 size={19} />}
                    {isChangeRequest ? 'Gửi QTV duyệt' : 'Đăng homestay'}
                  </button>
                </div>
              </div>
            )}
          </section>
        </div>
      </main>
      <Footer />
    </>
  )
}

export default OwnerHomestayEditorPage
