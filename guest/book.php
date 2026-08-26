<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("guest");

date_default_timezone_set("Asia/Ho_Chi_Minh");

$guestId = currentUserId();

$homestayId = (int) (
    $_GET["homestay_id"] ?? 0
);

if ($homestayId <= 0) {
    header("Location: ../index.php");
    exit;
}

/* Tạo mã bảo mật */

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
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
        hp.price_day_weekend,

        (
            SELECT hi.image_path

            FROM homestay_images AS hi

            WHERE hi.homestay_id = h.id

            ORDER BY
                hi.is_cover DESC,
                hi.sort_order ASC

            LIMIT 1
        ) AS cover_image

    FROM homestays AS h

    LEFT JOIN homestay_prices AS hp
        ON hp.homestay_id = h.id

    WHERE h.id = :homestay_id
        AND h.status = 'approved'

    LIMIT 1"
);

$homestayStatement->execute([
    "homestay_id" => $homestayId
]);

$homestay = $homestayStatement->fetch(
    PDO::FETCH_ASSOC
);

if (!$homestay) {
    http_response_code(404);

    exit(
        "<h2>Không tìm thấy homestay.</h2>" .
        "<a href='../index.php'>Quay về trang chủ</a>"
    );
}

/* Định dạng tiền */

function formatBookingPrice($price)
{
    return number_format(
        (float) $price,
        0,
        ",",
        "."
    ) . "đ";
}

/* Các gói thuê */

$bookingTypes = [
    "hourly" => "Thuê theo giờ",
    "overnight" => "Thuê qua đêm",
    "daytime" => "Thuê ban ngày",
    "day_night" => "Thuê ngày đêm"
];

/* Dữ liệu mặc định */

$errors = [];

$selectedType = $_POST["booking_type"]
    ?? "hourly";

$selectedDate = $_POST["booking_date"]
    ?? date("Y-m-d");

$selectedTime = $_POST["start_time"]
    ?? "14:00";

$selectedHours = (int) (
    $_POST["booking_hours"] ?? 2
);

$selectedGuests = (int) (
    $_POST["guest_count"] ?? 2
);

if (
    $selectedGuests >
    (int) $homestay["max_guests"]
) {
    $selectedGuests =
        (int) $homestay["max_guests"];
}

/* Kiểm tra đơn vừa tạo thành công */

$createdBooking = null;

$createdBookingCode = trim(
    $_GET["booking_code"] ?? ""
);

if ($createdBookingCode !== "") {
    $createdBookingStatement = $pdo->prepare(
        "SELECT *

        FROM bookings

        WHERE booking_code = :booking_code
            AND guest_id = :guest_id
            AND homestay_id = :homestay_id

        LIMIT 1"
    );

    $createdBookingStatement->execute([
        "booking_code" => $createdBookingCode,
        "guest_id" => $guestId,
        "homestay_id" => $homestayId
    ]);

    $createdBooking =
        $createdBookingStatement->fetch(
            PDO::FETCH_ASSOC
        );
}

