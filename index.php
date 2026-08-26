<?php

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/session.php";

/* Nhận dữ liệu tìm kiếm */

$destination = trim($_GET["destination"] ?? "");
$checkIn = $_GET["check_in"] ?? "";
$checkOut = $_GET["check_out"] ?? "";
$guestCount = (int) ($_GET["guest_count"] ?? 2);

if ($guestCount < 1 || $guestCount > 4) {
    $guestCount = 2;
}

/* Lấy homestay, ảnh bìa và khoảng giá */

$sql = "
    SELECT
        h.*,

        (
            SELECT hi.image_path
            FROM homestay_images AS hi
            WHERE hi.homestay_id = h.id
            ORDER BY
                hi.is_cover DESC,
                hi.sort_order ASC
            LIMIT 1
        ) AS cover_image,

        COALESCE(
            LEAST(
                hp.price_first_2_hours,
                hp.price_combo_4_hours,
                hp.price_overnight_weekday,
                hp.price_overnight_weekend,
                hp.price_day_night_weekday,
                hp.price_day_night_weekend,
                hp.price_day_weekday,
                hp.price_day_weekend
            ),
            h.price_per_hour
        ) AS min_price,

        COALESCE(
            GREATEST(
                hp.price_first_2_hours,
                hp.price_combo_4_hours,
                hp.price_overnight_weekday,
                hp.price_overnight_weekend,
                hp.price_day_night_weekday,
                hp.price_day_night_weekend,
                hp.price_day_weekday,
                hp.price_day_weekend
            ),
            h.overnight_price
        ) AS max_price

    FROM homestays AS h

    LEFT JOIN homestay_prices AS hp
        ON hp.homestay_id = h.id

    WHERE h.status = 'approved'
";

$parameters = [];

/* Lọc theo địa điểm */

if ($destination !== "") {
    $sql .= "
        AND (
            h.name LIKE :destination_name
            OR h.province LIKE :destination_province
            OR h.tourist_destination LIKE :destination_tourist
        )
    ";

    $destinationKeyword = "%" . $destination . "%";

    $parameters["destination_name"] =
        $destinationKeyword;

    $parameters["destination_province"] =
        $destinationKeyword;

    $parameters["destination_tourist"] =
        $destinationKeyword;
}

/* Lọc theo số khách */

$sql .= "
    AND h.max_guests >= :guest_count
";

$parameters["guest_count"] = $guestCount;

/* Sắp xếp homestay mới nhất */

$sql .= "
    ORDER BY h.created_at DESC
    LIMIT 6
";

$homestayStatement = $pdo->prepare($sql);
$homestayStatement->execute($parameters);

$homestays = $homestayStatement->fetchAll(
    PDO::FETCH_ASSOC
);

/* Kiểm tra đăng nhập */

$isLoggedIn = isset($_SESSION["user_id"]);
$userRole = $_SESSION["role"] ?? "";

$dashboardUrl = "";

