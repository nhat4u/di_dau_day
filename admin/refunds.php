<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("admin");

date_default_timezone_set("Asia/Ho_Chi_Minh");

$adminId = currentUserId();

/*
|--------------------------------------------------------------------------
| Tạo mã bảo vệ biểu mẫu
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION["csrf_token"]
    )
) {

    $_SESSION["csrf_token"] =

        bin2hex(
            random_bytes(32)
        );

}

/*
|--------------------------------------------------------------------------
| Hàm hỗ trợ hiển thị
|--------------------------------------------------------------------------
*/

function refundEscape($value)
{

    return htmlspecialchars(

        (string) $value,

        ENT_QUOTES,

        "UTF-8"

    );

}

function refundMoney($amount)
{

    return number_format(

        (float) $amount,

        0,

        ",",

        "."

    ) . "đ";

}

function refundReasonName($reason)
{

    $names = [

        "guest_cancelled" =>
            "Khách yêu cầu hủy đơn",

        "host_cancelled" =>
            "Chủ homestay hủy hoặc không tiếp nhận",

        "power_outage" =>
            "Mất điện hoặc sự cố điện",

        "service_issue" =>
            "Dịch vụ không đúng mô tả",

        "other" =>
            "Lý do khác"

    ];

    return $names[$reason]

        ??

        "Chưa xác định";

}

function refundStatusName($status)
{

    $names = [

        "pending" =>
            "Chờ xử lý",

        "approved" =>
            "Đã duyệt",

        "rejected" =>
            "Đã từ chối",

        "completed" =>
            "Đã hoàn tiền"

    ];

    return $names[$status]

        ??

        $status;

}

/*
|--------------------------------------------------------------------------
| Xử lý thao tác của QTV
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"]
    === "POST"
) {

    $csrfToken =

        $_POST["csrf_token"]

        ??

        "";

    $action =

        $_POST["action"]

        ??

        "";

    $refundId = (int) (

        $_POST["refund_id"]

        ??

        0

    );

    $adminNote = trim(

        $_POST["admin_note"]

        ??

        ""

    );

    if (

        !hash_equals(

            $_SESSION["csrf_token"],

            $csrfToken

        )

    ) {

        $_SESSION["refund_flash"] = [

            "type" =>
                "error",

            "message" =>
                "Yêu cầu không hợp lệ. Vui lòng thử lại."

        ];

        header(
            "Location: refunds.php"
        );

        exit;

    }

    if (
        $refundId <= 0

        ||

        !in_array(

            $action,

            [

                "approve_refund",

                "reject_refund"

            ],

            true

        )
    ) {

        $_SESSION["refund_flash"] = [

            "type" =>
                "error",

            "message" =>
                "Không xác định được thao tác cần xử lý."

        ];

        header(
            "Location: refunds.php"
        );

        exit;

    }

    try {

        $pdo->beginTransaction();

        /*
        |------------------------------------------------------------------
        | Khóa yêu cầu và đơn liên quan
        |------------------------------------------------------------------
        */

$requestStatement = $pdo->prepare(
    "SELECT
        refund_requests.id,
        refund_requests.booking_id,
        refund_requests.reason,
        refund_requests.refund_amount,
        refund_requests.status AS request_status,

        bookings.booking_code,
        bookings.status AS booking_status,
        bookings.total_amount,

        payments.id AS payment_id,
        payments.amount AS payment_amount,
        payments.status AS payment_status

     FROM refund_requests

     INNER JOIN bookings
        ON bookings.id = refund_requests.booking_id

     INNER JOIN payments
        ON payments.booking_id = bookings.id

     WHERE refund_requests.id = :refund_id

     LIMIT 1

     FOR UPDATE"
);

if (!$requestStatement) {
    throw new RuntimeException(
        "Không thể tạo truy vấn lấy yêu cầu hoàn tiền."
    );
}

$requestStatement->execute([
    "refund_id" => $refundId
]);

$request = $requestStatement->fetch(
    PDO::FETCH_ASSOC
);

