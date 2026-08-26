<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("guest");

date_default_timezone_set("Asia/Ho_Chi_Minh");

$guestId = currentUserId();

$bookingCode = trim(
    $_GET["booking_code"] ?? ""
);

if ($bookingCode === "") {
    header("Location: ../index.php");
    exit;
}

/* Tạo mã bảo mật */

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/* Các phương thức thanh toán */

$paymentMethods = [
    "bank_transfer" => "Chuyển khoản ngân hàng",
    "momo" => "Ví MoMo",
    "vnpay" => "VNPay"
];

$errors = [];

$selectedMethod = $_POST["payment_method"]
    ?? "bank_transfer";

/* Lấy thông tin đơn đặt phòng */

$bookingStatement = $pdo->prepare(
    "SELECT
        b.id,
        b.booking_code,
        b.guest_id,
        b.homestay_id,
        b.booking_type,
        b.check_in,
        b.check_out,
        b.guest_count,
        b.total_amount,
        b.status,

        h.name AS homestay_name,
        h.slug AS homestay_slug,
        h.address AS homestay_address,
        h.province AS homestay_province,

        p.transaction_code,
        p.payment_method AS payment_method,
        p.status AS payment_status,

        (
            SELECT hi.image_path

            FROM homestay_images AS hi

            WHERE hi.homestay_id = h.id

            ORDER BY
                hi.is_cover DESC,
                hi.sort_order ASC

            LIMIT 1
        ) AS cover_image

    FROM bookings AS b

    INNER JOIN homestays AS h
        ON h.id = b.homestay_id

    LEFT JOIN payments AS p
        ON p.booking_id = b.id

    WHERE b.booking_code = :booking_code
        AND b.guest_id = :guest_id

    LIMIT 1"
);

$bookingStatement->execute([
    "booking_code" => $bookingCode,
    "guest_id" => $guestId
]);

$booking = $bookingStatement->fetch(
    PDO::FETCH_ASSOC
);

if (!$booking) {
    http_response_code(404);

    exit(
        "<h2>Không tìm thấy đơn đặt phòng.</h2>" .
        "<a href='../index.php'>Quay về trang chủ</a>"
    );
}

/* Định dạng tiền */

function formatPaymentMoney($amount)
{
    return number_format(
        (float) $amount,
        0,
        ",",
        "."
    ) . "đ";
}

/* Đơn đã thanh toán hay chưa */

$isPaid = in_array(
    $booking["status"],
    [
        "funds_held",
        "confirmed",
        "completed"
    ],
    true
);