if ($userRole === "admin") {
    $dashboardUrl = "admin/index.php";
} elseif ($userRole === "owner") {
    $dashboardUrl = "owner/index.php";
}

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Đi Đâu Đây - Đặt homestay khắp Việt Nam</title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <style>
        .homestay-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
        }

        .homestay-card {
            overflow: hidden;
            border: 1px solid #dce5e1;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 16px 40px rgba(15, 62, 49, 0.08);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .homestay-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 22px 50px rgba(15, 62, 49, 0.14);
        }

        .homestay-cover {
            position: relative;
            height: 220px;
            display: block;
            overflow: hidden;
            background: #dce9e4;
        }

        .homestay-cover img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
            transition: transform 0.35s ease;
        }

        .homestay-card:hover .homestay-cover img {
            transform: scale(1.04);
        }

        .homestay-no-image {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #47665c;
            font-weight: 700;
        }

        .homestay-rank {
            position: absolute;
            top: 14px;
            left: 14px;
            padding: 7px 11px;
            border-radius: 999px;
            color: #ffffff;
            background: rgba(19, 76, 60, 0.92);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .homestay-card-content {
            padding: 20px;
        }

        .homestay-location {
            margin: 0 0 7px;
            color: #e9785f;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .homestay-card h3 {
            margin: 0 0 9px;
            color: #123c31;
            font-size: 21px;
        }

        .homestay-information {
            margin: 0;
            color: #687770;
            font-size: 14px;
        }

        .homestay-card-footer {
            margin-top: 20px;
            padding-top: 17px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            border-top: 1px solid #e4ebe8;
        }

        .homestay-price small {
            display: block;
            margin-bottom: 3px;
            color: #7b8983;
            font-size: 11px;
        }

        .homestay-price strong {
            color: #174f40;
            font-size: 16px;
        }

        .homestay-detail-button {
            padding: 11px 15px;
            border-radius: 10px;
            color: #ffffff;
            background: #1d654f;
            font-size: 13px;
            font-weight: 750;
            text-decoration: none;
            white-space: nowrap;
        }

        .homestay-detail-button:hover {
            background: #154b3b;
        }

        @media (max-width: 980px) {
            .homestay-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .homestay-grid {
                grid-template-columns: 1fr;
            }

            .homestay-cover {
                height: 210px;
            }
        }
    </style>
</head>

<body>

<header class="site-header">
    <div class="container navbar">

        <a href="index.php" class="logo">
            Đi Đâu Đây
        </a>

        <nav class="nav-links">
            <a href="#destinations">Điểm đến</a>
            <a href="#homestays">Homestay</a>
            <a href="#process">Cách hoạt động</a>

            <?php if (!$isLoggedIn): ?>
                <a href="auth/register.php">
                    Trở thành chủ nhà
                </a>
            <?php endif; ?>
        </nav>

        <div class="nav-actions">

            <?php if ($isLoggedIn): ?>

                <span class="user-name">
                    Xin chào,
                    <?php
                    echo htmlspecialchars($_SESSION["full_name"]);
                    ?>
                </span>

                <?php if ($dashboardUrl !== ""): ?>
                    <a
                        href="<?php echo $dashboardUrl; ?>"
                        class="button button-outline"
                    >
                        Trang quản lý
                    </a>
                <?php endif; ?>

                <?php if ($isLoggedIn && $userRole === "guest"): ?>

                <a
                    href="guest/bookings.php"
                    class="guest-bookings-button"
                >
                    Đơn của tôi
                </a>

            <?php endif; ?>

                <a
                    href="auth/logout.php"
                    class="button button-primary"
                >
                    Đăng xuất
                </a>

            <?php else: ?>

                <a
                    href="auth/login.php"
                    class="button button-outline"
                >
                    Đăng nhập
                </a>

                <a
                    href="auth/register.php"
                    class="button button-primary"
                >
                    Đăng ký
                </a>

            <?php endif; ?>

        </div>
    </div>
</header>

<main>

    <section class="hero">
        <div class="container hero-content">

            <p class="eyebrow">
                HOMESTAY CHO NHỮNG CHUYẾN ĐI NHỎ
            </p>

            <h1>
                Hôm nay mình<br>
                đi đâu đây?
            </h1>

            <p class="hero-description">
            Khám phá xem nào, biết đâu chuyến đi tiếp theo bắt đầu từ đây.
            </p>

            <form
                method="GET"
                action="index.php#homestays"
                class="search-box"
            >

                <div class="search-field">
                    <label for="destination">
                        ĐỊA ĐIỂM
                    </label>

                    <input
                        type="text"
                        id="destination"
                        name="destination"
                        value="<?php echo htmlspecialchars($destination); ?>"
                        placeholder="Bạn muốn đi đâu?"
                    >
                </div>

                <div class="search-field">
                    <label for="check_in">
                        NHẬN PHÒNG
                    </label>

                    <input
                        type="date"
                        id="check_in"
                        name="check_in"
                        value="<?php echo htmlspecialchars($checkIn); ?>"
                    >
                </div>

                <div class="search-field">
                    <label for="check_out">
                        TRẢ PHÒNG
                    </label>

                    <input
                        type="date"
                        id="check_out"
                        name="check_out"
                        value="<?php echo htmlspecialchars($checkOut); ?>"
                    >
                </div>

                <div class="search-field">
                    <label for="guest_count">
                        SỐ KHÁCH
                    </label>

                    <select
                        id="guest_count"
                        name="guest_count"
                    >
                        <?php for ($number = 1; $number <= 4; $number++): ?>
                            <option
                                value="<?php echo $number; ?>"
                                <?php
                                echo $guestCount === $number
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo $number; ?> khách
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <button type="submit" class="search-button">
                    Tìm homestay
                </button>

            </form>
        </div>
    </section>

<section
    class="section"
    id="destinations"
>
    <div class="container">

        <div class="section-heading">
            <div>
                <p>ĐIỂM ĐẾN NỔI BẬT</p>

                <h2>
                    Bạn muốn thức dậy ở đâu?
                </h2>
            </div>

            <a href="index.php#homestays">
                Khám phá homestay →
            </a>
        </div>

        <div class="destination-slider">

            <button
                type="button"
                class="destination-arrow destination-arrow-left"
                onclick="scrollDestinations(-1)"
                aria-label="Xem địa điểm trước"
            >
                ‹
            </button>

            <div
                class="destination-grid"
                id="destinationSlider"
            >

                <!-- ĐÀ LẠT -->

                <a
                    href="index.php?destination=Đà Lạt#homestays"
                    class="destination-card"
                    style="
                        background-image:
                            url('assets/images/da-lat.jpg') !important;
                        background-position:
                            left center !important;
                    "
                >
                    <span>LÂM ĐỒNG</span>
                    <h3>Đà Lạt</h3>

                    <p>
                        Thông reo và những buổi sáng đầy sương.
                    </p>
                </a>

                <!-- SA PA -->

                <a
                    href="index.php?destination=Sa Pa#homestays"
                    class="destination-card"
                    style="
                        background-image:
                            url('assets/images/sa-pa.jpg') !important;
                        background-position:
                            center !important;
                    "
                >
                    <span>LÀO CAI</span>
                    <h3>Sa Pa</h3>

                    <p>
                        Săn mây giữa núi rừng Tây Bắc.
                    </p>
                </a>

                <!-- ĐÀ NẴNG -->

                <a
                    href="index.php?destination=Đà Nẵng#homestays"
                    class="destination-card"
                    style="
                        background-image:
                            url('assets/images/da-nang.jpg') !important;
                        background-position:
                            center !important;
                    "
                >
                    <span>MIỀN TRUNG</span>
                    <h3>Đà Nẵng</h3>

                    <p>
                        Biển xanh, nắng ấm và những đêm yên bình.
                    </p>
                </a>

                <!-- HÀ NỘI -->

                <a
                    href="index.php?destination=Hà Nội#homestays"
                    class="destination-card"
                    style="
                        background-image:
                            url('assets/images/ha-noi.jpg') !important;
                        background-position:
                            center !important;
                    "
                >
                    <span>THỦ ĐÔ</span>
                    <h3>Hà Nội</h3>

                    <p>
                        Phố cổ bình yên và những góc nhỏ đầy thương nhớ.
                    </p>
                </a>

                <!-- TÀ XÙA -->

                <a
                    href="index.php?destination=Tà Xùa#homestays"
                    class="destination-card"
                    style="
                        background-image:
                            url('assets/images/ta-xua.jpg') !important;
                        background-position:
                            center !important;
                    "
                >
                    <span>SƠN LA</span>
                    <h3>Tà Xùa</h3>

                    <p>
                        Săn mây trên những cung đường Tây Bắc.
                    </p>
                </a>

                <!-- NHA TRANG -->

                <a
                    href="index.php?destination=Nha Trang#homestays"
                    class="destination-card"
                    style="
                        background-image:
                            url('assets/images/nha-trang.jpg') !important;
                        background-position:
                            center !important;
                    "
                >
                    <span>KHÁNH HÒA</span>
                    <h3>Nha Trang</h3>

                    <p>
                        Biển xanh, cát trắng và nắng vàng.
                    </p>
                </a>

                <!-- TAM ĐẢO -->

                <a
                    href="index.php?destination=Tam Đảo#homestays"
                    class="destination-card"
                    style="
                        background-image:
                            url('assets/images/tam-dao.jpg') !important;
                        background-position:
                            center !important;
                    "
                >
                    <span>VĨNH PHÚC</span>
                    <h3>Tam Đảo</h3>

                    <p>
                        Thị trấn mờ sương giữa núi rừng.
                    </p>
                </a>

                <!-- HỘI AN -->

                <a
                    href="index.php?destination=Hội An#homestays"
                    class="destination-card"
                    style="
                        background-image:
                            url('assets/images/hoi-an.jpg') !important;
                        background-position:
                            center !important;
                    "
                >
                    <span>QUẢNG NAM</span>
                    <h3>Hội An</h3>

                    <p>
                        Phố đèn lồng và những mái nhà cổ kính.
                    </p>
                </a>

                <!-- HẠ LONG -->

                <a
                    href="index.php?destination=Hạ Long#homestays"
                    class="destination-card"
                    style="
                        background-image:
                            url('assets/images/ha-long.jpg') !important;
                        background-position:
                            center !important;
                    "
                >
                    <span>QUẢNG NINH</span>
                    <h3>Hạ Long</h3>

                    <p>
                        Nghỉ ngơi bên một kỳ quan thiên nhiên.
                    </p>
                </a>

            </div>

            <button
                type="button"
                class="destination-arrow destination-arrow-right"
                onclick="scrollDestinations(1)"
                aria-label="Xem địa điểm tiếp theo"
            >
                ›
            </button>

        </div>

    </div>
</section>

    <section
        class="section section-light"
        id="homestays"
    >
        <div class="container">

            <div class="section-heading">
                <div>
                    <p>CHỖ Ở ĐƯỢC GỢI Ý</p>

                    <h2>
                        Những homestay dành cho bạn
                    </h2>
                </div>
            </div>

            <?php if (empty($homestays)): ?>

                <div class="empty-homestays">
                    <strong>
                        Chưa có homestay phù hợp
                    </strong>

                    Hãy thử tìm địa điểm hoặc số khách khác.
                </div>

            <?php else: ?>

                <div class="homestay-grid">
                    <?php foreach ($homestays as $homestay): ?>

                        <?php
                        $rankNames = [
                            "standard" => "Standard",
                            "deluxe" => "Deluxe",
                            "premium" => "Premium"
                        ];

                        $rankName = $rankNames[
                            $homestay["room_rank"]
                        ] ?? "Standard";

                        $detailUrl = "homestay.php?slug=" .
                            urlencode($homestay["slug"]);
                        ?>

                        <article class="homestay-card">
                            <a
                                href="<?php
                                echo htmlspecialchars($detailUrl);
                                ?>"
                                class="homestay-cover"
                            >
                                <?php if (!empty(
                                    $homestay["cover_image"]
                                )): ?>
                                    <img
                                        src="<?php
                                        echo htmlspecialchars(
                                            $homestay["cover_image"]
                                        );
                                        ?>"
                                        alt="<?php
                                        echo htmlspecialchars(
                                            $homestay["name"]
                                        );
                                        ?>"
                                    >
                                <?php else: ?>
                                    <div class="homestay-no-image">
                                        Chưa có ảnh
                                    </div>
                                <?php endif; ?>

                                <span class="homestay-rank">
                                    <?php
                                    echo htmlspecialchars($rankName);
                                    ?>
                                </span>
                            </a>

                            <div class="homestay-card-content">
                                <p class="homestay-location">
                                    <?php
                                    echo htmlspecialchars(
                                        $homestay[
                                            "tourist_destination"
                                        ]
                                    );
                                    ?>
                                    ·
                                    <?php
                                    echo htmlspecialchars(
                                        $homestay["province"]
                                    );
                                    ?>
                                </p>

                                <h3>
                                    <?php
                                    echo htmlspecialchars(
                                        $homestay["name"]
                                    );
                                    ?>
                                </h3>

                                <p class="homestay-information">
                                    1 giường đôi · Tối đa
                                    <?php
                                    echo (int) $homestay["max_guests"];
                                    ?> khách
                                </p>

                                <div class="homestay-card-footer">
                                    <div class="homestay-price">
                                        <small>Khoảng giá</small>

                                        <strong>
                                            <?php
                                            echo number_format(
                                                (float) $homestay[
                                                    "min_price"
                                                ],
                                                0,
                                                ",",
                                                "."
                                            );
                                            ?>đ
                                            –
                                            <?php
                                            echo number_format(
                                                (float) $homestay[
                                                    "max_price"
                                                ],
                                                0,
                                                ",",
                                                "."
                                            );
                                            ?>đ
                                        </strong>
                                    </div>

                                    <a
                                        href="<?php
                                        echo htmlspecialchars($detailUrl);
                                        ?>"
                                        class="homestay-detail-button"
                                    >
                                        Xem chi tiết
                                    </a>
                                </div>
                            </div>
                        </article>

                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        </div>
    </section>

    <section
        class="section"
        id="process"
    >
        <div class="container">

            <div class="section-heading">
                <div>
                    <p>ĐẶT PHÒNG AN TÂM</p>

                    <h2>
                        Tiền chỉ được tính khi kỳ nghỉ hoàn tất
                    </h2>
                </div>
            </div>

            <div class="process-grid">

                <article class="process-card">
                    <div class="process-number">01</div>

                    <h3>Khách thanh toán</h3>

                    <p>
                        Khách thanh toán cho website.
                        Tiền được giữ an toàn và chưa chuyển
                        vào ví của chủ homestay hay QTV.
                    </p>
                </article>

                <article class="process-card">
                    <div class="process-number">02</div>

                    <h3>Hoàn thành kỳ nghỉ</h3>

                    <p>
                        Sau khi khách trả phòng, hệ thống chờ
                        kiểm tra yêu cầu hủy, hoàn tiền hoặc
                        khiếu nại phát sinh.
                    </p>
                </article>

                <article class="process-card">
                    <div class="process-number">03</div>

                <h3>Hoàn tất kỳ nghỉ</h3>

                <p>
                    Sau khi kỳ nghỉ kết thúc, hệ thống sẽ xác nhận
                    giao dịch và bảo đảm quyền lợi của khách thuê.
                </p>
                </article>

            </div>
        </div>
    </section>

</main>

<footer class="site-footer">
    <div class="container">

        <div class="footer-content">
            <div>
                <h3>Đi Đâu Đây</h3>

                <p>
                    Homestay cho những chuyến đi nhỏ<br>
                    trên khắp Việt Nam.
                </p>
            </div>

            <div class="footer-links">

                <a href="#">
                    Chính sách đặt phòng
                </a>

                <a href="#">
                    Hủy phòng & hoàn tiền
                </a>

                <a href="#">
                    Liên hệ hỗ trợ
                </a>

            </div>
        </div>

        <div class="footer-bottom">
            © 2026 Đi Đâu Đây
        </div>

    </div>
</footer>

<script>
    function scrollDestinations(direction) {
        const slider =
            document.getElementById("destinationSlider");

        const firstCard =
            slider.querySelector(".destination-card");

        if (!slider || !firstCard) {
            return;
        }

        const cardWidth =
            firstCard.getBoundingClientRect().width;

        const gap = 18;

        slider.scrollBy({
            left: direction * (cardWidth + gap) * 2,
            behavior: "smooth"
        });
    }
</script>

</body>
</html>