if (!$request) {
    throw new RuntimeException(
        "Không tìm thấy yêu cầu hoàn tiền."
    );
}

        if (

            $request["request_status"]
            !== "pending"

        ) {

            throw new RuntimeException(

                "Yêu cầu này đã được xử lý trước đó."

            );

        }

        if (

            $request["payment_status"]
            !== "held"

        ) {

            throw new RuntimeException(

                "Khoản thanh toán không còn được website giữ."

            );

        }

        $bookingId = (int) (

            $request["booking_id"]

        );

        /*
        |------------------------------------------------------------------
        | QTV đồng ý hoàn tiền
        |------------------------------------------------------------------
        */

        if (
            $action === "approve_refund"
        ) {

            $refundAmount = (float) (

                $request["refund_amount"]

            );

            $paymentAmount = (float) (

                $request["payment_amount"]

            );

            if (

                $refundAmount <= 0

                ||

                abs(

                    $refundAmount
                    -
                    $paymentAmount

                ) > 0.01

            ) {

                throw new RuntimeException(

                    "Hiện tại hệ thống chỉ hỗ trợ hoàn "
                    . "toàn bộ giá trị đã thanh toán."

                );

            }

            if (
                $adminNote === ""
            ) {

                $adminNote =

                    "QTV đã duyệt và hoàn tiền cho khách.";

            }

            /*
            |--------------------------------------------------------------
            | Đánh dấu khoản thanh toán đã được hoàn
            |--------------------------------------------------------------
            */

            $paymentUpdateStatement =
                $pdo->prepare(

                    "UPDATE payments

                     SET status =
                        'refunded'

                     WHERE id =
                        :payment_id

                       AND status =
                        'held'"

                );

            $paymentUpdateStatement->execute([

                "payment_id" =>
                    $request["payment_id"]

            ]);

            if (

                $paymentUpdateStatement->rowCount()
                !== 1

            ) {

                throw new RuntimeException(

                    "Không thể cập nhật trạng thái thanh toán."

                );

            }

            /*
            |--------------------------------------------------------------
            | Đánh dấu đơn đặt phòng đã được hoàn tiền
            |--------------------------------------------------------------
            */

            $bookingUpdateStatement =
                $pdo->prepare(

                    "UPDATE bookings

                     SET status =
                        'refunded'

                     WHERE id =
                        :booking_id"

                );

            $bookingUpdateStatement->execute([

                "booking_id" =>
                    $bookingId

            ]);

            /*
            |--------------------------------------------------------------
            | Đánh dấu yêu cầu hoàn tiền đã xử lý xong
            |--------------------------------------------------------------
            */

            $refundUpdateStatement =
                $pdo->prepare(

                    "UPDATE refund_requests

                     SET

                        status =
                            'completed',

                        admin_note =
                            :admin_note,

                        resolved_by =
                            :resolved_by,

                        resolved_at =
                            NOW()

                     WHERE id =
                        :refund_id"

                );

            $refundUpdateStatement->execute([

                "admin_note" =>
                    $adminNote,

                "resolved_by" =>
                    $adminId,

                "refund_id" =>
                    $refundId

            ]);

            /*
            |--------------------------------------------------------------
            | Hủy những bản quyết toán chưa hoàn thành nếu có
            |--------------------------------------------------------------
            */

            $settlementUpdateStatement =
                $pdo->prepare(

                    "UPDATE settlements

                     SET status =
                        'failed'

                     WHERE booking_id =
                        :booking_id

                       AND status IN (

                            'pending',

                            'held',

                            'processing'

                       )"

                );

            $settlementUpdateStatement->execute([

                "booking_id" =>
                    $bookingId

            ]);

            $pdo->commit();

            $_SESSION["refund_flash"] = [

                "type" =>
                    "success",

                "message" =>

                    "Đã hoàn "

                    . refundMoney(
                        $refundAmount
                    )

                    . " cho đơn "

                    . $request[
                        "booking_code"
                    ]

                    . "."

            ];

        }

        /*
        |------------------------------------------------------------------
        | QTV từ chối yêu cầu hoàn tiền
        |------------------------------------------------------------------
        */

        if (
            $action === "reject_refund"
        ) {

            if (
                $adminNote === ""
            ) {

                $adminNote =

                    "QTV đã từ chối yêu cầu hoàn tiền.";

            }

            /*
            |--------------------------------------------------------------
            | Đánh dấu yêu cầu đã bị từ chối
            |--------------------------------------------------------------
            */

            $refundUpdateStatement =
                $pdo->prepare(

                    "UPDATE refund_requests

                     SET

                        status =
                            'rejected',

                        admin_note =
                            :admin_note,

                        resolved_by =
                            :resolved_by,

                        resolved_at =
                            NOW()

                     WHERE id =
                        :refund_id"

                );

            $refundUpdateStatement->execute([

                "admin_note" =>
                    $adminNote,

                "resolved_by" =>
                    $adminId,

                "refund_id" =>
                    $refundId

            ]);

            /*
            |--------------------------------------------------------------
            | Đơn quay lại trạng thái chờ chủ homestay xác nhận
            |--------------------------------------------------------------
            */

            $bookingUpdateStatement =
                $pdo->prepare(

                    "UPDATE bookings

                     SET status =
                        'funds_held'

                     WHERE id =
                        :booking_id

                       AND status =
                        'disputed'"

                );

            $bookingUpdateStatement->execute([

                "booking_id" =>
                    $bookingId

            ]);

            if (

                $bookingUpdateStatement->rowCount()
                !== 1

            ) {

                throw new RuntimeException(

                    "Không thể khôi phục trạng thái đơn đặt phòng."

                );

            }

            $pdo->commit();

            $_SESSION["refund_flash"] = [

                "type" =>
                    "success",

                "message" =>

                    "Đã từ chối yêu cầu hoàn tiền của đơn "

                    . $request[
                        "booking_code"
                    ]

                    . ". Chủ homestay cần xác nhận lại đơn."

            ];

        }

    } catch (
        Throwable $exception
    ) {

        if (

            $pdo->inTransaction()

        ) {

            $pdo->rollBack();

        }

        $_SESSION["refund_flash"] = [

            "type" =>
                "error",

            "message" =>

                "Không thể xử lý yêu cầu: "

                . $exception->getMessage()

        ];

    }

    header(
        "Location: refunds.php"
    );

    exit;

}