/* Xử lý xác nhận thanh toán */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    !$isPaid
) {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Yêu cầu thanh toán không hợp lệ.";
    }

    if (!isset($paymentMethods[$selectedMethod])) {
        $errors[] =
            "Phương thức thanh toán không hợp lệ.";
    }

    if ($booking["status"] !== "pending_payment") {
        $errors[] =
            "Đơn hàng không còn ở trạng thái chờ thanh toán.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            /* Khóa đơn để tránh thanh toán hai lần */

            $lockBookingStatement = $pdo->prepare(
                "SELECT
                    id,
                    total_amount,
                    status

                FROM bookings

                WHERE id = :booking_id
                    AND guest_id = :guest_id

                LIMIT 1

                FOR UPDATE"
            );

            $lockBookingStatement->execute([
                "booking_id" => $booking["id"],
                "guest_id" => $guestId
            ]);

            $lockedBooking =
                $lockBookingStatement->fetch(
                    PDO::FETCH_ASSOC
                );

            if (
                !$lockedBooking ||
                $lockedBooking["status"]
                    !== "pending_payment"
            ) {
                throw new RuntimeException(
                    "Đơn hàng đã được xử lý hoặc không hợp lệ."
                );
            }

            /* Mã giao dịch */

            $transactionCode =
                "PAY" .
                date("ymdHis") .
                strtoupper(
                    bin2hex(
                        random_bytes(3)
                    )
                );

            /* Kiểm tra đơn đã có bản ghi thanh toán chưa */

            $existingPaymentStatement = $pdo->prepare(
                "SELECT id

                FROM payments

                WHERE booking_id = :booking_id

                LIMIT 1

                FOR UPDATE"
            );

            $existingPaymentStatement->execute([
                "booking_id" => $booking["id"]
            ]);

            $existingPayment =
                $existingPaymentStatement->fetch(
                    PDO::FETCH_ASSOC
                );

            if ($existingPayment) {
                    $updatePaymentStatement = $pdo->prepare(
                        "UPDATE payments

                        SET
                            transaction_code = :transaction_code,
                            payment_method = :method,
                            amount = :amount,
                            status = 'held',
                            paid_at = NOW()

                        WHERE id = :payment_id"
                    );

                $updatePaymentStatement->execute([
                    "transaction_code" =>
                        $transactionCode,

                    "method" =>
                        $selectedMethod,

                    "amount" =>
                        $lockedBooking["total_amount"],

                    "payment_id" =>
                        $existingPayment["id"]
                ]);
            } else {
                    $insertPaymentStatement = $pdo->prepare(
                        "INSERT INTO payments (
                            booking_id,
                            transaction_code,
                            payment_method,
                            amount,
                            status,
                            paid_at
                        ) VALUES (
                            :booking_id,
                            :transaction_code,
                            :method,
                            :amount,
                            'held',
                            NOW()
                        )"
                    );

                $insertPaymentStatement->execute([
                    "booking_id" =>
                        $booking["id"],

                    "transaction_code" =>
                        $transactionCode,

                    "method" =>
                        $selectedMethod,

                    "amount" =>
                        $lockedBooking["total_amount"]
                ]);
            }

            /*
             * Website đang giữ tiền.
             * Chưa cộng vào ví QTV hoặc chủ homestay.
             */

            $updateBookingStatement = $pdo->prepare(
                "UPDATE bookings

                SET status = 'funds_held'

                WHERE id = :booking_id"
            );

            $updateBookingStatement->execute([
                "booking_id" => $booking["id"]
            ]);

            $pdo->commit();

            header(
                "Location: payment.php?" .
                http_build_query([
                    "booking_code" =>
                        $bookingCode,

                    "success" =>
                        1
                ])
            );

            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] =
                "Không thể thực hiện thanh toán. " .
                "Vui lòng thử lại.";
        }
    }
}

/* Số tiền dự kiến được chia sau khi kỳ nghỉ hoàn tất */

$totalAmount = (float) $booking["total_amount"];

$adminAmount = round(
    $totalAmount * 0.10
);

$ownerAmount =
    $totalAmount - $adminAmount;

/* Tên gói đặt phòng */

$bookingTypeLabels = [
    "hourly" => "Thuê theo giờ",
    "overnight" => "Thuê qua đêm",
    "daytime" => "Thuê ban ngày",
    "day_night" => "Thuê ngày đêm"
];

