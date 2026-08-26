<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("owner");

$ownerId = currentUserId();

$errors = [];

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/*
 * Chủ homestay xác nhận đơn đã thanh toán.
 */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    $action = $_POST["action"] ?? "";

    $bookingId = (int) (
        $_POST["booking_id"] ?? 0
    );

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Yêu cầu không hợp lệ.";
    }

    if ($bookingId <= 0) {
        $errors[] = "Không tìm thấy đơn đặt phòng.";
    }

    if ($action !== "confirm_booking") {
        $errors[] = "Thao tác không hợp lệ.";
    }

    if (empty($errors)) {
        $checkBookingStatement = $pdo->prepare(
            "SELECT
                b.id,
                b.status,

                p.status AS payment_status

            FROM bookings AS b

            INNER JOIN homestays AS h
                ON h.id = b.homestay_id

            LEFT JOIN payments AS p
                ON p.booking_id = b.id

            WHERE b.id = :booking_id

                AND h.owner_id = :owner_id

            LIMIT 1"
        );

        $checkBookingStatement->execute([
            "booking_id" => $bookingId,
            "owner_id" => $ownerId
        ]);

        $bookingToConfirm =
            $checkBookingStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$bookingToConfirm) {
            $errors[] =
                "Bạn không có quyền xử lý đơn này.";
        } elseif (
            $bookingToConfirm["status"]
            !== "funds_held"
        ) {
            $errors[] =
                "Đơn đặt phòng không ở trạng thái chờ xác nhận.";
        } elseif (
            $bookingToConfirm["payment_status"]
            !== "held"
        ) {
            $errors[] =
                "Khoản thanh toán chưa được website giữ.";
        } else {
            $confirmBookingStatement = $pdo->prepare(
                "UPDATE bookings

                SET status = 'confirmed'

                WHERE id = :booking_id

                    AND status = 'funds_held'"
            );

            $confirmBookingStatement->execute([
                "booking_id" => $bookingId
            ]);

            header(
                "Location: bookings.php?confirmed=1"
            );

            exit;
        }
    }
}

/*
 * Lấy toàn bộ đơn thuộc homestay của chủ nhà.
 */

$bookingStatement = $pdo->prepare(
    "SELECT
        b.id,

        b.booking_code,

        b.booking_type,

        b.check_in,

        b.check_out,

        b.guest_count,

        b.total_amount,

        b.status,

        b.created_at,

        h.id AS homestay_id,

        h.name AS homestay_name,

        h.slug AS homestay_slug,

        h.province,

        u.full_name AS guest_name,

        u.email AS guest_email,

        u.phone AS guest_phone,

        p.status AS payment_status,

        p.amount AS payment_amount,

        p.transaction_code,

        (
            SELECT hi.image_path

            FROM homestay_images AS hi

            WHERE hi.homestay_id = h.id

            ORDER BY
                hi.is_cover DESC,
                hi.sort_order ASC,
                hi.id ASC

            LIMIT 1
        ) AS cover_image

    FROM bookings AS b

    INNER JOIN homestays AS h
        ON h.id = b.homestay_id

    INNER JOIN users AS u
        ON u.id = b.guest_id

    LEFT JOIN payments AS p
        ON p.booking_id = b.id

    WHERE h.owner_id = :owner_id

    ORDER BY b.created_at DESC"
);

$bookingStatement->execute([
    "owner_id" => $ownerId
]);

$allBookings = $bookingStatement->fetchAll(
    PDO::FETCH_ASSOC
);

/*
 * Thống kê.
 */

$totalBookings = count($allBookings);

$waitingBookings = 0;

$confirmedBookings = 0;

$completedBookings = 0;

$heldAmount = 0;

$expectedOwnerAmount = 0;

foreach ($allBookings as $bookingItem) {
    $bookingStatus = $bookingItem["status"];

    $bookingAmount = (float) (
        $bookingItem["total_amount"]
    );

    if ($bookingStatus === "funds_held") {
        $waitingBookings++;
    }

    if ($bookingStatus === "confirmed") {
        $confirmedBookings++;
    }

    if ($bookingStatus === "completed") {
        $completedBookings++;
    }

    if (
        in_array(
            $bookingStatus,
            ["funds_held", "confirmed"],
            true
        )
    ) {
        $heldAmount += $bookingAmount;

        $expectedOwnerAmount +=
            $bookingAmount * 0.9;
    }
}