/*
|--------------------------------------------------------------------------
| Hiển thị thông báo sau khi xử lý
|--------------------------------------------------------------------------
*/

$flash =

    $_SESSION["refund_flash"]

    ??

    null;

unset(
    $_SESSION["refund_flash"]
);

/*
|--------------------------------------------------------------------------
| Bộ lọc trạng thái
|--------------------------------------------------------------------------
*/

$selectedStatus =

    $_GET["status"]

    ??

    "all";

$allowedStatuses = [

    "all",

    "pending",

    "completed",

    "rejected"

];

if (

    !in_array(

        $selectedStatus,

        $allowedStatuses,

        true

    )

) {

    $selectedStatus =
        "all";

}

/*
|--------------------------------------------------------------------------
| Thống kê
|--------------------------------------------------------------------------
*/

$summaryStatement = $pdo->query(

    "SELECT

        COUNT(*) AS total_requests,

        COALESCE(

            SUM(

                CASE

                    WHEN status =
                        'pending'

                    THEN 1

                    ELSE 0

                END

            ),

            0

        ) AS pending_count,

        COALESCE(

            SUM(

                CASE

                    WHEN status =
                        'pending'

                    THEN refund_amount

                    ELSE 0

                END

            ),

            0

        ) AS pending_amount,

        COALESCE(

            SUM(

                CASE

                    WHEN status =
                        'completed'

                    THEN 1

                    ELSE 0

                END

            ),

            0

        ) AS completed_count,

        COALESCE(

            SUM(

                CASE

                    WHEN status =
                        'rejected'

                    THEN 1

                    ELSE 0

                END

            ),

            0

        ) AS rejected_count

     FROM refund_requests"

);

