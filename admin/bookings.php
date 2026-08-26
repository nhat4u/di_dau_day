<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("admin");

date_default_timezone_set("Asia/Ho_Chi_Minh");

/*
|--------------------------------------------------------------------------
| Hàm hỗ trợ hiển thị
|--------------------------------------------------------------------------
*/

function adminBookingEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function adminBookingMoney($amount)
{
    return number_format(
        (float) $amount,
        0,
        ",",
        "."
    ) . "đ";
}

function adminBookingDate($value)
{
    if (empty($value)) {
        return "—";
    }

    return date(
        "d/m/Y H:i",
        strtotime($value)
    );
}

function adminBookingStatusName($status)
{
    $names = [

        "pending_payment" =>
            "Chờ thanh toán",

        "funds_held" =>
            "Chờ chủ home xác nhận",

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

    return $names[$status] ?? $status;
}

function adminPaymentStatusName($status)
{
    $names = [

        "pending" =>
            "Chưa thanh toán",

        "held" =>
            "Website đang giữ tiền",

        "settled" =>
            "Đã quyết toán",

        "refunded" =>
            "Đã hoàn tiền",

        "failed" =>
            "Thanh toán thất bại"

    ];

    return $names[$status] ?? "Chưa thanh toán";
}

function adminBookingTypeName($type)
{
    $names = [

        "hourly" =>
            "Thuê theo giờ",

        "overnight" =>
            "Thuê qua đêm",

        "daytime" =>
            "Thuê ban ngày",

        "day_night" =>
            "Thuê ngày đêm"

    ];

    return $names[$type] ?? $type;
}

/*
|--------------------------------------------------------------------------
| Xác định tên các cột thông tin người dùng
|--------------------------------------------------------------------------
*/

$userColumnsStatement = $pdo->query(
    "SHOW COLUMNS FROM users"
);

$userColumns = $userColumnsStatement->fetchAll(
    PDO::FETCH_COLUMN
);

$userNameColumn = null;

foreach (
    [
        "full_name",
        "name",
        "username"
    ] as $possibleNameColumn
) {
    if (
        in_array(
            $possibleNameColumn,
            $userColumns,
            true
        )
    ) {
        $userNameColumn = $possibleNameColumn;
        break;
    }
}

$userPhoneColumn = null;

foreach (
    [
        "phone",
        "phone_number",
        "telephone"
    ] as $possiblePhoneColumn
) {
    if (
        in_array(
            $possiblePhoneColumn,
            $userColumns,
            true
        )
    ) {
        $userPhoneColumn = $possiblePhoneColumn;
        break;
    }
}

$guestNameSql = $userNameColumn

    ? "guest_users." . $userNameColumn

    : "guest_users.email";

$ownerNameSql = $userNameColumn

    ? "owner_users." . $userNameColumn

    : "owner_users.email";

$guestPhoneSql = $userPhoneColumn

    ? "guest_users." . $userPhoneColumn

    : "NULL";

/*
|--------------------------------------------------------------------------
| Bộ lọc
|--------------------------------------------------------------------------
*/

$search = trim(
    $_GET["search"] ?? ""
);

$selectedStatus =
    $_GET["status"] ?? "all";

$allowedStatuses = [

    "all",

    "pending_payment",

    "funds_held",

    "confirmed",

    "completed",

    "cancelled",

    "refunded",

    "disputed"

];

if (
    !in_array(
        $selectedStatus,
        $allowedStatuses,
        true
    )
) {
    $selectedStatus = "all";
}

/*
|--------------------------------------------------------------------------
| Thống kê đơn đặt phòng
|--------------------------------------------------------------------------
*/

$summaryStatement = $pdo->query(
    "SELECT

        COUNT(*) AS total_bookings,

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status = 'funds_held'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS pending_owner_count,

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status = 'confirmed'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS confirmed_count,

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status = 'completed'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS completed_count,

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status = 'disputed'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS disputed_count,

        COALESCE(
            SUM(
                CASE
                    WHEN payments.status = 'held'
                    THEN payments.amount
                    ELSE 0
                END
            ),
            0
        ) AS website_held_amount

     FROM bookings

     LEFT JOIN payments
        ON payments.booking_id = bookings.id"
);

$summary = $summaryStatement->fetch(
    PDO::FETCH_ASSOC
);

/*
|--------------------------------------------------------------------------
| Truy vấn danh sách đơn
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT

        bookings.id,

        bookings.booking_code,

        bookings.booking_type,

        bookings.check_in,

        bookings.check_out,

        bookings.guest_count,

        bookings.total_amount,

        bookings.status,

        bookings.created_at,

        homestays.id AS homestay_id,

        homestays.name AS homestay_name,

        homestays.slug AS homestay_slug,

        homestays.province,

        {$guestNameSql} AS guest_name,

        guest_users.email AS guest_email,

        {$guestPhoneSql} AS guest_phone,

        {$ownerNameSql} AS owner_name,

        owner_users.email AS owner_email,

        payments.status AS payment_status,

        settlements.platform_fee,

        settlements.owner_amount,

        (
            SELECT homestay_images.image_path

            FROM homestay_images

            WHERE homestay_images.homestay_id =
                homestays.id

            ORDER BY

                homestay_images.is_cover DESC,

                homestay_images.sort_order ASC

            LIMIT 1

        ) AS cover_image,

        (
            SELECT refund_requests.id

            FROM refund_requests

            WHERE refund_requests.booking_id =
                bookings.id

              AND refund_requests.status =
                'pending'

            LIMIT 1

        ) AS pending_refund_id

    FROM bookings

    INNER JOIN homestays
        ON homestays.id = bookings.homestay_id

    LEFT JOIN users AS guest_users
        ON guest_users.id = bookings.guest_id

    LEFT JOIN users AS owner_users
        ON owner_users.id = homestays.owner_id

    LEFT JOIN payments
        ON payments.booking_id = bookings.id

    LEFT JOIN settlements
        ON settlements.booking_id = bookings.id

    WHERE 1 = 1
";

$parameters = [];

if (
    $selectedStatus !== "all"
) {
    $sql .= "
        AND bookings.status = :status
    ";

    $parameters["status"] =
        $selectedStatus;
}

if (
    $search !== ""
) {
    $sql .= "
        AND (
            bookings.booking_code LIKE :booking_code

            OR homestays.name LIKE :homestay_name

            OR guest_users.email LIKE :guest_email

            OR owner_users.email LIKE :owner_email
        )
    ";

    $searchValue =
        "%" . $search . "%";

    $parameters["booking_code"] =
        $searchValue;

    $parameters["homestay_name"] =
        $searchValue;

    $parameters["guest_email"] =
        $searchValue;

    $parameters["owner_email"] =
        $searchValue;
}

$sql .= "
    ORDER BY bookings.created_at DESC,
             bookings.id DESC
";

$bookingStatement = $pdo->prepare(
    $sql
);

$bookingStatement->execute(
    $parameters
);

$bookings = $bookingStatement->fetchAll(
    PDO::FETCH_ASSOC
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
        Quản lý đơn đặt phòng - Đi Đâu Đây
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >

    <style>

        .admin-booking-stats {
            display: grid;
            grid-template-columns: repeat(
                4,
                minmax(0, 1fr)
            );
            gap: 16px;
            margin-bottom: 22px;
        }

        .admin-booking-stat {
            min-height: 132px;
            padding: 20px;
            border: 1px solid #e0e7e3;
            border-radius: 14px;
            background: #ffffff;
        }

        .admin-booking-stat small {
            display: block;
            margin-bottom: 14px;
            color: #77827d;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .admin-booking-stat strong {
            display: block;
            color: #14503b;
            font-size: 28px;
        }

        .admin-booking-stat span {
            display: block;
            margin-top: 9px;
            color: #89938e;
            font-size: 12px;
        }

        .admin-booking-notice {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
            padding: 16px 19px;
            border: 1px solid #d9e8de;
            border-radius: 12px;
            color: #275942;
            background: #edf7f0;
            font-size: 13px;
        }

        .admin-booking-notice strong {
            color: #14503b;
        }

        .admin-booking-panel {
            overflow: hidden;
            border: 1px solid #dfe7e2;
            border-radius: 15px;
            background: #ffffff;
        }

        .admin-booking-panel-header {
            padding: 21px 23px;
            border-bottom: 1px solid #e8edea;
        }

        .admin-booking-panel-header h2 {
            margin: 0 0 5px;
            color: #203029;
            font-size: 20px;
        }

        .admin-booking-panel-header p {
            margin: 0;
            color: #87918c;
            font-size: 13px;
        }

        .admin-booking-search {
            display: grid;
            grid-template-columns:
                minmax(0, 1fr)
                220px
                auto;
            gap: 11px;
            padding: 17px 20px;
            border-bottom: 1px solid #e8edea;
        }

        .admin-booking-search input,
        .admin-booking-search select {
            min-height: 44px;
            padding: 0 13px;
            border: 1px solid #dfe7e2;
            border-radius: 9px;
            outline: none;
            color: #405048;
            background: #ffffff;
            font-size: 13px;
        }

        .admin-booking-search button {
            min-height: 44px;
            padding: 0 18px;
            border: none;
            border-radius: 9px;
            color: #ffffff;
            background: #18533f;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
        }

        .admin-booking-table-wrap {
            overflow-x: auto;
        }

        .admin-booking-table {
            width: 100%;
            min-width: 1250px;
            border-collapse: collapse;
        }

        .admin-booking-table th {
            padding: 15px 15px;
            border-bottom: 1px solid #e8edea;
            color: #76817c;
            background: #f7f9f8;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.4px;
            text-align: left;
            text-transform: uppercase;
        }

        .admin-booking-table td {
            padding: 16px 15px;
            border-bottom: 1px solid #edf1ef;
            color: #435049;
            font-size: 12px;
            vertical-align: top;
        }

        .admin-booking-table tbody tr:last-child td {
            border-bottom: none;
        }

        .admin-booking-main-text {
            display: block;
            margin-bottom: 5px;
            color: #184936;
            font-weight: 750;
        }

        .admin-booking-muted {
            display: block;
            margin-top: 4px;
            color: #87918c;
            font-size: 11px;
            line-height: 1.55;
        }

        .admin-booking-home {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 200px;
        }

        .admin-booking-image {
            width: 58px;
            height: 50px;
            flex-shrink: 0;
            border-radius: 8px;
            object-fit: cover;
        }

        .admin-booking-image-empty {
            display: flex;
            width: 58px;
            height: 50px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            color: #84918a;
            background: #edf2ef;
            font-size: 10px;
        }

        .admin-booking-amount {
            display: block;
            margin-bottom: 6px;
            color: #14503b;
            font-size: 14px;
            font-weight: 800;
        }

        .admin-booking-status {
            display: inline-flex;
            margin-bottom: 5px;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 750;
            white-space: nowrap;
        }

        .admin-booking-status-pending_payment {
            color: #8d641e;
            background: #fff4df;
        }

        .admin-booking-status-funds_held {
            color: #98641f;
            background: #fff2df;
        }

        .admin-booking-status-confirmed {
            color: #246847;
            background: #eaf6ee;
        }

        .admin-booking-status-completed {
            color: #345f87;
            background: #edf3fa;
        }

        .admin-booking-status-cancelled {
            color: #a04848;
            background: #fbecec;
        }

        .admin-booking-status-refunded {
            color: #76558d;
            background: #f2ecf8;
        }

        .admin-booking-status-disputed {
            color: #ae4545;
            background: #fdeeee;
        }

        .admin-booking-action {
            display: inline-flex;
            margin-bottom: 7px;
            padding: 8px 10px;
            border-radius: 8px;
            color: #18533f;
            background: #edf5f0;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .admin-booking-action-refund {
            color: #aa4848;
            background: #fceeee;
        }

        .admin-booking-empty {
            padding: 70px 20px;
            color: #86918b;
            text-align: center;
        }

        @media (max-width: 1100px) {

            .admin-booking-stats {
                grid-template-columns: repeat(
                    2,
                    minmax(0, 1fr)
                );
            }

            .admin-booking-search {
                grid-template-columns: 1fr 1fr;
            }

        }

        @media (max-width: 700px) {

            .admin-booking-stats {
                grid-template-columns: 1fr;
            }

            .admin-booking-search {
                grid-template-columns: 1fr;
            }

            .admin-booking-notice {
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
            QUẢN TRỊ HỆ THỐNG
        </div>

        <nav class="sidebar-menu">

            <a href="index.php">
                Tổng quan
            </a>

            <a href="index.php#owner-approval">
                Duyệt tài khoản chủ homestay
            </a>

            <a href="profile_requests.php">
                Yêu cầu sửa hồ sơ
            </a>

            <a href="wallet.php">
                Ví QTV
            </a>

            <a
                href="bookings.php"
                class="active"
            >
                Đơn đặt phòng
            </a>

            <a href="refunds.php">
                Hoàn tiền
            </a>

        </nav>

        <div class="sidebar-bottom">

            <div class="sidebar-user">

                <div class="sidebar-avatar">
                    QT
                </div>

                <div>

                    <strong>

                        <?php

                        echo adminBookingEscape(
                            currentUserName()
                        );

                        ?>

                    </strong>

                    <small>
                        Quản trị viên
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
                    QUẢN LÝ GIAO DỊCH TOÀN HỆ THỐNG
                </p>

                <h1>
                    Đơn đặt phòng
                </h1>

            </div>

            <a
                href="wallet.php"
                class="button button-outline"
            >
                ← Xem ví QTV
            </a>

        </header>

        <section class="admin-booking-stats">

            <article class="admin-booking-stat">

                <small>
                    Tổng đơn đặt phòng
                </small>

                <strong>

                    <?php

                    echo (int) (
                        $summary["total_bookings"]
                    );

                    ?>

                </strong>

                <span>
                    Tất cả đơn trên hệ thống
                </span>

            </article>

            <article class="admin-booking-stat">

                <small>
                    Chờ chủ home xác nhận
                </small>

                <strong>

                    <?php

                    echo (int) (
                        $summary["pending_owner_count"]
                    );

                    ?>

                </strong>

                <span>
                    Khách đã thanh toán
                </span>

            </article>

            <article class="admin-booking-stat">

                <small>
                    Đã hoàn tất
                </small>

                <strong>

                    <?php

                    echo (int) (
                        $summary["completed_count"]
                    );

                    ?>

                </strong>

                <span>
                    Đơn đã được quyết toán
                </span>

            </article>

            <article class="admin-booking-stat">

                <small>
                    Có khiếu nại
                </small>

                <strong>

                    <?php

                    echo (int) (
                        $summary["disputed_count"]
                    );

                    ?>

                </strong>

                <span>
                    Cần QTV kiểm tra
                </span>

            </article>

        </section>

        <div class="admin-booking-notice">

            <span>

                Website đang giữ:

                <strong>

                    <?php

                    echo adminBookingMoney(
                        $summary[
                            "website_held_amount"
                        ]
                    );

                    ?>

                </strong>

            </span>

            <span>

                Đơn đang được xác nhận:

                <strong>

                    <?php

                    echo (int) (
                        $summary[
                            "confirmed_count"
                        ]
                    );

                    ?>

                </strong>

            </span>

        </div>

        <section class="admin-booking-panel">

            <div class="admin-booking-panel-header">

                <h2>
                    Danh sách đơn đặt phòng
                </h2>

                <p>

                    Theo dõi khách thuê, chủ homestay,
                    thanh toán và trạng thái từng đơn.

                </p>

            </div>

            <form
                method="GET"
                class="admin-booking-search"
            >

                <input
                    type="text"
                    name="search"
                    value="<?php

                    echo adminBookingEscape(
                        $search
                    );

                    ?>"
                    placeholder="Tìm theo mã đơn, homestay hoặc email..."
                >

                <select name="status">

                    <option value="all">

                        Tất cả trạng thái

                    </option>

                    <?php foreach (

                        $allowedStatuses

                        as

                        $statusOption

                    ): ?>

                        <?php if (
                            $statusOption === "all"
                        ): ?>

                            <?php continue; ?>

                        <?php endif; ?>

                        <option
                            value="<?php

                            echo adminBookingEscape(
                                $statusOption
                            );

                            ?>"

                            <?php

                            echo $selectedStatus
                                === $statusOption

                                ? "selected"

                                : "";

                            ?>
                        >

                            <?php

                            echo adminBookingEscape(
                                adminBookingStatusName(
                                    $statusOption
                                )
                            );

                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

                <button type="submit">

                    Tìm kiếm

                </button>

            </form>

            <?php if (
                empty($bookings)
            ): ?>

                <div class="admin-booking-empty">

                    Không tìm thấy đơn đặt phòng phù hợp.

                </div>

            <?php else: ?>

                <div class="admin-booking-table-wrap">

                    <table class="admin-booking-table">

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
                                    Chủ homestay
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

                                $bookings

                                as

                                $booking

                            ): ?>

                                <?php

                                $bookingStatus =
                                    $booking["status"];

                                $platformFee =

                                    $booking[
                                        "platform_fee"
                                    ]

                                    !== null

                                    ?

                                    (float) $booking[
                                        "platform_fee"
                                    ]

                                    :

                                    round(
                                        (float) $booking[
                                            "total_amount"
                                        ] * 0.10
                                    );

                                $ownerAmount =

                                    $booking[
                                        "owner_amount"
                                    ]

                                    !== null

                                    ?

                                    (float) $booking[
                                        "owner_amount"
                                    ]

                                    :

                                    (
                                        (float) $booking[
                                            "total_amount"
                                        ]

                                        -

                                        $platformFee
                                    );

                                ?>

                                <tr>

                                    <td>

                                        <span
                                            class="admin-booking-main-text"
                                        >

                                            <?php

                                            echo adminBookingEscape(
                                                $booking[
                                                    "booking_code"
                                                ]
                                            );

                                            ?>

                                        </span>

                                        <span
                                            class="admin-booking-muted"
                                        >

                                            <?php

                                            echo adminBookingDate(
                                                $booking[
                                                    "created_at"
                                                ]
                                            );

                                            ?>

                                        </span>

                                    </td>

                                    <td>

                                        <div
                                            class="admin-booking-home"
                                        >

                                            <?php if (

                                                !empty(
                                                    $booking[
                                                        "cover_image"
                                                    ]
                                                )

                                            ): ?>

                                                <img
                                                    src="../<?php

                                                    echo adminBookingEscape(
                                                        $booking[
                                                            "cover_image"
                                                        ]
                                                    );

                                                    ?>"
                                                    alt="<?php

                                                    echo adminBookingEscape(
                                                        $booking[
                                                            "homestay_name"
                                                        ]
                                                    );

                                                    ?>"
                                                    class="admin-booking-image"
                                                >

                                            <?php else: ?>

                                                <div
                                                    class="admin-booking-image-empty"
                                                >

                                                    Chưa ảnh

                                                </div>

                                            <?php endif; ?>

                                            <div>

                                                <span
                                                    class="admin-booking-main-text"
                                                >

                                                    <?php

                                                    echo adminBookingEscape(
                                                        $booking[
                                                            "homestay_name"
                                                        ]
                                                    );

                                                    ?>

                                                </span>

                                                <span
                                                    class="admin-booking-muted"
                                                >

                                                    <?php

                                                    echo adminBookingEscape(
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
                                            class="admin-booking-main-text"
                                        >

                                            <?php

                                            echo adminBookingEscape(
                                                $booking[
                                                    "guest_name"
                                                ]

                                                ??

                                                "Không có thông tin"
                                            );

                                            ?>

                                        </span>

                                        <span
                                            class="admin-booking-muted"
                                        >

                                            <?php

                                            echo adminBookingEscape(
                                                $booking[
                                                    "guest_email"
                                                ]

                                                ??

                                                ""
                                            );

                                            ?>

                                        </span>

                                        <?php if (

                                            !empty(
                                                $booking[
                                                    "guest_phone"
                                                ]
                                            )

                                        ): ?>

                                            <span
                                                class="admin-booking-muted"
                                            >

                                                <?php

                                                echo adminBookingEscape(
                                                    $booking[
                                                        "guest_phone"
                                                    ]
                                                );

                                                ?>

                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <span
                                            class="admin-booking-main-text"
                                        >

                                            <?php

                                            echo adminBookingEscape(
                                                $booking[
                                                    "owner_name"
                                                ]

                                                ??

                                                "Không có thông tin"
                                            );

                                            ?>

                                        </span>

                                        <span
                                            class="admin-booking-muted"
                                        >

                                            <?php

                                            echo adminBookingEscape(
                                                $booking[
                                                    "owner_email"
                                                ]

                                                ??

                                                ""
                                            );

                                            ?>

                                        </span>

                                    </td>

                                    <td>

                                        <span
                                            class="admin-booking-main-text"
                                        >

                                            <?php

                                            echo adminBookingEscape(
                                                adminBookingTypeName(
                                                    $booking[
                                                        "booking_type"
                                                    ]
                                                )
                                            );

                                            ?>

                                        </span>

                                        <span
                                            class="admin-booking-muted"
                                        >

                                            Nhận:

                                            <?php

                                            echo adminBookingDate(
                                                $booking[
                                                    "check_in"
                                                ]
                                            );

                                            ?>

                                        </span>

                                        <span
                                            class="admin-booking-muted"
                                        >

                                            Trả:

                                            <?php

                                            echo adminBookingDate(
                                                $booking[
                                                    "check_out"
                                                ]
                                            );

                                            ?>

                                        </span>

                                        <span
                                            class="admin-booking-muted"
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

                                    <td>

                                        <span
                                            class="admin-booking-amount"
                                        >

                                            <?php

                                            echo adminBookingMoney(
                                                $booking[
                                                    "total_amount"
                                                ]
                                            );

                                            ?>

                                        </span>

                                        <?php if (

                                            in_array(

                                                $bookingStatus,

                                                [

                                                    "funds_held",

                                                    "confirmed",

                                                    "completed"

                                                ],

                                                true

                                            )

                                        ): ?>

                                            <span
                                                class="admin-booking-muted"
                                            >

                                                QTV:

                                                <?php

                                                echo adminBookingMoney(
                                                    $platformFee
                                                );

                                                ?>

                                            </span>

                                            <span
                                                class="admin-booking-muted"
                                            >

                                                Chủ home:

                                                <?php

                                                echo adminBookingMoney(
                                                    $ownerAmount
                                                );

                                                ?>

                                            </span>

                                        <?php elseif (

                                            $bookingStatus
                                            === "disputed"

                                        ): ?>

                                            <span
                                                class="admin-booking-muted"
                                            >

                                                Tạm giữ, chưa quyết toán

                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <span
                                            class="admin-booking-status admin-booking-status-<?php

                                            echo adminBookingEscape(
                                                $bookingStatus
                                            );

                                            ?>"
                                        >

                                            <?php

                                            echo adminBookingEscape(
                                                adminBookingStatusName(
                                                    $bookingStatus
                                                )
                                            );

                                            ?>

                                        </span>

                                        <span
                                            class="admin-booking-muted"
                                        >

                                            <?php

                                            echo adminBookingEscape(
                                                adminPaymentStatusName(
                                                    $booking[
                                                        "payment_status"
                                                    ]

                                                    ??

                                                    ""
                                                )
                                            );

                                            ?>

                                        </span>

                                    </td>

                                    <td>

                                        <a
                                            href="../homestay.php?slug=<?php

                                            echo urlencode(
                                                $booking[
                                                    "homestay_slug"
                                                ]
                                            );

                                            ?>"
                                            class="admin-booking-action"
                                        >

                                            Xem homestay

                                        </a>

                                        <?php if (

                                            $bookingStatus
                                                === "disputed"

                                            ||

                                            !empty(
                                                $booking[
                                                    "pending_refund_id"
                                                ]
                                            )

                                        ): ?>

                                            <a
                                                href="refunds.php?status=pending"
                                                class="admin-booking-action admin-booking-action-refund"
                                            >

                                                Xử lý khiếu nại

                                            </a>

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