/*
 * Bộ lọc trạng thái.
 */

$availableFilters = [
    "all" => "Tất cả đơn",

    "pending_payment" =>
        "Chờ thanh toán",

    "funds_held" =>
        "Chờ chủ nhà xác nhận",

    "confirmed" =>
        "Đã xác nhận",

    "completed" =>
        "Đã hoàn tất",

    "cancelled" =>
        "Đã hủy",

    "refunded" =>
        "Đã hoàn tiền",

    "disputed" =>
        "Có khiếu nại"
];

$selectedStatus = $_GET["status"] ?? "all";

if (
    !array_key_exists(
        $selectedStatus,
        $availableFilters
    )
) {
    $selectedStatus = "all";
}

$searchKeyword = trim(
    $_GET["search"] ?? ""
);

/*
 * Lọc danh sách.
 */

$bookings = array_values(
    array_filter(
        $allBookings,

        function ($booking) use (
            $selectedStatus,
            $searchKeyword
        ) {
            if (
                $selectedStatus !== "all" &&
                $booking["status"] !== $selectedStatus
            ) {
                return false;
            }

            if ($searchKeyword === "") {
                return true;
            }

            $searchableText =
                $booking["booking_code"] .
                " " .
                $booking["guest_name"] .
                " " .
                $booking["guest_phone"] .
                " " .
                $booking["homestay_name"];

            if (function_exists("mb_stripos")) {
                return mb_stripos(
                    $searchableText,
                    $searchKeyword
                ) !== false;
            }

            return stripos(
                $searchableText,
                $searchKeyword
            ) !== false;
        }
    )
);

/*
 * Tên trạng thái.
 */

$bookingStatusNames = [
    "pending_payment" => "Chờ thanh toán",

    "funds_held" => "Chờ xác nhận",

    "confirmed" => "Đã xác nhận",

    "completed" => "Đã hoàn tất",

    "cancelled" => "Đã hủy",

    "refunded" => "Đã hoàn tiền",

    "disputed" => "Có khiếu nại"
];

$paymentStatusNames = [
    "pending" => "Chưa thanh toán",

    "held" => "Website đang giữ tiền",

    "settled" => "Đã quyết toán",

    "refunded" => "Đã hoàn tiền",

    "failed" => "Thanh toán thất bại"
];

$bookingTypeNames = [
    "hourly" => "Thuê theo giờ",

    "overnight" => "Thuê qua đêm"
];

/*
 * Định dạng tiền.
 */

function formatOwnerBookingMoney($amount)
{
    return number_format(
        (float) $amount,
        0,
        ",",
        "."
    ) . "đ";
}

/*
 * Định dạng ngày giờ.
 */

function formatOwnerBookingDate($dateTime)
{
    if (!$dateTime) {
        return "Chưa xác định";
    }

    $timestamp = strtotime($dateTime);

    if ($timestamp === false) {
        return "Chưa xác định";
    }

    return date(
        "d/m/Y H:i",
        $timestamp
    );
}

