import { Heart, Mail, MapPin, MapPinHouse, Phone } from 'lucide-react'
import { Link } from 'react-router-dom'
import '../styles/footer.css'

function Footer() {
  return (
    <footer className="site-footer">
      <div className="container footer-grid">
        <div className="footer-brand-column">
          <Link className="footer-brand" to="/">
            <span className="footer-brand-icon">
              <MapPinHouse size={25} strokeWidth={2.3} />
            </span>

            <span>
              <strong>Đi Đâu Đây</strong>
              <small>Homestay Việt Nam</small>
            </span>
          </Link>

          <p>
            Một chốn nhỏ cho chuyến đi lớn. Tìm và đặt homestay tại
            những điểm đến tuyệt đẹp trên khắp Việt Nam.
          </p>
        </div>

        <div className="footer-column">
          <h3>Khám phá</h3>
          <a href="/#destinations">Điểm đến nổi bật</a>
          <Link to="/homestays">Danh sách homestay</Link>
          <a href="/#how-it-works">Cách hoạt động</a>
        </div>

        <div className="footer-column">
          <h3>Hỗ trợ</h3>
          <Link to="/help">Trung tâm trợ giúp</Link>
          <Link to="/terms">Điều khoản sử dụng</Link>
          <Link to="/privacy">Chính sách bảo mật</Link>
        </div>

        <div className="footer-column footer-contact">
          <h3>Liên hệ</h3>

          <a href="mailto:hotro@didauday.vn">
            <Mail size={17} />
            hotro@didauday.vn
          </a>

          <a href="tel:0901234567">
            <Phone size={17} />
            0901 234 567
          </a>

          <span>
            <MapPin size={17} />
            Việt Nam
          </span>
        </div>
      </div>

      <div className="container footer-bottom">
        <span>© 2026 Đi Đâu Đây. Tất cả quyền được bảo lưu.</span>

        <span className="made-with-love">
          Được tạo với <Heart size={15} fill="currentColor" /> tại Việt Nam
        </span>
      </div>
    </footer>
  )
}

export default Footer