/* Xử lý đặt phòng */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Yêu cầu không hợp lệ.";
    }

    if (!isset($bookingTypes[$selectedType])) {
        $errors[] = "Gói thuê không hợp lệ.";
    }

    if (
        $selectedGuests < 1 ||
        $selectedGuests >
        (int) $homestay["max_guests"]
    ) {
        $errors[] =
            "Số khách vượt quá sức chứa của homestay.";
    }

    $bookingDate = DateTimeImmutable::createFromFormat(
        "!Y-m-d",
        $selectedDate
    );

    if (
        !$bookingDate ||
        $bookingDate->format("Y-m-d") !== $selectedDate
    ) {
        $errors[] = "Ngày đặt phòng không hợp lệ.";
    }

    $checkInDate = null;
    $checkOutDate = null;
    $totalAmount = 0;

    if ($bookingDate && isset($bookingTypes[$selectedType])) {
        /*
         * T2-T5: ngày thường.
         * T6-CN: cuối tuần.
         */

        $weekDay = (int) $bookingDate->format("N");

        $isWeekend = $weekDay >= 5;

        if ($selectedType === "hourly") {
            if (
                !preg_match(
                    "/^(?:[01]\d|2[0-3]):00$/",
                    $selectedTime
                )
            ) {
                $errors[] =
                    "Giờ nhận phòng không hợp lệ.";
            }

            if (
                $selectedHours < 2 ||
                $selectedHours > 12
            ) {
                $errors[] =
                    "Thuê theo giờ phải từ 2 đến 12 giờ.";
            }

            if (empty($errors)) {
                $hour = (int) substr(
                    $selectedTime,
                    0,
                    2
                );

                $checkInDate = $bookingDate->setTime(
                    $hour,
                    0
                );

                $checkOutDate = $checkInDate->modify(
                    "+" . $selectedHours . " hours"
                );

                if ($selectedHours === 2) {
                    $totalAmount = (float) $homestay[
                        "price_first_2_hours"
                    ];
                } elseif ($selectedHours === 3) {
                    $totalAmount =
                        (float) $homestay[
                            "price_first_2_hours"
                        ] +
                        (float) $homestay[
                            "price_extra_hour"
                        ];
                } else {
                    $totalAmount =
                        (float) $homestay[
                            "price_combo_4_hours"
                        ] +
                        (
                            $selectedHours - 4
                        ) *
                        (float) $homestay[
                            "price_extra_hour"
                        ];
                }
            }
        }

        if ($selectedType === "overnight") {
            $checkInDate = $bookingDate->setTime(
                22,
                0
            );

            $checkOutDate = $bookingDate
                ->modify("+1 day")
                ->setTime(10, 0);

            $totalAmount = $isWeekend
                ? (float) $homestay[
                    "price_overnight_weekend"
                ]
                : (float) $homestay[
                    "price_overnight_weekday"
                ];
        }

        if ($selectedType === "daytime") {
            $checkInDate = $bookingDate->setTime(
                11,
                0
            );

            $checkOutDate = $bookingDate->setTime(
                21,
                0
            );

            $totalAmount = $isWeekend
                ? (float) $homestay[
                    "price_day_weekend"
                ]
                : (float) $homestay[
                    "price_day_weekday"
                ];
        }

        if ($selectedType === "day_night") {
            $checkInDate = $bookingDate->setTime(
                15,
                0
            );

            $checkOutDate = $bookingDate
                ->modify("+1 day")
                ->setTime(10, 0);

            $totalAmount = $isWeekend
                ? (float) $homestay[
                    "price_day_night_weekend"
                ]
                : (float) $homestay[
                    "price_day_night_weekday"
                ];
        }
    }

    if ($checkInDate && $checkOutDate) {
        $now = new DateTimeImmutable();

        if ($checkInDate <= $now) {
            $errors[] =
                "Thời gian nhận phòng phải sau thời điểm hiện tại.";
        }

        if ($checkOutDate <= $checkInDate) {
            $errors[] =
                "Thời gian trả phòng không hợp lệ.";
        }
    }

    if ($totalAmount <= 0) {
        $errors[] =
            "Chưa có bảng giá phù hợp cho gói thuê này.";
    }

    if (
        empty($errors) &&
        $checkInDate &&
        $checkOutDate
    ) {
        try {
            $pdo->beginTransaction();

            /*
             * Khóa bản ghi homestay để tránh
             * hai khách đặt trùng cùng một lúc.
             */

            $lockHomestayStatement = $pdo->prepare(
                "SELECT id

                FROM homestays

                WHERE id = :homestay_id

                FOR UPDATE"
            );

            $lockHomestayStatement->execute([
                "homestay_id" => $homestayId
            ]);

            /* Kiểm tra trùng thời gian */

            $overlapStatement = $pdo->prepare(
                "SELECT id

                FROM bookings

                WHERE homestay_id = :homestay_id

                    AND status IN (
                        'pending_payment',
                        'funds_held',
                        'confirmed',
                        'disputed'
                    )

                    AND check_in < :new_check_out
                    AND check_out > :new_check_in

                LIMIT 1"
            );

            $overlapStatement->execute([
                "homestay_id" => $homestayId,

                "new_check_in" =>
                    $checkInDate->format(
                        "Y-m-d H:i:s"
                    ),

                "new_check_out" =>
                    $checkOutDate->format(
                        "Y-m-d H:i:s"
                    )
            ]);

            if ($overlapStatement->fetch()) {
                $pdo->rollBack();

                $errors[] =
                    "Khung giờ này đã có người đặt. " .
                    "Vui lòng chọn thời gian khác.";
            } else {
                /* Tạo mã đơn */

                $bookingCode =
                    "DDD" .
                    date("ymdHis") .
                    strtoupper(
                        bin2hex(random_bytes(3))
                    );

                /* Lưu đơn đặt phòng */

                $insertBookingStatement = $pdo->prepare(
                    "INSERT INTO bookings (
                        booking_code,
                        guest_id,
                        homestay_id,
                        booking_type,
                        check_in,
                        check_out,
                        guest_count,
                        total_amount,
                        status
                    ) VALUES (
                        :booking_code,
                        :guest_id,
                        :homestay_id,
                        :booking_type,
                        :check_in,
                        :check_out,
                        :guest_count,
                        :total_amount,
                        'pending_payment'
                    )"
                );

                $insertBookingStatement->execute([
                    "booking_code" => $bookingCode,

                    "guest_id" => $guestId,

                    "homestay_id" => $homestayId,

                    "booking_type" => $selectedType,

                    "check_in" =>
                        $checkInDate->format(
                            "Y-m-d H:i:s"
                        ),

                    "check_out" =>
                        $checkOutDate->format(
                            "Y-m-d H:i:s"
                        ),

                    "guest_count" =>
                        $selectedGuests,

                    "total_amount" =>
                        $totalAmount
                ]);

                $pdo->commit();

                header(
                    "Location: book.php?" .
                    http_build_query([
                        "homestay_id" =>
                            $homestayId,

                        "booking_code" =>
                            $bookingCode
                    ])
                );

                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] =
                "Không thể tạo đơn đặt phòng. " .
                "Vui lòng thử lại.";
        }
    }
}