$bookingTypeLabel = $bookingTypeLabels[
    $booking["booking_type"]
] ?? "Đặt homestay";

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
        Thanh toán -
        <?php
        echo htmlspecialchars(
            $booking["homestay_name"]
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

        .payment-container {
            width: min(1100px, calc(100% - 36px));
            margin: 0 auto;
        }

        .payment-header {
            padding: 20px 0;
            background: #ffffff;
            border-bottom: 1px solid #e3ebe6;
        }

        .payment-header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .payment-logo {
            color: #174f40;
            font-size: 25px;
            font-weight: 900;
            text-decoration: none;
        }

        .back-link {
            color: #174f40;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .payment-main {
            padding: 42px 0 70px;
        }

        .page-title {
            margin: 0 0 8px;
            color: #193c31;
            font-size: 36px;
        }

        .page-subtitle {
            margin: 0 0 26px;
            color: #74817a;
            font-size: 14px;
        }

        .payment-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 350px;
            align-items: start;
            gap: 24px;
        }

        .payment-panel,
        .payment-summary {
            padding: 24px;
            background: #ffffff;
            border: 1px solid #e1e9e5;
            border-radius: 18px;
        }

        .payment-panel h2 {
            margin: 0 0 20px;
            color: #1b4437;
            font-size: 21px;
        }

        .payment-methods {
            display: grid;
            gap: 12px;
        }

        .payment-method {
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 13px;
            border: 1px solid #dfe7e3;
            border-radius: 12px;
            cursor: pointer;
        }

        .payment-method:hover {
            border-color: #1c644f;
            background: #f4f8f6;
        }

        .payment-method input {
            accent-color: #1b5947;
        }

        .payment-method strong {
            display: block;
            color: #29463a;
            font-size: 14px;
        }

        .payment-method small {
            display: block;
            margin-top: 4px;
            color: #78847e;
            font-size: 12px;
        }

        .bank-information {
            margin-top: 19px;
            padding: 17px;
            background: #f3f7f5;
            border-radius: 12px;
        }

        .bank-information h3 {
            margin: 0 0 13px;
            color: #244a3c;
            font-size: 15px;
        }

        .information-row {
            padding: 8px 0;
            display: flex;
            justify-content: space-between;
            gap: 15px;
            font-size: 13px;
        }

        .information-row span {
            color: #718079;
        }

        .information-row strong {
            color: #27473c;
            text-align: right;
        }

        .payment-button {
            width: 100%;
            height: 49px;
            margin-top: 19px;
            color: #ffffff;
            background: #1b5947;
            border: none;
            border-radius: 11px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 750;
        }

        .payment-button:hover {
            background: #134536;
        }

        .demo-note {
            margin: 13px 0 0;
            color: #77837d;
            font-size: 12px;
            line-height: 1.7;
        }

        .error-box {
            margin-bottom: 18px;
            padding: 14px;
            color: #973d35;
            background: #fff0ee;
            border-radius: 11px;
            font-size: 13px;
            line-height: 1.8;
        }

        .success-box {
            padding: 20px;
            background: #edf7f1;
            border-radius: 14px;
        }

        .success-box h2 {
            margin: 0 0 10px;
            color: #1f614b;
        }

        .success-box p {
            color: #426257;
            line-height: 1.75;
        }

        .success-information {
            margin-top: 17px;
            padding-top: 12px;
            border-top: 1px solid #d7e8dd;
        }

        .holding-box {
            margin-top: 18px;
            padding: 17px;
            color: #ffffff;
            background: #1d5947;
            border-radius: 13px;
        }

        .holding-box small {
            color: rgba(255, 255, 255, 0.75);
        }

        .holding-box strong {
            display: block;
            margin-top: 7px;
            font-size: 26px;
        }

        .split-note {
            margin-top: 17px;
            padding-top: 13px;
            border-top: 1px solid #e4ece8;
        }

        .split-note h3 {
            margin: 0 0 9px;
            color: #355247;
            font-size: 14px;
        }

        .summary-image {
            width: 100%;
            height: 185px;
            display: block;
            object-fit: cover;
            border-radius: 12px;
        }

        .payment-summary h3 {
            margin: 16px 0 6px;
            color: #1d4437;
            font-size: 20px;
        }

        .summary-location {
            margin: 0 0 15px;
            color: #78847e;
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
            color: #75817a;
        }

        .summary-line strong {
            color: #29463a;
            text-align: right;
        }

        .total-line strong {
            color: #18543f;
            font-size: 17px;
        }

        .home-button {
            width: 100%;
            margin-top: 18px;
            padding: 13px;
            display: block;
            color: #ffffff;
            background: #1b5947;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
        }

        @media (max-width: 820px) {
            .payment-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 560px) {
            .payment-header-inner {
                align-items: flex-start;
                flex-direction: column;
            }

            .page-title {
                font-size: 29px;
            }
        }
    </style>
</head>

<body>