$bookingConfirmedSuccessfully = isset(
    $_GET["confirmed"]
);

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
        Đơn đặt phòng - Đi Đâu Đây
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >

    <style>
        .booking-stats {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 18px;

            margin-bottom: 24px;
        }

        .booking-stat-card {
            min-height: 130px;

            padding: 20px;

            border: 1px solid #dfe7e3;

            border-radius: 16px;

            background: #ffffff;
        }

        .booking-stat-card small {
            display: block;

            color: #74817a;

            font-size: 11px;

            font-weight: 800;

            letter-spacing: 0.06em;

            text-transform: uppercase;
        }

        .booking-stat-card strong {
            display: block;

            margin: 13px 0 6px;

            color: #174e3d;

            font-size: 27px;
        }

        .booking-stat-card span {
            color: #829089;

            font-size: 12px;
        }

        .owner-payment-notice {
            margin-bottom: 23px;

            padding: 17px 20px;

            display: flex;

            justify-content: space-between;

            gap: 20px;

            border: 1px solid #d7e9df;

            border-radius: 14px;

            color: #285441;

            background: #edf6f0;
        }

        .owner-payment-notice strong {
            color: #174d3c;
        }

        .booking-panel {
            overflow: hidden;

            border: 1px solid #dfe7e3;

            border-radius: 17px;

            background: #ffffff;
        }

        .booking-panel-heading {
            padding: 21px 23px;

            border-bottom: 1px solid #e7ece9;
        }

        .booking-panel-heading h2 {
            margin: 0;

            color: #26382f;

            font-size: 20px;
        }

        .booking-panel-heading p {
            margin: 5px 0 0;

            color: #7a8780;

            font-size: 13px;
        }

        .booking-filter-form {
            padding: 17px 22px;

            display: grid;

            grid-template-columns:
                minmax(0, 1fr)
                220px
                115px;

            gap: 12px;

            border-bottom: 1px solid #e7ece9;
        }

        .booking-filter-form input,
        .booking-filter-form select {
            width: 100%;

            height: 45px;

            padding: 0 13px;

            border: 1px solid #dfe6e2;

            border-radius: 10px;

            color: #34483d;

            background: #ffffff;

            outline: none;
        }

        .booking-filter-form button {
            height: 45px;

            border: none;

            border-radius: 10px;

            color: #ffffff;

            background: #205b47;

            font-weight: 750;

            cursor: pointer;
        }

        .booking-table-wrapper {
            overflow-x: auto;
        }

        .booking-table {
            width: 100%;

            min-width: 1320px;

            border-collapse: collapse;
        }

        .booking-table th {
            padding: 14px 16px;

            color: #79847e;

            background: #f8faf9;

            font-size: 11px;

            font-weight: 800;

            letter-spacing: 0.04em;

            text-align: left;

            text-transform: uppercase;
        }

        .booking-table td {
            padding: 16px;

            border-top: 1px solid #edf0ef;

            color: #43544a;

            font-size: 13px;

            vertical-align: middle;
        }

        .booking-code {
            display: block;

            margin-bottom: 5px;

            color: #174c3c;

            font-size: 13px;

            font-weight: 850;
        }

        .booking-created-at {
            color: #87918c;

            font-size: 11px;
        }

        .booking-homestay {
            display: flex;

            align-items: center;

            gap: 12px;
        }

        .booking-homestay-image {
            width: 75px;

            height: 60px;

            flex-shrink: 0;

            overflow: hidden;

            border-radius: 10px;

            background: #eaf0ed;
        }

        .booking-homestay-image img {
            width: 100%;

            height: 100%;

            display: block;

            object-fit: cover;
        }

        .booking-homestay-name {
            display: block;

            margin-bottom: 4px;

            color: #263d32;

            font-weight: 800;
        }

        .booking-homestay-province {
            color: #7d8982;

            font-size: 11px;
        }

        .booking-guest-name {
            display: block;

            margin-bottom: 5px;

            color: #294036;

            font-weight: 750;
        }

        .booking-guest-phone {
            display: block;

            color: #78847e;

            font-size: 12px;
        }

        .booking-time {
            white-space: nowrap;
        }

        .booking-time strong {
            display: block;

            margin-bottom: 4px;

            color: #32463c;

            font-size: 12px;
        }

        .booking-time small {
            display: block;

            margin-bottom: 7px;

            color: #829089;
        }

        .booking-total {
            display: block;

            margin-bottom: 6px;

            color: #174f3d;

            font-size: 14px;

            font-weight: 850;

            white-space: nowrap;
        }

        .booking-owner-income {
            color: #718078;

            font-size: 11px;

            white-space: nowrap;
        }

        .booking-status {
            display: inline-block;

            padding: 7px 10px;

            border-radius: 999px;

            font-size: 11px;

            font-weight: 800;

            white-space: nowrap;
        }

        .booking-status.pending_payment {
            color: #936523;

            background: #fff1da;
        }

        .booking-status.funds_held {
            color: #8a611e;

            background: #fff0d7;
        }

        .booking-status.confirmed {
            color: #246447;

            background: #e8f5ed;
        }

        .booking-status.completed {
            color: #285f85;

            background: #e9f2fa;
        }

        .booking-status.cancelled,
        .booking-status.refunded,
        .booking-status.disputed {
            color: #a04848;

            background: #fdeeee;
        }

        .booking-payment-status {
            display: block;

            margin-top: 7px;

            color: #79867f;

            font-size: 11px;
        }

        .booking-confirm-button {
            padding: 10px 12px;

            border: none;

            border-radius: 9px;

            color: #ffffff;

            background: #205b47;

            font-size: 11px;

            font-weight: 800;

            white-space: nowrap;

            cursor: pointer;
        }

        .booking-action-note {
            color: #7f8b84;

            font-size: 11px;
        }

        .empty-bookings {
            padding: 75px 25px;

            color: #75827b;

            text-align: center;
        }

        .empty-bookings h3 {
            margin: 0 0 10px;

            color: #30443a;
        }

        .empty-bookings p {
            margin: 0;

            font-size: 14px;
        }

        .booking-success-message {
            margin-bottom: 18px;

            padding: 14px 17px;

            border: 1px solid #cfe8d7;

            border-radius: 10px;

            color: #286644;

            background: #edf8f0;
        }

        .booking-error-message {
            margin-bottom: 18px;

            padding: 14px 17px;

            border: 1px solid #efcccc;

            border-radius: 10px;

            color: #984646;

            background: #fff1f1;
        }

        @media (max-width: 1100px) {
            .booking-stats {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .booking-stats {
                grid-template-columns: 1fr;
            }

            .booking-filter-form {
                grid-template-columns: 1fr;
            }

            .owner-payment-notice {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<div class="dashboard">

    <aside class="sidebar">

        <a
            href="../index.php"
            class="sidebar-logo"
        >
            Đi Đâu Đây
        </a>

        <div class="sidebar-label">
            KHU VỰC CHỦ HOMESTAY
        </div>

        <nav class="sidebar-menu">

            <a href="index.php">
                Tổng quan
            </a>

            <a href="profile.php">
                Hồ sơ chủ homestay
            </a>

            <a href="homestays.php">
                Homestay của tôi
            </a>

            <a href="add_homestay.php">
                Thêm homestay
            </a>

            <a
                href="bookings.php"
                class="active"
            >
                Đơn đặt phòng
            </a>

            <a href="wallet.php">
                Ví của tôi
            </a>

        </nav>

        <div class="sidebar-bottom">

            <div class="sidebar-user">

                <div class="sidebar-avatar">
                    CN
                </div>

                <div>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            currentUserName()
                        );
                        ?>
                    </strong>

                    <small>
                        Chủ homestay
                    </small>

                </div>

            </div>

            <a
                href="../auth/logout.php"
                class="logout-link"
            >
                Đăng xuất
            </a>

        </div>

    </aside>

    <main class="dashboard-main">

        <header class="dashboard-header">

            <div>

                <p>
                    QUẢN LÝ KHÁCH THUÊ
                </p>

                <h1>
                    Đơn đặt phòng
                </h1>

            </div>

            <a
                href="homestays.php"
                class="button button-outline"
            >
                Xem homestay
            </a>

        </header>

        <?php if (
            $bookingConfirmedSuccessfully
        ): ?>

            <div
                class="booking-success-message"
            >
                Đã xác nhận đơn đặt phòng thành công.
            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="booking-error-message">

                <?php foreach (
                    $errors as $error
                ): ?>

                    <div>

                        <?php
                        echo htmlspecialchars(
                            $error
                        );
                        ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <section class="booking-stats">

            <article
                class="booking-stat-card"
            >

                <small>
                    Tổng đơn đặt phòng
                </small>

                <strong>

                    <?php
                    echo $totalBookings;
                    ?>

                </strong>

                <span>
                    Tất cả đơn đã nhận
                </span>

            </article>

            <article
                class="booking-stat-card"
            >

                <small>
                    Chờ xác nhận
                </small>

                <strong>

                    <?php
                    echo $waitingBookings;
                    ?>

                </strong>

                <span>
                    Đơn đã được khách thanh toán
                </span>

            </article>

            <article
                class="booking-stat-card"
            >

                <small>
                    Đã xác nhận
                </small>

                <strong>

                    <?php
                    echo $confirmedBookings;
                    ?>

                </strong>

                <span>
                    Chờ khách nhận hoặc trả phòng
                </span>

            </article>

            <article
                class="booking-stat-card"
            >

                <small>
                    Đã hoàn tất
                </small>

                <strong>

                    <?php
                    echo $completedBookings;
                    ?>

                </strong>

                <span>
                    Các kỳ nghỉ đã kết thúc
                </span>

            </article>

        </section>

        <section
            class="owner-payment-notice"
        >

            <div>

                Website đang giữ:

                <strong>

                    <?php
                    echo formatOwnerBookingMoney(
                        $heldAmount
                    );
                    ?>

                </strong>

            </div>

            <div>

                Chủ homestay dự kiến nhận 90%:

                <strong>

                    <?php
                    echo formatOwnerBookingMoney(
                        $expectedOwnerAmount
                    );
                    ?>

                </strong>

            </div>

        </section>

        <section class="booking-panel">

            <div
                class="booking-panel-heading"
            >

                <h2>
                    Danh sách đơn đặt phòng
                </h2>

                <p>
                    Theo dõi thông tin khách, trạng thái
                    thanh toán và thời gian lưu trú.
                </p>

            </div>

            <form
                method="GET"
                class="booking-filter-form"
            >

                <input
                    type="text"
                    name="search"
                    value="<?php
                    echo htmlspecialchars(
                        $searchKeyword
                    );
                    ?>"
                    placeholder="Tìm theo mã đơn, tên khách, số điện thoại hoặc homestay..."
                >

                <select name="status">

                    <?php foreach (
                        $availableFilters as
                        $filterValue => $filterLabel
                    ): ?>

                        <option
                            value="<?php
                            echo htmlspecialchars(
                                $filterValue
                            );
                            ?>"
                            <?php
                            echo (
                                $selectedStatus ===
                                $filterValue
                            )
                                ? "selected"
                                : "";
                            ?>
                        >

                            <?php
                            echo htmlspecialchars(
                                $filterLabel
                            );
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

                <button type="submit">
                    Tìm kiếm
                </button>

            </form>

            <?php if (empty($bookings)): ?>

                <div class="empty-bookings">

                    <h3>
                        Chưa có đơn đặt phòng phù hợp
                    </h3>

                    <p>

                        <?php if (
                            empty($allBookings)
                        ): ?>

                            Khi khách đặt phòng, đơn sẽ
                            xuất hiện tại đây.

                        <?php else: ?>

                            Hãy thử đổi từ khóa hoặc
                            trạng thái tìm kiếm.

                        <?php endif; ?>

                    </p>

                </div>

            <?php else: ?>

                <div
                    class="booking-table-wrapper"
                >

                    <table class="booking-table">

                        <thead>

                            <tr>

                                <th>
                                    Mã đơn
                                </th>

                                <th>
                                    Homestay
                                </th>

                                <th>
                                    Khách thuê
                                </th>

                                <th>
                                    Thời gian
                                </th>

                                <th>
                                    Giá trị đơn
                                </th>

                                <th>
                                    Trạng thái
                                </th>

                                <th>
                                    Thao tác
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $bookings as $booking
                            ): ?>

                                <?php

                                $bookingStatus =
                                    $booking["status"];

                                $bookingStatusLabel =
                                    $bookingStatusNames[
                                        $bookingStatus
                                    ] ??
                                    "Không xác định";

                                $paymentStatus =
                                    $booking[
                                        "payment_status"
                                    ] ??
                                    "pending";

                                $paymentStatusLabel =
                                    $paymentStatusNames[
                                        $paymentStatus
                                    ] ??
                                    "Chưa xác định";

                                $bookingType =
                                    $bookingTypeNames[
                                        $booking[
                                            "booking_type"
                                        ]
                                    ] ??
                                    "Đặt phòng";

                                $ownerIncome =
                                    (float) $booking[
                                        "total_amount"
                                    ] * 0.9;

                                $coverImage = "";

                                if (
                                    !empty(
                                        $booking[
                                            "cover_image"
                                        ]
                                    )
                                ) {
                                    $coverImage =
                                        "../" .
                                        ltrim(
                                            $booking[
                                                "cover_image"
                                            ],
                                            "/"
                                        );
                                }

                                ?>

                                <tr>

                                    <td>

                                        <span
                                            class="booking-code"
                                        >

                                            <?php
                                            echo htmlspecialchars(
                                                $booking[
                                                    "booking_code"
                                                ]
                                            );
                                            ?>

                                        </span>

                                        <span
                                            class="booking-created-at"
                                        >

                                            <?php
                                            echo formatOwnerBookingDate(
                                                $booking[
                                                    "created_at"
                                                ]
                                            );
                                            ?>

                                        </span>

                                    </td>

                                    <td>

                                        <div
                                            class="booking-homestay"
                                        >

                                            <div
                                                class="booking-homestay-image"
                                            >

                                                <?php if (
                                                    $coverImage
                                                    !== ""
                                                ): ?>

                                                    <img
                                                        src="<?php
                                                        echo htmlspecialchars(
                                                            $coverImage
                                                        );
                                                        ?>"
                                                        alt="<?php
                                                        echo htmlspecialchars(
                                                            $booking[
                                                                "homestay_name"
                                                            ]
                                                        );
                                                        ?>"
                                                    >

                                                <?php endif; ?>

                                            </div>

                                            <div>

                                                <span
                                                    class="booking-homestay-name"
                                                >

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $booking[
                                                            "homestay_name"
                                                        ]
                                                    );
                                                    ?>

                                                </span>

                                                <span
                                                    class="booking-homestay-province"
                                                >

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $booking[
                                                            "province"
                                                        ]
                                                    );
                                                    ?>

                                                </span>

                                            </div>

                                        </div>

                                    </td>

                                    <td>

                                        <span
                                            class="booking-guest-name"
                                        >

                                            <?php
                                            echo htmlspecialchars(
                                                $booking[
                                                    "guest_name"
                                                ]
                                            );
                                            ?>

                                        </span>

                                        <span
                                            class="booking-guest-phone"
                                        >

                                            <?php
                                            echo htmlspecialchars(
                                                $booking[
                                                    "guest_phone"
                                                ]
                                            );
                                            ?>

                                        </span>

                                        <span
                                            class="booking-guest-phone"
                                        >

                                            <?php
                                            echo (int) (
                                                $booking[
                                                    "guest_count"
                                                ]
                                            );
                                            ?>

                                            khách

                                        </span>

                                    </td>

                                    <td class="booking-time">

                                        <strong>

                                            <?php
                                            echo htmlspecialchars(
                                                $bookingType
                                            );
                                            ?>

                                        </strong>

                                        <small>

                                            Nhận:

                                            <?php
                                            echo formatOwnerBookingDate(
                                                $booking[
                                                    "check_in"
                                                ]
                                            );
                                            ?>

                                        </small>

                                        <small>

                                            Trả:

                                            <?php
                                            echo formatOwnerBookingDate(
                                                $booking[
                                                    "check_out"
                                                ]
                                            );
                                            ?>

                                        </small>

                                    </td>

                                    <td>

                                        <span
                                            class="booking-total"
                                        >

                                            <?php
                                            echo formatOwnerBookingMoney(
                                                $booking[
                                                    "total_amount"
                                                ]
                                            );
                                            ?>

                                        </span>

                                        <span
                                            class="booking-owner-income"
                                        >

                                            Chủ nhà nhận:

                                            <?php
                                            echo formatOwnerBookingMoney(
                                                $ownerIncome
                                            );
                                            ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span
                                            class="booking-status <?php
                                            echo htmlspecialchars(
                                                $bookingStatus
                                            );
                                            ?>"
                                        >

                                            <?php
                                            echo htmlspecialchars(
                                                $bookingStatusLabel
                                            );
                                            ?>

                                        </span>

                                        <span
                                            class="booking-payment-status"
                                        >

                                            <?php
                                            echo htmlspecialchars(
                                                $paymentStatusLabel
                                            );
                                            ?>

                                        </span>

                                    </td>

                                    <td>

                                        <?php if (
                                            $bookingStatus
                                            === "funds_held"
                                        ): ?>

                                            <form
                                                method="POST"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?php
                                                    echo htmlspecialchars(
                                                        $_SESSION[
                                                            "csrf_token"
                                                        ]
                                                    );
                                                    ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="booking_id"
                                                    value="<?php
                                                    echo (int) (
                                                        $booking[
                                                            "id"
                                                        ]
                                                    );
                                                    ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="confirm_booking"
                                                >

                                                <button
                                                    type="submit"
                                                    class="booking-confirm-button"
                                                >
                                                    Xác nhận
                                                </button>

                                            </form>

                                        <?php elseif (
                                            $bookingStatus
                                            === "confirmed"
                                        ): ?>

                                            <span
                                                class="booking-action-note"
                                            >
                                                Chờ hoàn tất kỳ nghỉ
                                            </span>

                                        <?php elseif (
                                            $bookingStatus
                                            === "pending_payment"
                                        ): ?>

                                            <span
                                                class="booking-action-note"
                                            >
                                                Chờ khách thanh toán
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="booking-action-note"
                                            >
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

</body>
</html>