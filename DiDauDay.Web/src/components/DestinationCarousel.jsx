import { useRef } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'

const destinationRegions = {
  'Hà Nội': 'Thủ đô',
  'Đà Nẵng': 'Miền Trung',
  'Đà Lạt': 'Lâm Đồng',
  'Sa Pa': 'Lào Cai',
  'Hội An': 'Quảng Nam',
  'Nha Trang': 'Khánh Hòa',
}

function DestinationCarousel({ destinations }) {
  const sliderRef = useRef(null)

  function scrollSlider(direction) {
    const slider = sliderRef.current
    const firstCard = slider?.querySelector('.destination-card')

    if (!slider || !firstCard) {
      return
    }

    const cardWidth = firstCard.getBoundingClientRect().width
    const gap = 24

    slider.scrollBy({
      left: direction * (cardWidth + gap),
      behavior: 'smooth',
    })
  }

  return (
    <div className="destination-carousel">
      <button
        className="destination-arrow destination-arrow-left"
        type="button"
        aria-label="Xem địa điểm trước"
        onClick={() => scrollSlider(-1)}
      >
        <ChevronLeft size={25} />
      </button>

      <div className="destination-slider" ref={sliderRef}>
        {destinations.map((destination) => (
          <Link
            className="destination-card"
            key={destination.name}
            to={`/homestays?destination=${encodeURIComponent(
              destination.name,
            )}`}
          >
            <img src={destination.image} alt={destination.name} />

            <span className="destination-overlay" />

            <span className="destination-information">
              <small className="destination-region">
                {destinationRegions[destination.name] || 'Việt Nam'}
              </small>

              <strong>{destination.name}</strong>
              <span>{destination.description}</span>
            </span>
          </Link>
        ))}
      </div>

      <button
        className="destination-arrow destination-arrow-right"
        type="button"
        aria-label="Xem địa điểm tiếp theo"
        onClick={() => scrollSlider(1)}
      >
        <ChevronRight size={25} />
      </button>
    </div>
  )
}

export default DestinationCarousel