<header class="payment-header">

    <div class="payment-container payment-header-inner">

        <a
            href="../index.php"
            class="payment-logo"
        >
            Đi Đâu Đây
        </a>

        <a
            href="../homestay.php?slug=<?php
            echo urlencode(
                $booking["homestay_slug"]
            );
            ?>"

            class="back-link"
        >
            ← Quay lại homestay
        </a>

    </div>

</header>

<main class="payment-main">

    <div class="payment-container">

        <h1 class="page-title">

            <?php
            echo $isPaid
                ? "Thanh toán thành công"
                : "Thanh toán đơn đặt phòng";
            ?>

        </h1>

        <p class="page-subtitle">
            Website giữ tiền an toàn cho đến khi
            kỳ nghỉ hoàn tất.
        </p>

        <div class="payment-layout">

            <section class="payment-panel">

                <?php if ($isPaid): ?>

                    <div class="success-box">

                        <h2>
                            Thanh toán thành công!
                        </h2>

                        <p>
                            Khoản thanh toán đã được ghi nhận.
                            Website đang giữ toàn bộ số tiền
                            của đơn đặt phòng.
                        </p>

                        <div class="success-information">

                            <div class="information-row">

                                <span>
                                    Mã đơn
                                </span>

                                <strong>
                                    <?php
                                    echo htmlspecialchars(
                                        $booking[
                                            "booking_code"
                                        ]
                                    );
                                    ?>
                                </strong>

                            </div>

                            <div class="information-row">

                                <span>
                                    Mã giao dịch
                                </span>

                                <strong>
                                    <?php
                                    echo htmlspecialchars(
                                        $booking[
                                            "transaction_code"
                                        ] ?? ""
                                    );
                                    ?>
                                </strong>

                            </div>

                            <div class="information-row">

                                <span>
                                    Phương thức
                                </span>

                                <strong>
                                    <?php

                                    echo htmlspecialchars(
                                        $paymentMethods[
                                            $booking[
                                                "payment_method"
                                            ] ?? ""
                                        ] ?? "Đã thanh toán"
                                    );

                                    ?>
                                </strong>

                            </div>

                        </div>

                    </div>

                    <div class="holding-box">

                        <small>
                            Website đang giữ
                        </small>

                        <strong>
                            <?php
                            echo formatPaymentMoney(
                                $totalAmount
                            );
                            ?>
                        </strong>

                    </div>

<div class="split-note">

    <h3>
        Thanh toán của bạn được bảo vệ
    </h3>

    <p class="demo-note">
        Khoản thanh toán sẽ được hệ thống giữ an toàn
        cho đến khi kỳ nghỉ hoàn tất.
    </p>

    <p class="demo-note">
        Nếu có vấn đề với homestay, bạn có thể gửi
        yêu cầu hỗ trợ hoặc hoàn tiền.
    </p>