$summary =

    $summaryStatement->fetch(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Danh sách yêu cầu
|--------------------------------------------------------------------------
*/

$refundSql =

    "SELECT

        refund_requests.id,

        refund_requests.reason,

        refund_requests.description,

        refund_requests.refund_amount,

        refund_requests.status,

        refund_requests.admin_note,

        refund_requests.created_at,

        refund_requests.resolved_at,

        bookings.booking_code,

        bookings.check_in,

        bookings.check_out,

        bookings.total_amount,

        bookings.status
            AS booking_status,

        homestays.name
            AS homestay_name,

        homestays.province,

        guest_users.email
            AS guest_email,

        owner_users.email
            AS owner_email

     FROM refund_requests

     INNER JOIN bookings

        ON bookings.id =
            refund_requests.booking_id

     INNER JOIN homestays

        ON homestays.id =
            bookings.homestay_id

     LEFT JOIN users AS guest_users

        ON guest_users.id =
            refund_requests.requested_by

     LEFT JOIN users AS owner_users

        ON owner_users.id =
            homestays.owner_id

     WHERE 1 = 1";

$refundParameters = [];

if (
    $selectedStatus !== "all"
) {

    $refundSql .=

        " AND refund_requests.status =
            :status";

    $refundParameters["status"] =

        $selectedStatus;

}

$refundSql .=

    " ORDER BY

        CASE

            WHEN refund_requests.status =
                'pending'

            THEN 0

            ELSE 1

        END,

        refund_requests.created_at DESC";

$refundStatement =

    $pdo->prepare(
        $refundSql
    );

$refundStatement->execute(
    $refundParameters
);

$refundRequests =

    $refundStatement->fetchAll(
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

        Quản lý hoàn tiền - Đi Đâu Đây

    </title>

    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >

    <style>

        .refund-stat-grid {

            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 16px;

            margin-bottom: 22px;

        }

        .refund-stat-card {

            min-height: 130px;

            padding: 20px;

            border:
                1px solid #e0e7e3;

            border-radius: 14px;

            background: #ffffff;

        }

        .refund-stat-card small {

            display: block;

            margin-bottom: 14px;

            color: #77827d;

            font-size: 11px;

            font-weight: 800;

            letter-spacing: 0.5px;

            text-transform: uppercase;

        }

        .refund-stat-card strong {

            display: block;

            color: #14503b;

            font-size: 28px;

        }

        .refund-stat-card span {

            display: block;

            margin-top: 9px;

            color: #89938e;

            font-size: 12px;

        }

        .refund-message {

            margin-bottom: 20px;

            padding: 15px 18px;

            border-radius: 11px;

            font-size: 13px;

            line-height: 1.6;

        }

        .refund-message-success {

            border:
                1px solid #d4e9db;

            color: #245e43;

            background: #edf8f0;

        }

        .refund-message-error {

            border:
                1px solid #f1d4d4;

            color: #9d4545;

            background: #fff1f1;

        }

        .refund-filters {

            display: flex;

            flex-wrap: wrap;

            gap: 10px;

            margin-bottom: 20px;

        }

        .refund-filter {

            padding: 9px 15px;

            border:
                1px solid #dfe6e2;

            border-radius: 999px;

            color: #66736c;

            background: #ffffff;

            font-size: 12px;

            text-decoration: none;

        }

        .refund-filter.active {

            border-color: #18533f;

            color: #ffffff;

            background: #18533f;

        }

        .refund-list {

            display: grid;

            gap: 17px;

        }

        .refund-card {

            overflow: hidden;

            border:
                1px solid #dfe7e2;

            border-radius: 15px;

            background: #ffffff;

        }

        .refund-card-header {

            display: flex;

            justify-content:
                space-between;

            gap: 15px;

            padding:
                18px 21px;

            border-bottom:
                1px solid #e9eeeb;

        }

        .refund-booking-code {

            display: block;

            margin-bottom: 5px;

            color: #174b38;

            font-size: 14px;

            font-weight: 800;

        }

        .refund-created-at {

            color: #87918c;

            font-size: 12px;

        }

        .refund-status {

            display: inline-flex;

            align-items: center;

            height: 31px;

            padding: 0 12px;

            border-radius: 999px;

            font-size: 11px;

            font-weight: 700;

        }

        .refund-status-pending {

            color: #94621f;

            background: #fff3df;

        }

        .refund-status-completed {

            color: #236d49;

            background: #eaf7ee;

        }

        .refund-status-rejected {

            color: #aa4848;

            background: #fcecec;

        }

        .refund-status-approved {

            color: #345f88;

            background: #edf4fb;

        }

        .refund-card-body {

            display: grid;

            grid-template-columns:
                1fr 1fr 220px;

            gap: 20px;

            padding:
                20px 21px;

        }

        .refund-info-title {

            display: block;

            margin-bottom: 8px;

            color: #76817c;

            font-size: 11px;

            font-weight: 700;

            text-transform:
                uppercase;

        }

        .refund-homestay-name {

            display: block;

            margin-bottom: 8px;

            color: #1c362b;

            font-size: 16px;

            font-weight: 800;

        }

        .refund-info-line {

            margin: 6px 0;

            color: #6f7b75;

            font-size: 12px;

            line-height: 1.6;

        }

        .refund-reason {

            margin-bottom: 8px;

            color: #35443d;

            font-size: 13px;

            font-weight: 700;

        }

        .refund-description {

            color: #78837e;

            font-size: 12px;

            line-height: 1.7;

        }

        .refund-amount {

            display: block;

            margin-top: 7px;

            color: #14503b;

            font-size: 24px;

            font-weight: 800;

        }

        .refund-actions {

            padding:
                18px 21px;

            border-top:
                1px solid #e9eeeb;

            background: #fafcfb;

        }

        .refund-action-form {

            display: grid;

            grid-template-columns:
                minmax(0, 1fr)
                auto
                auto;

            gap: 10px;

            align-items: center;

        }

        .refund-note-input {

            width: 100%;

            min-height: 44px;

            padding:
                0 13px;

            border:
                1px solid #dfe7e2;

            border-radius: 9px;

            outline: none;

            background: #ffffff;

            font-size: 13px;

        }

        .refund-note-input:focus {

            border-color: #18533f;

        }

        .refund-button {

            min-height: 44px;

            padding:
                0 17px;

            border: none;

            border-radius: 9px;

            cursor: pointer;

            font-size: 12px;

            font-weight: 750;

        }

        .refund-button-approve {

            color: #ffffff;

            background: #18533f;

        }

        .refund-button-reject {

            color: #a94343;

            background: #fdeeee;

        }

        .refund-resolution {

            padding:
                17px 21px;

            border-top:
                1px solid #e9eeeb;

            color: #73807a;

            background: #fafcfb;

            font-size: 12px;

            line-height: 1.7;

        }

        .refund-empty {

            padding:
                75px 20px;

            border:
                1px solid #e0e7e3;

            border-radius: 15px;

            color: #85908a;

            background: #ffffff;

            text-align: center;

        }

        .refund-empty strong {

            display: block;

            margin-bottom: 8px;

            color: #52615a;

        }

        @media (
            max-width: 1100px
        ) {

            .refund-stat-grid {

                grid-template-columns:
                    repeat(
                        2,
                        minmax(0, 1fr)
                    );

            }

            .refund-card-body {

                grid-template-columns:
                    1fr 1fr;

            }

        }

        @media (
            max-width: 700px
        ) {

            .refund-stat-grid {

                grid-template-columns:
                    1fr;

            }

            .refund-card-body {

                grid-template-columns:
                    1fr;

            }

            .refund-action-form {

                grid-template-columns:
                    1fr;

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

            <a href="bookings.php">

                Đơn đặt phòng

            </a>

            <a
                href="refunds.php"
                class="active"
            >

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

                        echo refundEscape(

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

                    XỬ LÝ KHIẾU NẠI VÀ HOÀN TIỀN

                </p>

                <h1>

                    Quản lý hoàn tiền

                </h1>

            </div>

            <a
                href="wallet.php"
                class="button button-outline"
            >

                ← Xem ví QTV

            </a>

        </header>

        <?php if ($flash): ?>

            <div
                class="refund-message refund-message-<?php

                echo refundEscape(

                    $flash["type"]

                );

                ?>"
            >

                <?php

                echo refundEscape(

                    $flash["message"]

                );

                ?>

            </div>

        <?php endif; ?>

        <section class="refund-stat-grid">

            <article class="refund-stat-card">

                <small>

                    Tổng yêu cầu

                </small>

                <strong>

                    <?php

                    echo (int) (

                        $summary[
                            "total_requests"
                        ]

                    );

                    ?>

                </strong>

                <span>

                    Tất cả yêu cầu đã ghi nhận

                </span>

            </article>

            <article class="refund-stat-card">

                <small>

                    Đang chờ xử lý

                </small>

                <strong>

                    <?php

                    echo (int) (

                        $summary[
                            "pending_count"
                        ]

                    );

                    ?>

                </strong>

                <span>

                    Yêu cầu cần QTV kiểm tra

                </span>

            </article>

            <article class="refund-stat-card">

                <small>

                    Số tiền đang giữ

                </small>

                <strong>

                    <?php

                    echo refundMoney(

                        $summary[
                            "pending_amount"
                        ]

                    );

                    ?>

                </strong>

                <span>

                    Chờ quyết định hoàn tiền

                </span>

            </article>

            <article class="refund-stat-card">

                <small>

                    Đã hoàn tiền

                </small>

                <strong>

                    <?php

                    echo (int) (

                        $summary[
                            "completed_count"
                        ]

                    );

                    ?>

                </strong>

                <span>

                    Yêu cầu đã xử lý thành công

                </span>

            </article>

        </section>

        <div class="refund-filters">

            <a
                href="refunds.php"
                class="refund-filter <?php

                echo $selectedStatus === "all"

                    ? "active"

                    : "";

                ?>"
            >

                Tất cả

            </a>

            <a
                href="refunds.php?status=pending"
                class="refund-filter <?php

                echo $selectedStatus === "pending"

                    ? "active"

                    : "";

                ?>"
            >

                Chờ xử lý

            </a>

            <a
                href="refunds.php?status=completed"
                class="refund-filter <?php

                echo $selectedStatus === "completed"

                    ? "active"

                    : "";

                ?>"
            >

                Đã hoàn tiền

            </a>

            <a
                href="refunds.php?status=rejected"
                class="refund-filter <?php

                echo $selectedStatus === "rejected"

                    ? "active"

                    : "";

                ?>"
            >

                Đã từ chối

            </a>

        </div>

        <?php if (
            empty(
                $refundRequests
            )
        ): ?>

            <div class="refund-empty">

                <strong>

                    Chưa có yêu cầu hoàn tiền

                </strong>

                Những yêu cầu khiếu nại và hủy đơn
                của khách sẽ xuất hiện tại đây.

            </div>

        <?php else: ?>

            <section class="refund-list">

                <?php foreach (

                    $refundRequests

                    as

                    $refund

                ): ?>

                    <article class="refund-card">

                        <div class="refund-card-header">

                            <div>

                                <span
                                    class="refund-booking-code"
                                >

                                    Mã đơn:

                                    <?php

                                    echo refundEscape(

                                        $refund[
                                            "booking_code"
                                        ]

                                    );

                                    ?>

                                </span>

                                <span
                                    class="refund-created-at"
                                >

                                    Gửi lúc:

                                    <?php

                                    echo date(

                                        "d/m/Y H:i",

                                        strtotime(

                                            $refund[
                                                "created_at"
                                            ]

                                        )

                                    );

                                    ?>

                                </span>

                            </div>

                            <span
                                class="refund-status refund-status-<?php

                                echo refundEscape(

                                    $refund["status"]

                                );

                                ?>"
                            >

                                <?php

                                echo refundEscape(

                                    refundStatusName(

                                        $refund["status"]

                                    )

                                );

                                ?>

                            </span>

                        </div>

                        <div class="refund-card-body">

                            <div>

                                <span
                                    class="refund-info-title"
                                >

                                    Homestay và khách thuê

                                </span>

                                <span
                                    class="refund-homestay-name"
                                >

                                    <?php

                                    echo refundEscape(

                                        $refund[
                                            "homestay_name"
                                        ]

                                    );

                                    ?>

                                </span>

                                <div
                                    class="refund-info-line"
                                >

                                    Địa điểm:

                                    <?php

                                    echo refundEscape(

                                        $refund[
                                            "province"
                                        ]

                                    );

                                    ?>

                                </div>

                                <div
                                    class="refund-info-line"
                                >

                                    Khách:

                                    <?php

                                    echo refundEscape(

                                        $refund[
                                            "guest_email"
                                        ]

                                        ??

                                        "Không có thông tin"

                                    );

                                    ?>

                                </div>

                                <div
                                    class="refund-info-line"
                                >

                                    Chủ home:

                                    <?php

                                    echo refundEscape(

                                        $refund[
                                            "owner_email"
                                        ]

                                        ??

                                        "Không có thông tin"

                                    );

                                    ?>

                                </div>

                            </div>

                            <div>

                                <span
                                    class="refund-info-title"
                                >

                                    Lý do yêu cầu

                                </span>

                                <div
                                    class="refund-reason"
                                >

                                    <?php

                                    echo refundEscape(

                                        refundReasonName(

                                            $refund[
                                                "reason"
                                            ]

                                        )

                                    );

                                    ?>

                                </div>

                                <div
                                    class="refund-description"
                                >

                                    <?php

                                    echo nl2br(

                                        refundEscape(

                                            $refund[
                                                "description"
                                            ]

                                            ??

                                            "Không có nội dung bổ sung."

                                        )

                                    );

                                    ?>

                                </div>

                            </div>

                            <div>

                                <span
                                    class="refund-info-title"
                                >

                                    Số tiền yêu cầu hoàn

                                </span>

                                <strong
                                    class="refund-amount"
                                >

                                    <?php

                                    echo refundMoney(

                                        $refund[
                                            "refund_amount"
                                        ]

                                    );

                                    ?>

                                </strong>

                                <div
                                    class="refund-info-line"
                                >

                                    Giá trị đơn:

                                    <?php

                                    echo refundMoney(

                                        $refund[
                                            "total_amount"
                                        ]

                                    );

                                    ?>

                                </div>

                            </div>

                        </div>

                        <?php if (

                            $refund["status"]

                            ===

                            "pending"

                        ): ?>

                            <div class="refund-actions">

                                <form
                                    method="POST"
                                    class="refund-action-form"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?php

                                        echo refundEscape(

                                            $_SESSION[
                                                "csrf_token"
                                            ]

                                        );

                                        ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="refund_id"
                                        value="<?php

                                        echo (int) (

                                            $refund["id"]

                                        );

                                        ?>"
                                    >

                                    <input
                                        type="text"
                                        name="admin_note"
                                        class="refund-note-input"
                                        placeholder="Ghi chú xử lý của QTV..."
                                    >

                                    <button
                                        type="submit"
                                        name="action"
                                        value="approve_refund"
                                        class="refund-button refund-button-approve"
                                        onclick="return confirm('Xác nhận hoàn tiền cho khách?');"
                                    >

                                        Duyệt hoàn tiền

                                    </button>

                                    <button
                                        type="submit"
                                        name="action"
                                        value="reject_refund"
                                        class="refund-button refund-button-reject"
                                        onclick="return confirm('Xác nhận từ chối yêu cầu này?');"
                                    >

                                        Từ chối

                                    </button>

                                </form>

                            </div>

                        <?php else: ?>

                            <div
                                class="refund-resolution"
                            >

                                <strong>

                                    Ghi chú của QTV:

                                </strong>

                                <?php

                                echo refundEscape(

                                    $refund[
                                        "admin_note"
                                    ]

                                    ??

                                    "Không có ghi chú."

                                );

                                ?>

                                <?php if (

                                    !empty(

                                        $refund[
                                            "resolved_at"
                                        ]

                                    )

                                ): ?>

                                    <br>

                                    Xử lý lúc:

                                    <?php

                                    echo date(

                                        "d/m/Y H:i",

                                        strtotime(

                                            $refund[
                                                "resolved_at"
                                            ]

                                        )

                                    );

                                    ?>

                                <?php endif; ?>

                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </section>

        <?php endif; ?>

    </main>

</div>

</body>

</html>