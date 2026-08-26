<?php

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/session.php";

/* Lấy slug homestay */

$slug = trim($_GET["slug"] ?? "");

if ($slug === "") {
    header("Location: index.php");
    exit;
}

/* Lấy thông tin homestay và bảng giá */

$homestayStatement = $pdo->prepare(
    "SELECT
        h.*,

        hp.price_first_2_hours,
        hp.price_combo_4_hours,
        hp.price_extra_hour,

        hp.price_overnight_weekday,
        hp.price_overnight_weekend,

        hp.price_day_night_weekday,
        hp.price_day_night_weekend,

        hp.price_day_weekday,
        hp.price_day_weekend

    FROM homestays AS h

    LEFT JOIN homestay_prices AS hp
        ON hp.homestay_id = h.id

    WHERE h.slug = :slug
        AND h.status = 'approved'

    LIMIT 1"
);

$homestayStatement->execute([
    "slug" => $slug
]);

$homestay = $homestayStatement->fetch(
    PDO::FETCH_ASSOC
);

/* Không tìm thấy homestay */

if (!$homestay) {
    http_response_code(404);

    exit(
        "<h2>Không tìm thấy homestay.</h2>" .
        "<a href='index.php'>Quay về trang chủ</a>"
    );
}

/* Lấy toàn bộ ảnh */

$imageStatement = $pdo->prepare(
    "SELECT
        image_path,
        is_cover,
        sort_order

    FROM homestay_images

    WHERE homestay_id = :homestay_id

    ORDER BY
        is_cover DESC,
        sort_order ASC,
        id ASC"
);

$imageStatement->execute([
    "homestay_id" => $homestay["id"]
]);

$images = $imageStatement->fetchAll(
    PDO::FETCH_ASSOC
);

$mainImage = $images[0]["image_path"] ?? "";

/* Kiểm tra đăng nhập */

$isLoggedIn = isset($_SESSION["user_id"]);

$userRole = $_SESSION["role"] ?? "";

$dashboardUrl = "";

if ($userRole === "admin") {
    $dashboardUrl = "admin/index.php";
} elseif ($userRole === "owner") {
    $dashboardUrl = "owner/index.php";
}

/* Hạng phòng */

$rankLabels = [
    "standard" => "Standard",
    "deluxe" => "Deluxe",
    "premium" => "Premium"
];

$roomRank = $rankLabels[
    $homestay["room_rank"]
] ?? "Standard";

/* Định dạng tiền */

function formatHomestayPrice($value)
{
    if (
        $value === null ||
        $value === "" ||
        (float) $value <= 0
    ) {
        return "Liên hệ";
    }

    return number_format(
        (float) $value,
        0,
        ",",
        "."
    ) . "đ";
}

/* Giá thấp nhất và cao nhất */

$availablePrices = [];

$priceFields = [
    "price_first_2_hours",
    "price_combo_4_hours",
    "price_overnight_weekday",
    "price_overnight_weekend",
    "price_day_night_weekday",
    "price_day_night_weekend",
    "price_day_weekday",
    "price_day_weekend"
];

foreach ($priceFields as $field) {
    $price = $homestay[$field] ?? null;

    if ($price !== null && (float) $price > 0) {
        $availablePrices[] = (float) $price;
    }
}

if (empty($availablePrices)) {
    $availablePrices[] =
        (float) $homestay["price_per_hour"];

    $availablePrices[] =
        (float) $homestay["overnight_price"];
}

$minimumPrice = min($availablePrices);
$maximumPrice = max($availablePrices);

/* Tiện nghi mặc định */

$amenities = [
    "Wi-Fi",
    "Điều hòa",
    "Bếp riêng",
    "Máy giặt",
    "Bãi đỗ xe",
    "Máy chiếu Netflix",
    "Gương toàn thân",
    "Board game",
    "Nhà vệ sinh khép kín",
    "Check-in/out tự động"
];

/* Tiện nghi bổ sung */

if ((int) $homestay["has_bathtub"] === 1) {
    $amenities[] = "Bồn tắm";
}