/* Lấy các khoảng thời gian đã được đặt */

$reservedStatement = $pdo->prepare(
    "SELECT
        check_in,
        check_out

    FROM bookings

    WHERE homestay_id = :homestay_id

        AND status IN (
            'pending_payment',
            'funds_held',
            'confirmed',
            'disputed'
        )

        AND check_out > :current_time

    ORDER BY check_in ASC

    LIMIT 30"
);

$reservedStatement->execute([
    "homestay_id" => $homestayId,

    "current_time" =>
        date("Y-m-d H:i:s")
]);

$reservedBookings = $reservedStatement->fetchAll(
    PDO::FETCH_ASSOC
);

/* Bảng giá cho JavaScript */

$javascriptPrices = [
    "first2Hours" => (float) $homestay[
        "price_first_2_hours"
    ],

    "combo4Hours" => (float) $homestay[
        "price_combo_4_hours"
    ],

    "extraHour" => (float) $homestay[
        "price_extra_hour"
    ],

    "overnightWeekday" => (float) $homestay[
        "price_overnight_weekday"
    ],

    "overnightWeekend" => (float) $homestay[
        "price_overnight_weekend"
    ],

    "dayNightWeekday" => (float) $homestay[
        "price_day_night_weekday"
    ],

    "dayNightWeekend" => (float) $homestay[
        "price_day_night_weekend"
    ],

    "dayWeekday" => (float) $homestay[
        "price_day_weekday"
    ],

    "dayWeekend" => (float) $homestay[
        "price_day_weekend"
    ]
];

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
        Đặt phòng -
        <?php
        echo htmlspecialchars(
            $homestay["name"]
        );
        ?>
    </title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #243a31;
            background: #f5f8f6;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .booking-header {
            padding: 19px 0;
            background: #ffffff;
            border-bottom: 1px solid #e4ebe7;
        }

        .booking-container {
            width: min(1100px, calc(100% - 36px));
            margin: 0 auto;
        }

        .booking-header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .booking-logo {
            color: #174f40;
            font-size: 25px;
            font-weight: 900;
            text-decoration: none;
        }

        .back-link {
            color: #174f40;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
        }

        .booking-main {
            padding: 42px 0 65px;
        }

        .booking-title {
            margin: 0 0 8px;
            color: #193c31;
            font-size: 36px;
        }

        .booking-subtitle {
            margin: 0 0 27px;
            color: #74817a;
            font-size: 14px;
        }

        .booking-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 350px;
            align-items: start;
            gap: 24px;
        }

        .booking-panel,
        .booking-summary {
            padding: 23px;
            background: #ffffff;
            border: 1px solid #e1e9e5;
            border-radius: 17px;
        }

        .booking-panel h2 {
            margin: 0 0 19px;
            color: #193c31;
            font-size: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(
                2,
                minmax(0, 1fr)
            );
            gap: 18px;
        }

        .form-group {
            min-width: 0;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #40564b;
            font-size: 13px;
            font-weight: 700;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            height: 47px;
            padding: 0 13px;
            color: #253c32;
            background: #ffffff;
            border: 1px solid #dce5e0;
            border-radius: 10px;
            font-size: 14px;
        }

        .time-preview {
            margin-top: 20px;
            padding: 16px;
            background: #f2f7f4;
            border-radius: 12px;
        }

        .time-preview-row {
            padding: 7px 0;
            display: flex;
            justify-content: space-between;
            gap: 15px;
            font-size: 13px;
        }

        .time-preview-row span {
            color: #6d7b73;
        }

        .time-preview-row strong {
            color: #1d493a;
            text-align: right;
        }

        .total-row {
            margin-top: 8px;
            padding-top: 13px;
            border-top: 1px solid #dce7e1;
        }

        .total-row strong {
            font-size: 19px;
        }

        .submit-button {
            width: 100%;
            height: 49px;
            margin-top: 18px;
            color: #ffffff;
            background: #1d5947;
            border: none;
            border-radius: 11px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 750;
        }

        .submit-button:disabled {
            background: #aab7b0;
            cursor: not-allowed;
        }

        .alert-error {
            margin-bottom: 18px;
            padding: 14px 16px;
            color: #98342e;
            background: #fff0ee;
            border-radius: 11px;
            font-size: 13px;
            line-height: 1.8;
        }

        .alert-success {
            padding: 18px;
            color: #215344;
            background: #edf7f1;
            border-radius: 12px;
            line-height: 1.8;
        }

        .success-information {
            margin-top: 18px;
            padding-top: 15px;
            border-top: 1px solid #dce9e2;
        }

        .summary-image {
            width: 100%;
            height: 190px;
            display: block;
            object-fit: cover;
            border-radius: 12px;
        }

        .booking-summary h3 {
            margin: 17px 0 7px;
            color: #193c31;
            font-size: 21px;
        }

        .summary-location {
            margin: 0 0 15px;
            color: #75817a;
            font-size: 13px;
        }

        .summary-line {
            padding: 11px 0;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            border-top: 1px solid #e8eeeb;
            font-size: 13px;
        }

        .summary-line span {
            color: #718078;
        }

        .summary-line strong {
            color: #24463a;
            text-align: right;
        }

        .reserved-list {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid #e7eeea;
        }

        .reserved-list h3 {
            margin: 0 0 10px;
            color: #44594f;
            font-size: 14px;
        }

        .reserved-item {
            margin-top: 8px;
            padding: 10px;
            color: #7a5146;
            background: #fff5f1;
            border-radius: 8px;
            font-size: 12px;
        }

        .booking-message {
            margin-top: 12px;
            color: #9a4637;
            font-size: 12px;
        }

        @media (max-width: 820px) {
            .booking-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 560px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .booking-title {
                font-size: 29px;
            }

            .booking-header-inner {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<header class="booking-header">

    <div class="booking-container booking-header-inner">

        <a
            href="../index.php"
            class="booking-logo"
        >
            Đi Đâu Đây
        </a>

        <a
            href="../homestay.php?slug=<?php
            echo urlencode(
                $homestay["slug"]
            );
            ?>"
            class="back-link"
        >
            ← Quay lại homestay
        </a>

    </div>

</header>

<main class="booking-main">

    <div class="booking-container">

        <h1 class="booking-title">
            Đặt phòng
        </h1>

        <p class="booking-subtitle">
            Chọn thời gian phù hợp cho chuyến đi của bạn.
        </p>

        <div class="booking-layout">

            <section class="booking-panel">

                <?php if ($createdBooking): ?>

                    <div class="alert-success">

                        <strong>
                            Đặt phòng thành công!
                        </strong>

                        <div class="success-information">

                            <div>
                                Mã đơn:

                                <strong>
                                    <?php
                                    echo htmlspecialchars(
                                        $createdBooking[
                                            "booking_code"
                                        ]
                                    );
                                    ?>
                                </strong>
                            </div>

                            <div>
                                Nhận phòng:

                                <?php
                                echo date(
                                    "H:i d/m/Y",
                                    strtotime(
                                        $createdBooking[
                                            "check_in"
                                        ]
                                    )
                                );
                                ?>
                            </div>

                            <div>
                                Trả phòng:

                                <?php
                                echo date(
                                    "H:i d/m/Y",
                                    strtotime(
                                        $createdBooking[
                                            "check_out"
                                        ]
                                    )
                                );
                                ?>
                            </div>

                            <div>
                                Tổng tiền:

                                <strong>
                                    <?php
                                    echo formatBookingPrice(
                                        $createdBooking[
                                            "total_amount"
                                        ]
                                    );
                                    ?>
                                </strong>
                            </div>

                            <div>
                                Trạng thái:

                                <strong>
                                    Chờ thanh toán
                                </strong>
                            </div>

                            <a
                                href="payment.php?booking_code=<?php

                                echo urlencode(
                                $createdBooking["booking_code"]
                                );

                                ?>"

                                style="
                                    display: block;
                                    margin-top: 20px;
                                    padding: 13px;
                                    border-radius: 10px;
                                    color: white;
                                    background: #1b5947;
                                    text-align: center;
                                    text-decoration: none;
                                    font-weight: 700;
                                "
                            >
                                Thanh toán ngay
                            </a>

                        </div>

                    </div>

                <?php else: ?>

                    <h2>
                        Thông tin đặt phòng
                    </h2>

                    <?php if (!empty($errors)): ?>

                        <div class="alert-error">

                            <?php foreach (
                                $errors as $error
                            ): ?>

                                <div>
                                    •
                                    <?php
                                    echo htmlspecialchars(
                                        $error
                                    );
                                    ?>
                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php
                            echo htmlspecialchars(
                                $_SESSION["csrf_token"]
                            );
                            ?>"
                        >

                        <div class="form-grid">

                            <div class="form-group full-width">

                                <label for="booking_type">
                                    Gói thuê
                                </label>

                                <select
                                    id="booking_type"
                                    name="booking_type"
                                    required
                                >

                                    <?php foreach (
                                        $bookingTypes
                                        as $typeValue
                                        => $typeLabel
                                    ): ?>

                                        <option
                                            value="<?php
                                            echo $typeValue;
                                            ?>"

                                            <?php
                                            echo $selectedType
                                                === $typeValue
                                                ? "selected"
                                                : "";
                                            ?>
                                        >

                                            <?php
                                            echo htmlspecialchars(
                                                $typeLabel
                                            );
                                            ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="form-group">

                                <label for="booking_date">
                                    Ngày nhận phòng
                                </label>

                                <input
                                    type="date"
                                    id="booking_date"
                                    name="booking_date"
                                    min="<?php
                                    echo date("Y-m-d");
                                    ?>"
                                    value="<?php
                                    echo htmlspecialchars(
                                        $selectedDate
                                    );
                                    ?>"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label for="guest_count">
                                    Số khách
                                </label>

                                <select
                                    id="guest_count"
                                    name="guest_count"
                                    required
                                >

                                    <?php for (
                                        $number = 1;
                                        $number <=
                                            (int) $homestay[
                                                "max_guests"
                                            ];
                                        $number++
                                    ): ?>

                                        <option
                                            value="<?php
                                            echo $number;
                                            ?>"

                                            <?php
                                            echo $selectedGuests
                                                === $number
                                                ? "selected"
                                                : "";
                                            ?>
                                        >

                                            <?php
                                            echo $number;
                                            ?>
                                            khách

                                        </option>

                                    <?php endfor; ?>

                                </select>

                            </div>

                            <div
                                class="form-group hourly-field"
                            >

                                <label for="start_time">
                                    Giờ nhận phòng
                                </label>

                                <select
                                    id="start_time"
                                    name="start_time"
                                >

                                    <?php for (
                                        $hour = 0;
                                        $hour <= 23;
                                        $hour++
                                    ): ?>

                                        <?php

                                        $hourValue = str_pad(
                                            (string) $hour,
                                            2,
                                            "0",
                                            STR_PAD_LEFT
                                        ) . ":00";

                                        ?>

                                        <option
                                            value="<?php
                                            echo $hourValue;
                                            ?>"

                                            <?php
                                            echo $selectedTime
                                                === $hourValue
                                                ? "selected"
                                                : "";
                                            ?>
                                        >

                                            <?php
                                            echo $hourValue;
                                            ?>

                                        </option>

                                    <?php endfor; ?>

                                </select>

                            </div>

                            <div
                                class="form-group hourly-field"
                            >

                                <label for="booking_hours">
                                    Thời gian thuê
                                </label>

                                <select
                                    id="booking_hours"
                                    name="booking_hours"
                                >

                                    <?php for (
                                        $hours = 2;
                                        $hours <= 12;
                                        $hours++
                                    ): ?>

                                        <option
                                            value="<?php
                                            echo $hours;
                                            ?>"

                                            <?php
                                            echo $selectedHours
                                                === $hours
                                                ? "selected"
                                                : "";
                                            ?>
                                        >

                                            <?php
                                            echo $hours;
                                            ?>
                                            giờ

                                        </option>

                                    <?php endfor; ?>

                                </select>

                            </div>

                        </div>

                        <div class="time-preview">

                            <div class="time-preview-row">

                                <span>
                                    Nhận phòng
                                </span>

                                <strong id="preview-check-in">
                                    —
                                </strong>

                            </div>

                            <div class="time-preview-row">

                                <span>
                                    Trả phòng
                                </span>

                                <strong id="preview-check-out">
                                    —
                                </strong>

                            </div>

                            <div
                                class="time-preview-row total-row"
                            >

                                <span>
                                    Tổng thanh toán
                                </span>

                                <strong id="preview-total">
                                    —
                                </strong>

                            </div>

                        </div>

                        <p
                            id="availability-message"
                            class="booking-message"
                            hidden
                        >
                            Khung giờ này đã có khách đặt.
                        </p>

                        <button
                            type="submit"
                            id="submit-booking"
                            class="submit-button"
                        >
                            Xác nhận đặt phòng
                        </button>

                    </form>

                    <?php if (!empty($reservedBookings)): ?>

                        <div class="reserved-list">

                            <h3>
                                Các khung giờ đã được đặt
                            </h3>

                            <?php foreach (
                                $reservedBookings
                                as $reservedBooking
                            ): ?>

                                <div class="reserved-item">

                                    <?php

                                    echo date(
                                        "H:i d/m/Y",
                                        strtotime(
                                            $reservedBooking[
                                                "check_in"
                                            ]
                                        )
                                    );

                                    ?>

                                    →

                                    <?php

                                    echo date(
                                        "H:i d/m/Y",
                                        strtotime(
                                            $reservedBooking[
                                                "check_out"
                                            ]
                                        )
                                    );

                                    ?>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </section>

            <aside class="booking-summary">

                <?php if (
                    !empty(
                        $homestay["cover_image"]
                    )
                ): ?>

                    <img
                        src="../<?php
                        echo htmlspecialchars(
                            $homestay["cover_image"]
                        );
                        ?>"

                        alt="<?php
                        echo htmlspecialchars(
                            $homestay["name"]
                        );
                        ?>"

                        class="summary-image"
                    >

                <?php endif; ?>

                <h3>

                    <?php
                    echo htmlspecialchars(
                        $homestay["name"]
                    );
                    ?>

                </h3>

                <p class="summary-location">

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

                <div class="summary-line">

                    <span>
                        Sức chứa
                    </span>

                    <strong>

                        Tối đa

                        <?php
                        echo (int) $homestay[
                            "max_guests"
                        ];
                        ?>

                        khách

                    </strong>

                </div>

                <div class="summary-line">

                    <span>
                        2 giờ đầu
                    </span>

                    <strong>

                        <?php
                        echo formatBookingPrice(
                            $homestay[
                                "price_first_2_hours"
                            ]
                        );
                        ?>

                    </strong>

                </div>

                <div class="summary-line">

                    <span>
                        Combo 4 giờ
                    </span>

                    <strong>

                        <?php
                        echo formatBookingPrice(
                            $homestay[
                                "price_combo_4_hours"
                            ]
                        );
                        ?>

                    </strong>

                </div>

                <div class="summary-line">

                    <span>
                        Thêm mỗi giờ
                    </span>

                    <strong>

                        <?php
                        echo formatBookingPrice(
                            $homestay[
                                "price_extra_hour"
                            ]
                        );
                        ?>

                    </strong>

                </div>

            </aside>

        </div>

    </div>

</main>

<script>

    const bookingPrices = <?php

        echo json_encode(
            $javascriptPrices,
            JSON_UNESCAPED_UNICODE
        );

    ?>;

    const reservedBookings = <?php

        echo json_encode(
            $reservedBookings,
            JSON_UNESCAPED_UNICODE
        );

    ?>;

    const bookingTypeField =
        document.getElementById(
            "booking_type"
        );

    const bookingDateField =
        document.getElementById(
            "booking_date"
        );

    const startTimeField =
        document.getElementById(
            "start_time"
        );

    const bookingHoursField =
        document.getElementById(
            "booking_hours"
        );

    const previewCheckIn =
        document.getElementById(
            "preview-check-in"
        );

    const previewCheckOut =
        document.getElementById(
            "preview-check-out"
        );

    const previewTotal =
        document.getElementById(
            "preview-total"
        );

    const availabilityMessage =
        document.getElementById(
            "availability-message"
        );

    const submitBooking =
        document.getElementById(
            "submit-booking"
        );

    function formatMoney(value) {
        return new Intl.NumberFormat(
            "vi-VN"
        ).format(value) + "đ";
    }

    function formatDateTime(date) {
        return new Intl.DateTimeFormat(
            "vi-VN",
            {
                hour: "2-digit",
                minute: "2-digit",
                day: "2-digit",
                month: "2-digit",
                year: "numeric"
            }
        ).format(date);
    }

    function hasBookingOverlap(checkIn, checkOut) {
        return reservedBookings.some(
            function (booking) {
                const reservedStart = new Date(
                    booking.check_in.replace(
                        " ",
                        "T"
                    )
                );

                const reservedEnd = new Date(
                    booking.check_out.replace(
                        " ",
                        "T"
                    )
                );

                return (
                    checkIn < reservedEnd &&
                    checkOut > reservedStart
                );
            }
        );
    }

    function updateBookingPreview() {
        if (
            !bookingTypeField ||
            !bookingDateField ||
            !bookingDateField.value
        ) {
            return;
        }

        const selectedType =
            bookingTypeField.value;

        const dateValue =
            bookingDateField.value;

        const date = new Date(
            dateValue + "T00:00:00"
        );

        const weekDay = date.getDay();

        const isWeekend =
            weekDay === 5 ||
            weekDay === 6 ||
            weekDay === 0;

        document
            .querySelectorAll(".hourly-field")
            .forEach(
                function (field) {
                    field.style.display =
                        selectedType === "hourly"
                            ? ""
                            : "none";
                }
            );

        let checkIn;
        let checkOut;
        let total = 0;

        if (selectedType === "hourly") {
            const selectedTime =
                startTimeField.value;

            const hours = Number(
                bookingHoursField.value
            );

            checkIn = new Date(
                dateValue +
                "T" +
                selectedTime +
                ":00"
            );

            checkOut = new Date(
                checkIn.getTime() +
                hours * 60 * 60 * 1000
            );

            if (hours === 2) {
                total =
                    bookingPrices.first2Hours;
            } else if (hours === 3) {
                total =
                    bookingPrices.first2Hours +
                    bookingPrices.extraHour;
            } else {
                total =
                    bookingPrices.combo4Hours +
                    (
                        hours - 4
                    ) *
                    bookingPrices.extraHour;
            }
        }

        if (selectedType === "overnight") {
            checkIn = new Date(
                dateValue + "T22:00:00"
            );

            checkOut = new Date(checkIn);

            checkOut.setDate(
                checkOut.getDate() + 1
            );

            checkOut.setHours(10, 0, 0, 0);

            total = isWeekend
                ? bookingPrices.overnightWeekend
                : bookingPrices.overnightWeekday;
        }

        if (selectedType === "daytime") {
            checkIn = new Date(
                dateValue + "T11:00:00"
            );

            checkOut = new Date(
                dateValue + "T21:00:00"
            );

            total = isWeekend
                ? bookingPrices.dayWeekend
                : bookingPrices.dayWeekday;
        }

        if (selectedType === "day_night") {
            checkIn = new Date(
                dateValue + "T15:00:00"
            );

            checkOut = new Date(checkIn);

            checkOut.setDate(
                checkOut.getDate() + 1
            );

            checkOut.setHours(10, 0, 0, 0);

            total = isWeekend
                ? bookingPrices.dayNightWeekend
                : bookingPrices.dayNightWeekday;
        }

        if (!checkIn || !checkOut) {
            return;
        }

        previewCheckIn.textContent =
            formatDateTime(checkIn);

        previewCheckOut.textContent =
            formatDateTime(checkOut);

        previewTotal.textContent =
            formatMoney(total);

        const hasOverlap = hasBookingOverlap(
            checkIn,
            checkOut
        );

        const isPast =
            checkIn <= new Date();

        availabilityMessage.hidden =
            !hasOverlap && !isPast;

        if (hasOverlap) {
            availabilityMessage.textContent =
                "Khung giờ này đã có khách đặt.";
        } else if (isPast) {
            availabilityMessage.textContent =
                "Vui lòng chọn thời gian nhận phòng trong tương lai.";
        }

        submitBooking.disabled =
            hasOverlap || isPast || total <= 0;
    }

    if (bookingTypeField) {
        bookingTypeField.addEventListener(
            "change",
            updateBookingPreview
        );

        bookingDateField.addEventListener(
            "change",
            updateBookingPreview
        );

        startTimeField.addEventListener(
            "change",
            updateBookingPreview
        );

        bookingHoursField.addEventListener(
            "change",
            updateBookingPreview
        );

        updateBookingPreview();
    }

</script>

</body>

</html>