</div>

                    <a
                        href="../index.php"
                        class="home-button"
                    >
                        Quay về trang chủ
                    </a>

                <?php else: ?>

                    <h2>
                        Chọn phương thức thanh toán
                    </h2>

                    <?php if (!empty($errors)): ?>

                        <div class="error-box">

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

                        <div class="payment-methods">

                            <label class="payment-method">

                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="bank_transfer"

                                    <?php

                                    echo $selectedMethod
                                        === "bank_transfer"
                                        ? "checked"
                                        : "";

                                    ?>
                                >

                                <span>

                                    <strong>
                                        Chuyển khoản ngân hàng
                                    </strong>

                                    <small>
                                        Chuyển khoản đến tài khoản
                                        của website.
                                    </small>

                                </span>

                            </label>

                            <label class="payment-method">

                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="momo"

                                    <?php

                                    echo $selectedMethod
                                        === "momo"
                                        ? "checked"
                                        : "";

                                    ?>
                                >

                                <span>

                                    <strong>
                                        Ví MoMo
                                    </strong>

                                    <small>
                                        Thanh toán mô phỏng
                                        bằng ví điện tử.
                                    </small>

                                </span>

                            </label>

                            <label class="payment-method">

                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="vnpay"

                                    <?php

                                    echo $selectedMethod
                                        === "vnpay"
                                        ? "checked"
                                        : "";

                                    ?>
                                >

                                <span>

                                    <strong>
                                        VNPay
                                    </strong>

                                    <small>
                                        Thanh toán mô phỏng
                                        qua cổng VNPay.
                                    </small>

                                </span>

                            </label>

                        </div>

                        <div class="bank-information">

                            <h3>
                                Thông tin thanh toán
                            </h3>

                            <div class="information-row">

                                <span>
                                    Đơn vị nhận
                                </span>

                                <strong>
                                    ĐI ĐÂU ĐÂY
                                </strong>

                            </div>

                            <div class="information-row">

                                <span>
                                    Nội dung
                                </span>

                                <strong>
                                    <?php

                                    echo htmlspecialchars(
                                        $booking[
                                            "booking_code"
                                        ]
                                    );

                                    ?>
                                </strong>

                            </div>

                            <div class="information-row">

                                <span>
                                    Số tiền
                                </span>

                                <strong>
                                    <?php

                                    echo formatPaymentMoney(
                                        $totalAmount
                                    );

                                    ?>
                                </strong>

                            </div>

                        </div>

                        <button
                            type="submit"
                            class="payment-button"
                        >
                            Xác nhận thanh toán
                        </button>

                        <p class="demo-note">
                            Đây là thanh toán mô phỏng phục vụ
                            bài tập lớn, chưa kết nối ngân hàng,
                            MoMo hoặc VNPay thật.
                        </p>

                    </form>

                <?php endif; ?>

            </section>

            <aside class="payment-summary">

                <?php if (
                    !empty(
                        $booking["cover_image"]
                    )
                ): ?>

                    <img
                        src="../<?php

                        echo htmlspecialchars(
                            $booking["cover_image"]
                        );

                        ?>"

                        alt="<?php

                        echo htmlspecialchars(
                            $booking["homestay_name"]
                        );

                        ?>"

                        class="summary-image"
                    >

                <?php endif; ?>

                <h3>

                    <?php

                    echo htmlspecialchars(
                        $booking["homestay_name"]
                    );

                    ?>

                </h3>

                <p class="summary-location">

                    <?php

                    echo htmlspecialchars(
                        $booking["homestay_address"]
                    );

                    ?>

                    ·

                    <?php

                    echo htmlspecialchars(
                        $booking["homestay_province"]
                    );

                    ?>

                </p>

                <div class="summary-line">

                    <span>
                        Mã đơn
                    </span>

                    <strong>

                        <?php

                        echo htmlspecialchars(
                            $booking["booking_code"]
                        );

                        ?>

                    </strong>

                </div>

                <div class="summary-line">

                    <span>
                        Gói thuê
                    </span>

                    <strong>

                        <?php

                        echo htmlspecialchars(
                            $bookingTypeLabel
                        );

                        ?>

                    </strong>

                </div>

                <div class="summary-line">

                    <span>
                        Nhận phòng
                    </span>

                    <strong>

                        <?php

                        echo date(
                            "H:i d/m/Y",
                            strtotime(
                                $booking["check_in"]
                            )
                        );

                        ?>

                    </strong>

                </div>

                <div class="summary-line">

                    <span>
                        Trả phòng
                    </span>

                    <strong>

                        <?php

                        echo date(
                            "H:i d/m/Y",
                            strtotime(
                                $booking["check_out"]
                            )
                        );

                        ?>

                    </strong>

                </div>

                <div class="summary-line">

                    <span>
                        Số khách
                    </span>

                    <strong>

                        <?php

                        echo (int) $booking[
                            "guest_count"
                        ];

                        ?>

                        khách

                    </strong>

                </div>

                <div class="summary-line total-line">

                    <span>
                        Tổng thanh toán
                    </span>

                    <strong>

                        <?php

                        echo formatPaymentMoney(
                            $totalAmount
                        );

                        ?>

                    </strong>

                </div>

            </aside>

        </div>

    </div>

</main>

</body>

</html>