if ((int) $homestay["has_balcony"] === 1) {
    $amenities[] = "Ban công";
}

if ((int) $homestay["has_mini_pool"] === 1) {
    $amenities[] = "Bể bơi mini";
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

    <title>
        <?php
        echo htmlspecialchars($homestay["name"]);
        ?> - Đi Đâu Đây
    </title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #21352e;
            background: #f8faf9;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .detail-header {
            height: 78px;
            display: flex;
            align-items: center;
            background: #ffffff;
            border-bottom: 1px solid #e7edeb;
        }

        .detail-container {
            width: min(1180px, calc(100% - 40px));
            margin: 0 auto;
        }

        .detail-header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .detail-logo {
            color: #174f40;
            font-size: 26px;
            font-weight: 900;
            text-decoration: none;
        }

        .detail-header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .detail-button-outline,
        .detail-button-primary {
            min-height: 43px;
            padding: 11px 17px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .detail-button-outline {
            color: #174f40;
            background: #ffffff;
            border: 1px solid #cbdad4;
        }

        .detail-button-primary {
            color: #ffffff;
            background: #1b5746;
            border: 1px solid #1b5746;
        }

        .detail-main {
            padding: 35px 0 70px;
        }

        .detail-breadcrumb {
            margin: 0 0 23px;
            color: #78847e;
            font-size: 13px;
        }

        .detail-breadcrumb a {
            color: #174f40;
            text-decoration: none;
        }

        .detail-heading {
            margin-bottom: 22px;
        }

        .detail-rank {
            display: inline-block;
            margin-bottom: 10px;
            padding: 7px 11px;
            color: #1b5746;
            background: #e7f0ed;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .detail-heading h1 {
            margin: 0 0 8px;
            color: #173c31;
            font-size: 39px;
            line-height: 1.15;
        }

        .detail-heading p {
            margin: 0;
            color: #78847e;
            font-size: 15px;
        }

        .gallery-main {
            height: 510px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e7ecea;
            border-radius: 22px;
        }

        .gallery-main img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: contain;
        }

        .gallery-empty {
            color: #64736c;
            font-size: 16px;
            font-weight: 700;
        }

        .gallery-thumbnails {
            margin-top: 14px;
            display: grid;
            grid-template-columns: repeat(
                auto-fill,
                minmax(112px, 1fr)
            );
            gap: 12px;
        }

        .gallery-thumbnail {
            height: 85px;
            padding: 0;
            overflow: hidden;
            background: #ffffff;
            border: 2px solid transparent;
            border-radius: 12px;
            cursor: pointer;
        }

        .gallery-thumbnail.active {
            border-color: #1b5746;
        }

        .gallery-thumbnail img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }

        .detail-layout {
            margin-top: 32px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            align-items: start;
            gap: 26px;
        }

        .detail-panel {
            padding: 24px;
            background: #ffffff;
            border: 1px solid #e2eae6;
            border-radius: 18px;
        }

        .detail-panel + .detail-panel {
            margin-top: 20px;
        }

        .detail-panel h2 {
            margin: 0 0 17px;
            color: #173c31;
            font-size: 21px;
        }

        .detail-description {
            margin: 0;
            color: #55665e;
            line-height: 1.85;
            white-space: pre-line;
        }

        .detail-summary {
            margin: 20px 0 0;
            padding: 18px 0 0;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            border-top: 1px solid #e8eeeb;
        }

        .summary-item {
            padding: 10px 13px;
            color: #355449;
            background: #f3f7f5;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .amenity-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .amenity-item {
            padding: 12px 13px;
            color: #40574d;
            background: #f6f8f7;
            border-radius: 10px;
            font-size: 13px;
        }

        .amenity-check {
            margin-right: 8px;
            color: #23805f;
            font-weight: 800;
        }

        .combo-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .combo-item {
            padding: 16px;
            background: #f4f8f6;
            border-radius: 12px;
        }

        .combo-item small {
            display: block;
            margin-bottom: 8px;
            color: #718079;
            font-size: 12px;
        }

        .combo-item strong {
            color: #174f40;
            font-size: 19px;
        }

        .price-table-wrapper {
            overflow-x: auto;
        }

        .price-table {
            width: 100%;
            margin-top: 22px;
            border-collapse: collapse;
        }

        .price-table th,
        .price-table td {
            padding: 15px 10px;
            border-bottom: 1px solid #e9efec;
            text-align: left;
        }

        .price-table th {
            color: #718079;
            font-size: 12px;
            font-weight: 800;
        }

        .price-table td {
            color: #33483f;
            font-size: 13px;
        }

        .price-table td strong {
            color: #174f40;
        }

        .price-table td small {
            display: block;
            margin-top: 4px;
            color: #7d8983;
            font-size: 11px;
        }

        .booking-card {
            position: sticky;
            top: 22px;
            padding: 23px;
            background: #ffffff;
            border: 1px solid #e2eae6;
            border-radius: 18px;
            box-shadow: 0 14px 35px rgba(
                20,
                65,
                50,
                0.07
            );
        }

        .booking-card small {
            color: #7a8781;
            font-size: 12px;
        }

        .booking-card h3 {
            margin: 8px 0 18px;
            color: #174f40;
            font-size: 25px;
        }

        .booking-line {
            padding: 12px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            border-top: 1px solid #e9efec;
            font-size: 13px;
        }

        .booking-line span {
            color: #74817a;
        }

        .booking-line strong {
            color: #29443a;
            text-align: right;
        }

        .booking-action {
            width: 100%;
            margin-top: 18px;
            padding: 14px;
            color: #ffffff;
            background: #1b5746;
            border: none;
            border-radius: 11px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 750;
            text-align: center;
            text-decoration: none;
        }

        a.booking-action {
            display: block;
        }

        .booking-note {
            margin: 14px 0 0;
            color: #74817a;
            font-size: 12px;
            line-height: 1.7;
        }

        .detail-footer {
            padding: 24px;
            color: #78847e;
            background: #ffffff;
            border-top: 1px solid #e7edeb;
            text-align: center;
            font-size: 13px;
        }

        @media (max-width: 850px) {
            .detail-layout {
                grid-template-columns: 1fr;
            }

            .booking-card {
                position: static;
            }
        }

        @media (max-width: 600px) {
            .detail-container {
                width: calc(100% - 28px);
            }

            .detail-header {
                height: auto;
                padding: 14px 0;
            }

            .detail-header-inner {
                align-items: flex-start;
                flex-direction: column;
            }

            .detail-heading h1 {
                font-size: 30px;
            }

            .gallery-main {
                height: 300px;
            }

            .amenity-list {
                grid-template-columns: 1fr;
            }

            .combo-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header class="detail-header">
    <div class="detail-container detail-header-inner">

        <a
            href="index.php"
            class="detail-logo"
        >
            Đi Đâu Đây
        </a>

        <div class="detail-header-actions">

            <a
                href="index.php"
                class="detail-button-outline"
            >
                ← Trang chủ
            </a>

            <?php if ($dashboardUrl !== ""): ?>

                <a
                    href="<?php
                    echo htmlspecialchars($dashboardUrl);
                    ?>"
                    class="detail-button-primary"
                >
                    Trang quản lý
                </a>

            <?php elseif (!$isLoggedIn): ?>

                <a
                    href="auth/login.php"
                    class="detail-button-primary"
                >
                    Đăng nhập
                </a>

            <?php endif; ?>

        </div>
    </div>
</header>

<main class="detail-main">
    <div class="detail-container">

        <p class="detail-breadcrumb">
            <a href="index.php">
                Trang chủ
            </a>

            /

            <?php
            echo htmlspecialchars(
                $homestay["province"]
            );
            ?>

            /

            <?php
            echo htmlspecialchars(
                $homestay["name"]
            );
            ?>
        </p>

        <div class="detail-heading">

            <span class="detail-rank">
                <?php
                echo htmlspecialchars($roomRank);
                ?>
            </span>

            <h1>
                <?php
                echo htmlspecialchars(
                    $homestay["name"]
                );
                ?>
            </h1>

            <p>
                <?php
                echo htmlspecialchars(
                    $homestay["address"]
                );
                ?>

                ·

                <?php
                echo htmlspecialchars(
                    $homestay["tourist_destination"]
                );
                ?>

                ·

                <?php
                echo htmlspecialchars(
                    $homestay["province"]
                );
                ?>
            </p>

        </div>

        <section class="gallery">

            <div class="gallery-main">

                <?php if ($mainImage !== ""): ?>

                    <img
                        id="main-homestay-image"
                        src="<?php
                        echo htmlspecialchars($mainImage);
                        ?>"
                        alt="<?php
                        echo htmlspecialchars(
                            $homestay["name"]
                        );
                        ?>"
                    >

                <?php else: ?>

                    <div class="gallery-empty">
                        Homestay chưa có ảnh.
                    </div>

                <?php endif; ?>

            </div>

            <?php if (count($images) > 1): ?>

                <div class="gallery-thumbnails">

                    <?php foreach (
                        $images as $index => $image
                    ): ?>

                        <button
                            type="button"
                            class="gallery-thumbnail <?php
                            echo $index === 0
                                ? 'active'
                                : '';
                            ?>"
                            data-image="<?php
                            echo htmlspecialchars(
                                $image["image_path"]
                            );
                            ?>"
                            onclick="changeHomestayImage(this)"
                        >

                            <img
                                src="<?php
                                echo htmlspecialchars(
                                    $image["image_path"]
                                );
                                ?>"
                                alt="Ảnh homestay <?php
                                echo $index + 1;
                                ?>"
                            >

                        </button>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

        <div class="detail-layout">

            <div>

                <section class="detail-panel">

                    <h2>Giới thiệu homestay</h2>

                    <p class="detail-description"><?php
                        echo htmlspecialchars(
                            $homestay["description"]
                        );
                    ?></p>

                    <div class="detail-summary">

                        <div class="summary-item">
                            1 giường đôi
                        </div>

                        <div class="summary-item">
                            Tối đa
                            <?php
                            echo (int) $homestay[
                                "max_guests"
                            ];
                            ?>
                            khách
                        </div>

                        <div class="summary-item">
                            Thuê tối thiểu 2 giờ
                        </div>

                        <div class="summary-item">
                            Check-in/out tự động
                        </div>

                    </div>

                </section>

                <section class="detail-panel">

                    <h2>Tiện nghi</h2>

                    <div class="amenity-list">

                        <?php foreach (
                            $amenities as $amenity
                        ): ?>

                            <div class="amenity-item">

                                <span class="amenity-check">
                                    ✓
                                </span>

                                <?php
                                echo htmlspecialchars(
                                    $amenity
                                );
                                ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </section>

                <section class="detail-panel">

                    <h2>Bảng giá</h2>

                    <div class="combo-grid">

                        <div class="combo-item">

                            <small>
                                2 giờ đầu
                            </small>

                            <strong>
                                <?php
                                echo formatHomestayPrice(
                                    $homestay[
                                        "price_first_2_hours"
                                    ]
                                );
                                ?>
                            </strong>

                        </div>

                        <div class="combo-item">

                            <small>
                                Combo 4 giờ
                            </small>

                            <strong>
                                <?php
                                echo formatHomestayPrice(
                                    $homestay[
                                        "price_combo_4_hours"
                                    ]
                                );
                                ?>
                            </strong>

                        </div>

                        <div class="combo-item">

                            <small>
                                Thêm mỗi giờ
                            </small>

                            <strong>
                                <?php
                                echo formatHomestayPrice(
                                    $homestay[
                                        "price_extra_hour"
                                    ]
                                );
                                ?>
                            </strong>

                        </div>

                    </div>

                    <div class="price-table-wrapper">

                        <table class="price-table">

                            <thead>

                                <tr>
                                    <th>
                                        Gói thuê
                                    </th>

                                    <th>
                                        T2–T5
                                    </th>

                                    <th>
                                        T6–CN
                                    </th>
                                </tr>

                            </thead>

                            <tbody>

                                <tr>

                                    <td>

                                        <strong>
                                            Giá đêm
                                        </strong>

                                        <small>
                                            22h–10h sáng hôm sau
                                        </small>

                                    </td>

                                    <td>
                                        <?php
                                        echo formatHomestayPrice(
                                            $homestay[
                                                "price_overnight_weekday"
                                            ]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo formatHomestayPrice(
                                            $homestay[
                                                "price_overnight_weekend"
                                            ]
                                        );
                                        ?>
                                    </td>

                                </tr>

                                <tr>

                                    <td>

                                        <strong>
                                            Giá ngày đêm
                                        </strong>

                                        <small>
                                            15h–10h sáng hôm sau
                                        </small>

                                    </td>

                                    <td>
                                        <?php
                                        echo formatHomestayPrice(
                                            $homestay[
                                                "price_day_night_weekday"
                                            ]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo formatHomestayPrice(
                                            $homestay[
                                                "price_day_night_weekend"
                                            ]
                                        );
                                        ?>
                                    </td>

                                </tr>

                                <tr>

                                    <td>

                                        <strong>
                                            Giá ban ngày
                                        </strong>

                                        <small>
                                            11h–21h
                                        </small>

                                    </td>

                                    <td>
                                        <?php
                                        echo formatHomestayPrice(
                                            $homestay[
                                                "price_day_weekday"
                                            ]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo formatHomestayPrice(
                                            $homestay[
                                                "price_day_weekend"
                                            ]
                                        );
                                        ?>
                                    </td>

                                </tr>

                            </tbody>

                        </table>

                    </div>

                </section>

            </div>

            <aside class="booking-card">

                <small>
                    Khoảng giá của homestay
                </small>

                <h3>
                    <?php
                    echo formatHomestayPrice(
                        $minimumPrice
                    );
                    ?>

                    –

                    <?php
                    echo formatHomestayPrice(
                        $maximumPrice
                    );
                    ?>
                </h3>

                <div class="booking-line">

                    <span>
                        Hạng phòng
                    </span>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $roomRank
                        );
                        ?>
                    </strong>

                </div>

                <div class="booking-line">

                    <span>
                        Sức chứa
                    </span>

                    <strong>
                        <?php
                        echo (int) $homestay[
                            "max_guests"
                        ];
                        ?>
                        khách
                    </strong>

                </div>

                <div class="booking-line">

                    <span>
                        Điểm du lịch
                    </span>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $homestay[
                                "tourist_destination"
                            ]
                        );
                        ?>
                    </strong>

                </div>

                <?php if (!$isLoggedIn): ?>

                    <a
                        href="auth/login.php"
                        class="booking-action"
                    >
                        Đăng nhập để đặt phòng
                    </a>

                <?php elseif ($userRole === "guest"): ?>

                     <a
                        href="guest/book.php?homestay_id=<?php
                    echo (int) $homestay["id"];
                ?>"

                        class="booking-action"
                    >
                      Đặt phòng
                     </a>

                <?php else: ?>

                    <p class="booking-note">
                        Đăng nhập bằng tài khoản khách thuê
                        để thực hiện đặt phòng.
                    </p>

                <?php endif; ?>

                <p class="booking-note">
                    Thanh toán được website giữ an toàn cho đến
                    khi kỳ nghỉ hoàn tất.
                </p>

            </aside>

        </div>

    </div>
</main>

<footer class="detail-footer">
    © 2026 Đi Đâu Đây.
</footer>

<script>
    function changeHomestayImage(button) {
        const mainImage = document.getElementById(
            "main-homestay-image"
        );

        if (!mainImage) {
            return;
        }

        mainImage.src = button.dataset.image;

        document
            .querySelectorAll(".gallery-thumbnail")
            .forEach(function (thumbnail) {
                thumbnail.classList.remove("active");
            });

        button.classList.add("active");
    }
</script>

</body>

</html>