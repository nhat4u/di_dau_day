<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("guest");

date_default_timezone_set("Asia/Ho_Chi_Minh");

$guestId = currentUserId();

$errors = [];

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/*
 * Xử lý hủy phòng, khiếu nại
 * và xác nhận hoàn thành.
 */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    $action = $_POST["action"] ?? "";

    $bookingId = (int) (
        $_POST["booking_id"] ?? 0
    );

    $description = trim(
        $_POST["description"] ?? ""
    );

    $complaintReason = (
        $_POST["complaint_reason"] ?? "other"
    );

    try {
        if (
            !hash_equals(
                $_SESSION["csrf_token"],
                $csrfToken
            )
        ) {
            throw new RuntimeException(
                "Yêu cầu không hợp lệ."
            );
        }

        if ($bookingId <= 0) {
            throw new RuntimeException(
                "Không tìm thấy đơn đặt phòng."
            );
        }

        if (
            !in_array(
                $action,
                [
                    "cancel_booking",
                    "submit_complaint",
                    "complete_booking"
                ],
                true
            )
        ) {
            throw new RuntimeException(
                "Thao tác không hợp lệ."
            );
        }

        $pdo->beginTransaction();

        /*
         * Khóa đơn để tránh xử lý hai lần.
         */

        $bookingCheckStatement = $pdo->prepare(
            "SELECT
                b.id,

                b.booking_code,

                b.guest_id,

                b.homestay_id,

                b.check_in,

                b.check_out,

                b.total_amount,

                b.status,

                h.owner_id,

                p.status AS payment_status

            FROM bookings AS b

            INNER JOIN homestays AS h
                ON h.id = b.homestay_id

            LEFT JOIN payments AS p
                ON p.booking_id = b.id

            WHERE b.id = :booking_id

                AND b.guest_id = :guest_id

            LIMIT 1

            FOR UPDATE"
        );

        $bookingCheckStatement->execute([
            "booking_id" => $bookingId,
            "guest_id" => $guestId
        ]);

        $currentBooking =
            $bookingCheckStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$currentBooking) {
            throw new RuntimeException(
                "Bạn không có quyền xử lý đơn này."
            );
        }

        /*
         * Kiểm tra đơn có yêu cầu hoàn tiền
         * hoặc khiếu nại đang chờ không.
         */

        $pendingRefundStatement = $pdo->prepare(
            "SELECT id

            FROM refund_requests

            WHERE booking_id = :booking_id

                AND status = 'pending'

            LIMIT 1

            FOR UPDATE"
        );

        $pendingRefundStatement->execute([
            "booking_id" => $bookingId
        ]);

        $existingPendingRequest = (
            $pendingRefundStatement->fetchColumn()
        );

        /*
         * KHÁCH YÊU CẦU HỦY.
         */

        if ($action === "cancel_booking") {
            $checkInTimestamp = strtotime(
                $currentBooking["check_in"]
            );

            if (
                $checkInTimestamp !== false &&
                $checkInTimestamp <= time()
            ) {
                throw new RuntimeException(
                    "Không thể hủy khi đã đến giờ nhận phòng."
                );
            }

            /*
             * Chưa thanh toán:
             * hủy trực tiếp.
             */

            if (
                $currentBooking["status"]
                === "pending_payment"
            ) {
                $cancelBookingStatement = $pdo->prepare(
                    "UPDATE bookings

                    SET status = 'cancelled'

                    WHERE id = :booking_id

                        AND guest_id = :guest_id"
                );

                $cancelBookingStatement->execute([
                    "booking_id" => $bookingId,
                    "guest_id" => $guestId
                ]);

                $cancelPaymentStatement = $pdo->prepare(
                    "UPDATE payments

                    SET status = 'failed'

                    WHERE booking_id = :booking_id

                        AND status = 'pending'"
                );

                $cancelPaymentStatement->execute([
                    "booking_id" => $bookingId
                ]);

                $pdo->commit();

                header(
                    "Location: bookings.php?success=cancelled"
                );

                exit;
            }

            /*
             * Đã thanh toán:
             * tạo yêu cầu hoàn tiền cho QTV.
             */

            if (
                !in_array(
                    $currentBooking["status"],
                    ["funds_held", "confirmed"],
                    true
                )
            ) {
                throw new RuntimeException(
                    "Đơn này không thể yêu cầu hủy."
                );
            }

            if (
                $currentBooking["payment_status"]
                !== "held"
            ) {
                throw new RuntimeException(
                    "Không tìm thấy khoản thanh toán đang được giữ."
                );
            }

            if ($existingPendingRequest) {
                throw new RuntimeException(
                    "Đơn này đã có một yêu cầu đang chờ xử lý."
                );
            }

            if ($description === "") {
                $description =
                    "Khách yêu cầu hủy đơn đặt phòng.";
            }

            $insertRefundStatement = $pdo->prepare(
                "INSERT INTO refund_requests (
                    booking_id,

                    requested_by,

                    reason,

                    description,

                    refund_amount,

                    status
                ) VALUES (
                    :booking_id,

                    :requested_by,

                    'guest_cancelled',

                    :description,

                    :refund_amount,

                    'pending'
                )"
            );

            $insertRefundStatement->execute([
                "booking_id" => $bookingId,

                "requested_by" => $guestId,

                "description" => $description,

                "refund_amount" => (
                    $currentBooking["total_amount"]
                )
            ]);

            /*
             * Chuyển đơn sang trạng thái chờ xử lý.
             * Tiền vẫn do website giữ.
             */

            $updateBookingStatement = $pdo->prepare(
                "UPDATE bookings

                SET status = 'disputed'

                WHERE id = :booking_id

                    AND guest_id = :guest_id"
            );

            $updateBookingStatement->execute([
                "booking_id" => $bookingId,

                "guest_id" => $guestId
            ]);

            $pdo->commit();

            header(
                "Location: bookings.php?success=refund_requested"
            );

            exit;
        }

        /*
         * KHÁCH GỬI KHIẾU NẠI.
         */

        if ($action === "submit_complaint") {
            if (
                !in_array(
                    $currentBooking["status"],
                    ["funds_held", "confirmed"],
                    true
                )
            ) {
                throw new RuntimeException(
                    "Đơn này không thể gửi khiếu nại."
                );
            }

            if (
                $currentBooking["payment_status"]
                !== "held"
            ) {
                throw new RuntimeException(
                    "Khoản thanh toán không còn ở trạng thái chờ xử lý."
                );
            }

            if ($existingPendingRequest) {
                throw new RuntimeException(
                    "Đơn này đã có yêu cầu đang chờ QTV xử lý."
                );
            }

            $allowedComplaintReasons = [
                "power_outage",

                "service_issue",

                "host_cancelled",

                "other"
            ];

            if (
                !in_array(
                    $complaintReason,
                    $allowedComplaintReasons,
                    true
                )
            ) {
                $complaintReason = "other";
            }

            if (strlen($description) < 10) {
                throw new RuntimeException(
                    "Vui lòng mô tả khiếu nại tối thiểu 10 ký tự."
                );
            }

            $insertComplaintStatement = $pdo->prepare(
                "INSERT INTO refund_requests (
                    booking_id,

                    requested_by,

                    reason,

                    description,

                    refund_amount,

                    status
                ) VALUES (
                    :booking_id,

                    :requested_by,

                    :reason,

                    :description,

                    :refund_amount,

                    'pending'
                )"
            );

            $insertComplaintStatement->execute([
                "booking_id" => $bookingId,

                "requested_by" => $guestId,

                "reason" => $complaintReason,

                "description" => $description,

                "refund_amount" => (
                    $currentBooking["total_amount"]
                )
            ]);

            $updateBookingStatement = $pdo->prepare(
                "UPDATE bookings

                SET status = 'disputed'

                WHERE id = :booking_id

                    AND guest_id = :guest_id"
            );

            $updateBookingStatement->execute([
                "booking_id" => $bookingId,

                "guest_id" => $guestId
            ]);

            $pdo->commit();

            header(
                "Location: bookings.php?success=complaint_sent"
            );

            exit;
        }

        /*
         * KHÁCH XÁC NHẬN HOÀN THÀNH.
         */

        if ($action === "complete_booking") {
            if (
                $currentBooking["status"]
                !== "confirmed"
            ) {
                throw new RuntimeException(
                    "Đơn chưa được chủ homestay xác nhận."
                );
            }

            if (
                $currentBooking["payment_status"]
                !== "held"
            ) {
                throw new RuntimeException(
                    "Khoản thanh toán không hợp lệ để quyết toán."
                );
            }

            if ($existingPendingRequest) {
                throw new RuntimeException(
                    "Đơn đang có khiếu nại hoặc yêu cầu hoàn tiền."
                );
            }

            $checkOutTimestamp = strtotime(
                $currentBooking["check_out"]
            );

            if (
                $checkOutTimestamp === false ||
                $checkOutTimestamp > time()
            ) {
                throw new RuntimeException(
                    "Chỉ có thể xác nhận sau thời gian trả phòng."
                );
            }

            /*
             * Tìm tài khoản QTV.
             */

            $adminStatement = $pdo->prepare(
                "SELECT id

                FROM users

                WHERE role = 'admin'

                    AND status = 'approved'

                ORDER BY id ASC

                LIMIT 1

                FOR UPDATE"
            );

            $adminStatement->execute();

            $adminId = (int) (
                $adminStatement->fetchColumn()
            );

            if ($adminId <= 0) {
                throw new RuntimeException(
                    "Chưa có tài khoản quản trị hợp lệ."
                );
            }

            $ownerId = (int) (
                $currentBooking["owner_id"]
            );

            $grossAmount = (int) round(
                (float) (
                    $currentBooking["total_amount"]
                )
            );

            $platformFee = (int) round(
                $grossAmount * 0.10
            );

            $ownerAmount =
                $grossAmount - $platformFee;

            /*
             * Tạo ví nếu chưa tồn tại.
             */

            $createWalletStatement = $pdo->prepare(
                "INSERT INTO wallets (
                    user_id,

                    pending_balance,

                    available_balance,

                    total_earned
                ) VALUES (
                    :user_id,

                    0,

                    0,

                    0
                )

                ON DUPLICATE KEY UPDATE

                    user_id = user_id"
            );

            $createWalletStatement->execute([
                "user_id" => $adminId
            ]);

            $createWalletStatement->execute([
                "user_id" => $ownerId
            ]);

            /*
             * Khóa hai ví để tránh cộng tiền trùng.
             */

            $walletStatement = $pdo->prepare(
                "SELECT
                    id,

                    user_id,

                    available_balance

                FROM wallets

                WHERE user_id IN (
                    :admin_id,

                    :owner_id
                )

                ORDER BY user_id ASC

                FOR UPDATE"
            );

            $walletStatement->execute([
                "admin_id" => $adminId,

                "owner_id" => $ownerId
            ]);

            $walletRows = $walletStatement->fetchAll(
                PDO::FETCH_ASSOC
            );

            $walletsByUser = [];

            foreach (
                $walletRows as $walletRow
            ) {
                $walletsByUser[
                    (int) $walletRow["user_id"]
                ] = $walletRow;
            }

            if (
                !isset(
                    $walletsByUser[$adminId]
                ) ||
                !isset(
                    $walletsByUser[$ownerId]
                )
            ) {
                throw new RuntimeException(
                    "Không tìm thấy ví nhận thanh toán."
                );
            }

            /*
             * Kiểm tra quyết toán trước đó.
             */

            $settlementCheckStatement = $pdo->prepare(
                "SELECT
                    id,

                    status

                FROM settlements

                WHERE booking_id = :booking_id

                LIMIT 1

                FOR UPDATE"
            );

            $settlementCheckStatement->execute([
                "booking_id" => $bookingId
            ]);

            $existingSettlement = (
                $settlementCheckStatement->fetch(
                    PDO::FETCH_ASSOC
                )
            );

            if (
                $existingSettlement &&
                $existingSettlement["status"]
                === "completed"
            ) {
                throw new RuntimeException(
                    "Đơn này đã được quyết toán."
                );
            }

            /*
             * Cập nhật số dư ví.
             */

            $creditWalletStatement = $pdo->prepare(
                "UPDATE wallets

                SET

                    available_balance =
                        available_balance +
                        :available_amount,

                    total_earned =
                        total_earned +
                        :earned_amount,

                    pending_balance =
                        GREATEST(
                            pending_balance -
                            :pending_amount,

                            0
                        )

                WHERE id = :wallet_id"
            );

            $creditWalletStatement->execute([
                "available_amount" => $platformFee,

                "earned_amount" => $platformFee,

                "pending_amount" => $platformFee,

                "wallet_id" => (
                    $walletsByUser[
                        $adminId
                    ]["id"]
                )
            ]);

            $creditWalletStatement->execute([
                "available_amount" => $ownerAmount,

                "earned_amount" => $ownerAmount,

                "pending_amount" => $ownerAmount,

                "wallet_id" => (
                    $walletsByUser[
                        $ownerId
                    ]["id"]
                )
            ]);

            /*
             * Lấy số dư sau khi cộng.
             */

            $balanceStatement = $pdo->prepare(
                "SELECT available_balance

                FROM wallets

                WHERE id = :wallet_id

                LIMIT 1"
            );

            $balanceStatement->execute([
                "wallet_id" => (
                    $walletsByUser[
                        $adminId
                    ]["id"]
                )
            ]);

            $adminBalanceAfter = (float) (
                $balanceStatement->fetchColumn()
            );

            $balanceStatement->execute([
                "wallet_id" => (
                    $walletsByUser[
                        $ownerId
                    ]["id"]
                )
            ]);

            $ownerBalanceAfter = (float) (
                $balanceStatement->fetchColumn()
            );

            /*
             * Ghi lịch sử giao dịch ví.
             */

            $walletTransactionStatement =
                $pdo->prepare(
                    "INSERT INTO wallet_transactions (
                        wallet_id,

                        booking_id,

                        transaction_type,

                        direction,

                        amount,

                        balance_after,

                        description,

                        status
                    ) VALUES (
                        :wallet_id,

                        :booking_id,

                        :transaction_type,

                        'credit',

                        :amount,

                        :balance_after,

                        :description,

                        'completed'
                    )"
                );

            $walletTransactionStatement->execute([
                "wallet_id" => (
                    $walletsByUser[
                        $adminId
                    ]["id"]
                ),

                "booking_id" => $bookingId,

                "transaction_type" =>
                    "platform_fee",

                "amount" => $platformFee,

                "balance_after" =>
                    $adminBalanceAfter,

                "description" =>
                    "Phí nền tảng của đơn " .
                    $currentBooking[
                        "booking_code"
                    ]
            ]);

            $walletTransactionStatement->execute([
                "wallet_id" => (
                    $walletsByUser[
                        $ownerId
                    ]["id"]
                ),

                "booking_id" => $bookingId,

                "transaction_type" =>
                    "owner_income",

                "amount" => $ownerAmount,

                "balance_after" =>
                    $ownerBalanceAfter,

                "description" =>
                    "Thu nhập từ đơn " .
                    $currentBooking[
                        "booking_code"
                    ]
            ]);

            /*
             * Lưu thông tin quyết toán.
             */

            if ($existingSettlement) {
                $updateSettlementStatement =
                    $pdo->prepare(
                        "UPDATE settlements

                        SET

                            owner_id = :owner_id,

                            gross_amount =
                                :gross_amount,

                            platform_fee =
                                :platform_fee,

                            owner_amount =
                                :owner_amount,

                            status = 'completed',

                            settled_at = NOW()

                        WHERE id = :id"
                    );

                $updateSettlementStatement->execute([
                    "owner_id" => $ownerId,

                    "gross_amount" => $grossAmount,

                    "platform_fee" => $platformFee,

                    "owner_amount" => $ownerAmount,

                    "id" => (
                        $existingSettlement["id"]
                    )
                ]);
            } else {
                $insertSettlementStatement =
                    $pdo->prepare(
                        "INSERT INTO settlements (
                            booking_id,

                            owner_id,

                            gross_amount,

                            platform_fee,

                            owner_amount,

                            status,

                            settled_at
                        ) VALUES (
                            :booking_id,

                            :owner_id,

                            :gross_amount,

                            :platform_fee,

                            :owner_amount,

                            'completed',

                            NOW()
                        )"
                    );

                $insertSettlementStatement->execute([
                    "booking_id" => $bookingId,

                    "owner_id" => $ownerId,

                    "gross_amount" => $grossAmount,

                    "platform_fee" => $platformFee,

                    "owner_amount" => $ownerAmount
                ]);
            }

            /*
             * Đánh dấu thanh toán đã quyết toán.
             */

            $updatePaymentStatement = $pdo->prepare(
                "UPDATE payments

                SET status = 'settled'

                WHERE booking_id = :booking_id

                    AND status = 'held'"
            );

            $updatePaymentStatement->execute([
                "booking_id" => $bookingId
            ]);

            /*
             * Đánh dấu đơn đã hoàn thành.
             */

            $completeBookingStatement =
                $pdo->prepare(
                    "UPDATE bookings

                    SET status = 'completed'

                    WHERE id = :booking_id

                        AND guest_id = :guest_id"
                );

            $completeBookingStatement->execute([
                "booking_id" => $bookingId,

                "guest_id" => $guestId
            ]);

            $pdo->commit();

            header(
                "Location: bookings.php?success=completed"
            );

            exit;
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (
            $exception instanceof RuntimeException
        ) {
            $errors[] =
                $exception->getMessage();
        } else {
            error_log(
                $exception->getMessage()
            );

            $errors[] =
                "Không thể xử lý yêu cầu. Vui lòng thử lại.";
        }
    }
}

/*
 * Lấy lịch sử đặt phòng của khách.
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

        h.name AS homestay_name,

        h.slug AS homestay_slug,

        h.address,

        h.province,

        h.tourist_destination,

        owner.full_name AS owner_name,

        owner.phone AS owner_phone,

        p.status AS payment_status,

        p.transaction_code,

        latest_refund.reason AS refund_reason,

        latest_refund.status AS refund_status,

        latest_refund.description AS refund_description,

        latest_refund.admin_note,

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

    INNER JOIN users AS owner
        ON owner.id = h.owner_id

    LEFT JOIN payments AS p
        ON p.booking_id = b.id

    LEFT JOIN refund_requests AS latest_refund

        ON latest_refund.id = (
            SELECT rr.id

            FROM refund_requests AS rr

            WHERE rr.booking_id = b.id

            ORDER BY
                rr.created_at DESC,
                rr.id DESC

            LIMIT 1
        )

    WHERE b.guest_id = :guest_id

    ORDER BY b.created_at DESC"
);

$bookingStatement->execute([
    "guest_id" => $guestId
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

foreach ($allBookings as $bookingItem) {
    if (
        in_array(
            $bookingItem["status"],
            ["pending_payment", "funds_held"],
            true
        )
    ) {
        $waitingBookings++;
    }

    if (
        $bookingItem["status"]
        === "confirmed"
    ) {
        $confirmedBookings++;
    }

    if (
        $bookingItem["status"]
        === "completed"
    ) {
        $completedBookings++;
    }
}

/*
 * Lọc trạng thái.
 */

$statusFilters = [
    "all" =>
        "Tất cả đơn",

    "pending_payment" =>
        "Chờ thanh toán",

    "funds_held" =>
        "Chờ xác nhận",

    "confirmed" =>
        "Đã xác nhận",

    "completed" =>
        "Đã hoàn thành",

    "cancelled" =>
        "Đã hủy",

    "refunded" =>
        "Đã hoàn tiền",

    "disputed" =>
        "Đang chờ xử lý"
];

$selectedStatus = $_GET["status"] ?? "all";

if (
    !array_key_exists(
        $selectedStatus,
        $statusFilters
    )
) {
    $selectedStatus = "all";
}

$bookings = array_values(
    array_filter(
        $allBookings,

        function ($booking) use (
            $selectedStatus
        ) {
            return
                $selectedStatus === "all" ||
                $booking["status"] ===
                    $selectedStatus;
        }
    )
);

/*
 * Trạng thái hiển thị.
 */

$statusLabels = [
    "pending_payment" =>
        "Chờ thanh toán",

    "funds_held" =>
        "Chờ chủ homestay xác nhận",

    "confirmed" =>
        "Đã xác nhận",

    "completed" =>
        "Đã hoàn thành",

    "cancelled" =>
        "Đã hủy",

    "refunded" =>
        "Đã hoàn tiền",

    "disputed" =>
        "Đang chờ xử lý"
];

$bookingTypeLabels = [
    "hourly" =>
        "Thuê theo giờ",

    "overnight" =>
        "Thuê qua đêm",

    "daytime" =>
        "Thuê ban ngày",

    "day_night" =>
        "Thuê ngày đêm"
];

$refundReasonLabels = [
    "guest_cancelled" =>
        "Yêu cầu hủy đặt phòng",

    "host_cancelled" =>
        "Chủ homestay hủy hoặc từ chối",

    "power_outage" =>
        "Mất điện hoặc gián đoạn điện",

    "service_issue" =>
        "Phòng hoặc dịch vụ không đúng mô tả",

    "other" =>
        "Vấn đề khác"
];

$successMessages = [
    "cancelled" =>
        "Đã hủy đơn đặt phòng thành công.",

    "refund_requested" =>
        "Yêu cầu hủy và hoàn tiền đã được gửi tới QTV.",

    "complaint_sent" =>
        "Khiếu nại đã được gửi tới QTV.",

    "completed" =>
        "Bạn đã xác nhận hoàn thành kỳ nghỉ."
];

$successKey = $_GET["success"] ?? "";

$successMessage =
    $successMessages[$successKey] ?? "";

/*
 * Định dạng tiền.
 */

function guestBookingMoney($amount)
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

function guestBookingDate($dateTime)
{
    $timestamp = strtotime(
        (string) $dateTime
    );

    if ($timestamp === false) {
        return "Chưa xác định";
    }

    return date(
        "d/m/Y H:i",
        $timestamp
    );
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
        Lịch sử đặt phòng - Đi Đâu Đây
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        body {
            background: #f5f8f6;
        }

        .guest-booking-wrapper {
            width: min(
                1180px,
                calc(100% - 36px)
            );

            margin: 0 auto;

            padding: 44px 0 90px;
        }

        .guest-booking-heading {
            margin-bottom: 25px;
        }

        .guest-booking-heading small {
            color: #df7860;

            font-size: 11px;

            font-weight: 800;

            letter-spacing: 0.08em;

            text-transform: uppercase;
        }

        .guest-booking-heading h1 {
            margin: 9px 0 6px;

            color: #193a30;

            font-size: 37px;
        }

        .guest-booking-heading p {
            margin: 0;

            color: #74817a;

            font-size: 14px;
        }

        .guest-booking-stats {
            display: grid;

            grid-template-columns:
                repeat(
                    4,
                    minmax(0, 1fr)
                );

            gap: 16px;

            margin-bottom: 23px;
        }

        .guest-booking-stat {
            padding: 19px;

            border: 1px solid #e0e8e3;

            border-radius: 15px;

            background: #ffffff;
        }

        .guest-booking-stat small {
            display: block;

            color: #75827a;

            font-size: 11px;

            font-weight: 750;

            text-transform: uppercase;
        }

        .guest-booking-stat strong {
            display: block;

            margin-top: 12px;

            color: #18503e;

            font-size: 27px;
        }

        .guest-filter-bar {
            margin-bottom: 20px;

            display: flex;

            gap: 9px;

            overflow-x: auto;
        }

        .guest-filter-link {
            padding: 10px 14px;

            border: 1px solid #dfe7e2;

            border-radius: 999px;

            color: #52625a;

            background: #ffffff;

            font-size: 12px;

            text-decoration: none;

            white-space: nowrap;
        }

        .guest-filter-link.active {
            border-color: #205d48;

            color: #ffffff;

            background: #205d48;
        }

        .guest-success-message {
            margin-bottom: 18px;

            padding: 14px 17px;

            border: 1px solid #cfe8d7;

            border-radius: 11px;

            color: #286644;

            background: #edf8f0;
        }

        .guest-error-message {
            margin-bottom: 18px;

            padding: 14px 17px;

            border: 1px solid #efcdcd;

            border-radius: 11px;

            color: #994747;

            background: #fff1f1;
        }

        .booking-history-list {
            display: grid;

            gap: 18px;
        }

        .booking-history-card {
            overflow: hidden;

            border: 1px solid #dfe7e2;

            border-radius: 18px;

            background: #ffffff;
        }

        .booking-history-top {
            padding: 16px 20px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            border-bottom: 1px solid #e8eeea;
        }

        .booking-history-code {
            color: #225341;

            font-size: 13px;

            font-weight: 850;
        }

        .booking-history-created {
            display: block;

            margin-top: 5px;

            color: #839088;

            font-size: 11px;
        }

        .booking-history-status {
            padding: 8px 12px;

            border-radius: 999px;

            font-size: 11px;

            font-weight: 800;

            white-space: nowrap;
        }

        .booking-history-status.pending_payment,
        .booking-history-status.funds_held {
            color: #90631f;

            background: #fff2da;
        }

        .booking-history-status.confirmed {
            color: #246646;

            background: #e8f5ed;
        }

        .booking-history-status.completed {
            color: #285d83;

            background: #e9f2fa;
        }

        .booking-history-status.disputed,
        .booking-history-status.cancelled,
        .booking-history-status.refunded {
            color: #a24545;

            background: #fcecec;
        }

        .booking-history-content {
            padding: 20px;

            display: grid;

            grid-template-columns:
                160px
                minmax(0, 1fr)
                235px;

            gap: 20px;
        }

        .booking-history-image {
            height: 122px;

            overflow: hidden;

            border-radius: 12px;

            background: #eaf0ec;
        }

        .booking-history-image img {
            width: 100%;

            height: 100%;

            display: block;

            object-fit: cover;
        }

        .booking-history-information h2 {
            margin: 0 0 8px;

            color: #203a30;

            font-size: 20px;
        }

        .booking-history-information p {
            margin: 6px 0;

            color: #697770;

            font-size: 13px;
        }

        .booking-history-side {
            padding-left: 18px;

            border-left: 1px solid #e8edeb;
        }

        .booking-history-side small {
            color: #7b8780;

            font-size: 12px;
        }

        .booking-history-total {
            display: block;

            margin: 8px 0 15px;

            color: #18503e;

            font-size: 22px;
        }

        .booking-payment-note {
            color: #78857d;

            font-size: 12px;

            line-height: 1.6;
        }

        .booking-issue-box {
            margin: 0 20px 17px;

            padding: 14px;

            border: 1px solid #f1dec2;

            border-radius: 11px;

            color: #795825;

            background: #fff8ed;

            font-size: 13px;

            line-height: 1.6;
        }

        .booking-history-actions {
            padding: 15px 20px;

            display: flex;

            justify-content: flex-end;

            gap: 10px;

            flex-wrap: wrap;

            border-top: 1px solid #e8edeb;
        }

        .booking-history-actions form {
            margin: 0;
        }

        .guest-action-button {
            padding: 10px 14px;

            border: 1px solid transparent;

            border-radius: 9px;

            font-size: 12px;

            font-weight: 800;

            text-decoration: none;

            cursor: pointer;
        }

        .guest-action-view {
            border-color: #dce7e1;

            color: #295542;

            background: #ffffff;
        }

        .guest-action-pay,
        .guest-action-complete {
            color: #ffffff;

            background: #205d48;
        }

        .guest-action-cancel {
            color: #965f25;

            background: #fff0dd;
        }

        .guest-action-complaint {
            color: #a44747;

            background: #fdeeee;
        }

        .guest-action-note {
            align-self: center;

            color: #7b8781;

            font-size: 12px;
        }

        .empty-booking-history {
            padding: 75px 25px;

            border: 1px solid #dfe7e2;

            border-radius: 17px;

            color: #738079;

            background: #ffffff;

            text-align: center;
        }

        .empty-booking-history h2 {
            margin: 0 0 10px;

            color: #294037;
        }

        .booking-modal {
            position: fixed;

            z-index: 30;

            inset: 0;

            padding: 20px;

            display: none;

            align-items: center;

            justify-content: center;

            background: rgba(
                18,
                34,
                27,
                0.56
            );
        }

        .booking-modal.open {
            display: flex;
        }

        .booking-modal-box {
            width: min(
                510px,
                100%
            );

            padding: 23px;

            border-radius: 17px;

            background: #ffffff;
        }

        .booking-modal-box h2 {
            margin: 0 0 8px;

            color: #243a31;
        }

        .booking-modal-box p {
            margin: 0 0 18px;

            color: #76827b;

            font-size: 13px;
        }

        .booking-modal-group {
            margin-bottom: 16px;
        }

        .booking-modal-group label {
            display: block;

            margin-bottom: 7px;

            color: #354b40;

            font-size: 13px;

            font-weight: 700;
        }

        .booking-modal-group select,
        .booking-modal-group textarea {
            width: 100%;

            padding: 11px;

            border: 1px solid #dce5df;

            border-radius: 9px;

            color: #34473d;

            background: #ffffff;

            font: inherit;
        }

        .booking-modal-group textarea {
            min-height: 110px;

            resize: vertical;
        }

        .booking-modal-actions {
            display: flex;

            justify-content: flex-end;

            gap: 10px;
        }

        .booking-modal-close {
            padding: 10px 14px;

            border: 1px solid #dce5df;

            border-radius: 9px;

            color: #4b5c53;

            background: #ffffff;

            cursor: pointer;
        }

        .booking-modal-submit {
            padding: 10px 14px;

            border: none;

            border-radius: 9px;

            color: #ffffff;

            background: #205d48;

            cursor: pointer;
        }

        @media (max-width: 850px) {
            .guest-booking-stats {
                grid-template-columns:
                    repeat(
                        2,
                        minmax(0, 1fr)
                    );
            }

            .booking-history-content {
                grid-template-columns:
                    120px
                    minmax(0, 1fr);
            }

            .booking-history-side {
                grid-column: 1 / -1;

                padding-left: 0;

                border-left: none;

                border-top:
                    1px solid #e8edeb;

                padding-top: 15px;
            }
        }

        @media (max-width: 550px) {
            .guest-booking-stats {
                grid-template-columns: 1fr;
            }

            .booking-history-content {
                grid-template-columns: 1fr;
            }

            .booking-history-image {
                height: 180px;
            }

            .booking-history-top {
                align-items: flex-start;

                flex-direction: column;
            }
        }
        
                .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        } 
        .guest-booking-nav {
    display: flex;
    align-items: center;
    justify-content: center;
}

.guest-back-home-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-height: 42px;
    padding: 0 20px;

    border: 1px solid #18533f;
    border-radius: 10px;

    color: #18533f;
    background: #ffffff;

    font-size: 14px;
    font-weight: 700;
    text-decoration: none;

    transition: all 0.2s ease;
}

.guest-back-home-button:hover {
    color: #ffffff;
    background: #18533f;

    transform: translateY(-1px);
}

@media (max-width: 768px) {
    .guest-back-home-button {
        min-height: 38px;
        padding: 0 14px;
        font-size: 13px;
    }
}

    </style>
</head>

<body>

<header class="site-header">

    <div class="container navbar">

        <a
            href="../index.php"
            class="logo"
        >
            Đi Đâu Đây
        </a>

        <div class="nav-actions">

            <span class="user-name">

                Xin chào,

                <?php
                echo htmlspecialchars(
                    currentUserName()
                );
                ?>

            </span>

            <a
                href="../index.php"
                class="guest-back-home-button"
            >
                Trang chính
            </a>

            <a
                href="../auth/logout.php"
                class="button button-primary"
            >
                Đăng xuất
            </a>

        </div>

    </div>

</header>

<main class="guest-booking-wrapper">

    <div class="guest-booking-heading">

        <small>
            CHUYẾN ĐI CỦA BẠN
        </small>

        <h1>
            Lịch sử đặt phòng
        </h1>

        <p>
            Theo dõi trạng thái, gửi yêu cầu hủy
            và phản ánh vấn đề khi cần.
        </p>

    </div>

    <?php if (
        $successMessage !== ""
    ): ?>

        <div class="guest-success-message">

            <?php
            echo htmlspecialchars(
                $successMessage
            );
            ?>

        </div>

    <?php endif; ?>

    <?php if (
        !empty($errors)
    ): ?>

        <div class="guest-error-message">

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

    <section class="guest-booking-stats">

        <article class="guest-booking-stat">

            <small>
                Tổng đơn
            </small>

            <strong>

                <?php
                echo $totalBookings;
                ?>

            </strong>

        </article>

        <article class="guest-booking-stat">

            <small>
                Đang chờ
            </small>

            <strong>

                <?php
                echo $waitingBookings;
                ?>

            </strong>

        </article>

        <article class="guest-booking-stat">

            <small>
                Đã xác nhận
            </small>

            <strong>

                <?php
                echo $confirmedBookings;
                ?>

            </strong>

        </article>

        <article class="guest-booking-stat">

            <small>
                Đã hoàn thành
            </small>

            <strong>

                <?php
                echo $completedBookings;
                ?>

            </strong>

        </article>

    </section>

    <nav class="guest-filter-bar">

        <?php foreach (
            $statusFilters as
            $statusValue => $statusName
        ): ?>

            <a
                href="bookings.php?status=<?php
                echo urlencode(
                    $statusValue
                );
                ?>"
                class="guest-filter-link <?php
                echo (
                    $selectedStatus ===
                    $statusValue
                )
                    ? "active"
                    : "";
                ?>"
            >

                <?php
                echo htmlspecialchars(
                    $statusName
                );
                ?>

            </a>

        <?php endforeach; ?>

    </nav>

    <?php if (
        empty($bookings)
    ): ?>

        <section class="empty-booking-history">

            <h2>
                Chưa có đơn đặt phòng phù hợp
            </h2>

            <p>
                Khi bạn đặt homestay, thông tin
                sẽ xuất hiện tại đây.
            </p>

            <br>

            <a
                href="../index.php#homestays"
                class="button button-primary"
            >
                Khám phá homestay
            </a>

        </section>

    <?php else: ?>

        <section class="booking-history-list">

            <?php foreach (
                $bookings as $booking
            ): ?>

                <?php

                $bookingStatus =
                    $booking["status"];

                $statusLabel =
                    $statusLabels[
                        $bookingStatus
                    ] ??
                    "Không xác định";

                if (
                    $bookingStatus === "disputed" &&
                    $booking["refund_reason"]
                    === "guest_cancelled"
                ) {
                    $statusLabel =
                        "Đang chờ hủy / hoàn tiền";
                }

                if (
                    $bookingStatus === "disputed" &&
                    $booking["refund_reason"]
                    !== "guest_cancelled"
                ) {
                    $statusLabel =
                        "Đang chờ xử lý khiếu nại";
                }

                $bookingType =
                    $bookingTypeLabels[
                        $booking["booking_type"]
                    ] ??
                    "Đặt phòng";

                $checkInTimestamp = strtotime(
                    $booking["check_in"]
                );

                $checkOutTimestamp = strtotime(
                    $booking["check_out"]
                );

                $canCancel =
                    in_array(
                        $bookingStatus,

                        [
                            "pending_payment",
                            "funds_held",
                            "confirmed"
                        ],

                        true
                    ) &&
                    $checkInTimestamp !== false &&
                    $checkInTimestamp > time();

                $canComplain =
                    in_array(
                        $bookingStatus,

                        [
                            "funds_held",
                            "confirmed"
                        ],

                        true
                    ) &&
                    $booking["payment_status"]
                    === "held";

                $canComplete =
                    $bookingStatus === "confirmed" &&
                    $checkOutTimestamp !== false &&
                    $checkOutTimestamp <= time();

                $detailUrl =
                    "../homestay.php?slug=" .
                    urlencode(
                        $booking["homestay_slug"]
                    );

                $coverImage = "";

                if (
                    !empty(
                        $booking["cover_image"]
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

                <article
                    class="booking-history-card"
                >

                    <div
                        class="booking-history-top"
                    >

                        <div>

                            <span
                                class="booking-history-code"
                            >

                                Mã đơn:

                                <?php
                                echo htmlspecialchars(
                                    $booking[
                                        "booking_code"
                                    ]
                                );
                                ?>

                            </span>

                            <span
                                class="booking-history-created"
                            >

                                Đặt lúc:

                                <?php
                                echo guestBookingDate(
                                    $booking[
                                        "created_at"
                                    ]
                                );
                                ?>

                            </span>

                        </div>

                        <span
                            class="booking-history-status <?php
                            echo htmlspecialchars(
                                $bookingStatus
                            );
                            ?>"
                        >

                            <?php
                            echo htmlspecialchars(
                                $statusLabel
                            );
                            ?>

                        </span>

                    </div>

                    <div
                        class="booking-history-content"
                    >

                        <div
                            class="booking-history-image"
                        >

                            <?php if (
                                $coverImage !== ""
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

                        <div
                            class="booking-history-information"
                        >

                            <h2>

                                <?php
                                echo htmlspecialchars(
                                    $booking[
                                        "homestay_name"
                                    ]
                                );
                                ?>

                            </h2>

                            <p>

                                <?php
                                echo htmlspecialchars(
                                    $booking[
                                        "tourist_destination"
                                    ]
                                );
                                ?>

                                ·

                                <?php
                                echo htmlspecialchars(
                                    $booking[
                                        "province"
                                    ]
                                );
                                ?>

                            </p>

                            <p>

                                <?php
                                echo htmlspecialchars(
                                    $bookingType
                                );
                                ?>

                                ·

                                <?php
                                echo (int) (
                                    $booking[
                                        "guest_count"
                                    ]
                                );
                                ?>

                                khách

                            </p>

                            <p>

                                Nhận phòng:

                                <?php
                                echo guestBookingDate(
                                    $booking[
                                        "check_in"
                                    ]
                                );
                                ?>

                            </p>

                            <p>

                                Trả phòng:

                                <?php
                                echo guestBookingDate(
                                    $booking[
                                        "check_out"
                                    ]
                                );
                                ?>

                            </p>

                            <p>

                                Chủ homestay:

                                <?php
                                echo htmlspecialchars(
                                    $booking[
                                        "owner_name"
                                    ]
                                );
                                ?>

                                ·

                                <?php
                                echo htmlspecialchars(
                                    $booking[
                                        "owner_phone"
                                    ]
                                );
                                ?>

                            </p>

                        </div>

                        <aside
                            class="booking-history-side"
                        >

                            <small>
                                Tổng tiền đặt phòng
                            </small>

                            <strong
                                class="booking-history-total"
                            >

                                <?php
                                echo guestBookingMoney(
                                    $booking[
                                        "total_amount"
                                    ]
                                );
                                ?>

                            </strong>

                            <p
                                class="booking-payment-note"
                            >

                                <?php if (
                                    $booking[
                                        "payment_status"
                                    ] === "held"
                                ): ?>

                                    Khoản thanh toán
                                    đang được website
                                    giữ an toàn.

                                <?php elseif (
                                    $booking[
                                        "payment_status"
                                    ] === "settled"
                                ): ?>

                                    Kỳ nghỉ đã hoàn thành.

                                <?php elseif (
                                    $booking[
                                        "payment_status"
                                    ] === "refunded"
                                ): ?>

                                    Khoản thanh toán
                                    đã được hoàn lại.

                                <?php else: ?>

                                    Đơn chưa hoàn tất
                                    thanh toán.

                                <?php endif; ?>

                            </p>

                        </aside>

                    </div>

                    <?php if (
                        $bookingStatus === "disputed"
                    ): ?>

                        <div
                            class="booking-issue-box"
                        >

                            <strong>

                                <?php

                                $refundReason =
                                    $booking[
                                        "refund_reason"
                                    ] ??
                                    "other";

                                echo htmlspecialchars(
                                    $refundReasonLabels[
                                        $refundReason
                                    ] ??
                                    "Yêu cầu hỗ trợ"
                                );

                                ?>

                            </strong>

                            <?php if (
                                !empty(
                                    $booking[
                                        "refund_description"
                                    ]
                                )
                            ): ?>

                                <br>

                                <?php
                                echo htmlspecialchars(
                                    $booking[
                                        "refund_description"
                                    ]
                                );
                                ?>

                            <?php endif; ?>

                            <?php if (
                                !empty(
                                    $booking[
                                        "admin_note"
                                    ]
                                )
                            ): ?>

                                <br>

                                Phản hồi của QTV:

                                <?php
                                echo htmlspecialchars(
                                    $booking[
                                        "admin_note"
                                    ]
                                );
                                ?>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                    <div
                        class="booking-history-actions"
                    >

                        <a
                            href="<?php
                            echo htmlspecialchars(
                                $detailUrl
                            );
                            ?>"
                            class="guest-action-button guest-action-view"
                        >
                            Xem homestay
                        </a>

                        <?php if (
                            $bookingStatus
                            === "pending_payment"
                        ): ?>

                            <a
                                href="payment.php?booking_id=<?php
                                echo (int) (
                                    $booking["id"]
                                );
                                ?>"
                                class="guest-action-button guest-action-pay"
                            >
                                Thanh toán
                            </a>

                        <?php endif; ?>

                        <?php if (
                            $canCancel
                        ): ?>

                            <button
                                type="button"
                                class="guest-action-button guest-action-cancel"
                                data-modal-action="cancel_booking"
                                data-booking-id="<?php
                                echo (int) (
                                    $booking["id"]
                                );
                                ?>"
                            >
                                Yêu cầu hủy
                            </button>

                        <?php endif; ?>

                        <?php if (
                            $canComplain
                        ): ?>

                            <button
                                type="button"
                                class="guest-action-button guest-action-complaint"
                                data-modal-action="submit_complaint"
                                data-booking-id="<?php
                                echo (int) (
                                    $booking["id"]
                                );
                                ?>"
                            >
                                Khiếu nại
                            </button>

                        <?php endif; ?>

                        <?php if (
                            $canComplete
                        ): ?>

                            <form method="POST">

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
                                        $booking["id"]
                                    );
                                    ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="complete_booking"
                                >

                                <button
                                    type="submit"
                                    class="guest-action-button guest-action-complete"
                                    onclick="return confirm('Xác nhận bạn đã hoàn thành kỳ nghỉ và không có khiếu nại?');"
                                >
                                    Xác nhận hoàn thành
                                </button>

                            </form>

                        <?php elseif (
                            $bookingStatus
                            === "confirmed"
                        ): ?>

                            <span
                                class="guest-action-note"
                            >
                                Có thể xác nhận
                                sau giờ trả phòng.
                            </span>

                        <?php endif; ?>

                    </div>

                </article>

            <?php endforeach; ?>

        </section>

    <?php endif; ?>

</main>

<div
    id="booking-action-modal"
    class="booking-modal"
>

    <div class="booking-modal-box">

        <h2 id="booking-modal-title">
            Gửi yêu cầu
        </h2>

        <p id="booking-modal-description">
            Yêu cầu sẽ được chuyển cho QTV xử lý.
        </p>

        <form method="POST">

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
                id="modal-booking-id"
            >

            <input
                type="hidden"
                name="action"
                id="modal-booking-action"
            >

            <div
                id="complaint-reason-group"
                class="booking-modal-group"
            >

                <label
                    for="complaint_reason"
                >
                    Vấn đề gặp phải
                </label>

                <select
                    id="complaint_reason"
                    name="complaint_reason"
                >

                    <option
                        value="power_outage"
                    >
                        Mất điện
                    </option>

                    <option
                        value="service_issue"
                    >
                        Phòng hoặc dịch vụ
                        không đúng mô tả
                    </option>

                    <option
                        value="host_cancelled"
                    >
                        Chủ homestay hủy
                        hoặc từ chối
                    </option>

                    <option
                        value="other"
                    >
                        Vấn đề khác
                    </option>

                </select>

            </div>

            <div class="booking-modal-group">

                <label for="description">
                    Nội dung yêu cầu
                </label>

                <textarea
                    id="description"
                    name="description"
                    placeholder="Mô tả lý do hủy hoặc vấn đề bạn gặp phải..."
                    required
                ></textarea>

            </div>

            <div
                class="booking-modal-actions"
            >

                <button
                    type="button"
                    id="close-booking-modal"
                    class="booking-modal-close"
                >
                    Đóng
                </button>

                <button
                    type="submit"
                    class="booking-modal-submit"
                >
                    Gửi yêu cầu
                </button>

            </div>

        </form>

    </div>

</div>

<script>
    const bookingModal = document.getElementById(
        "booking-action-modal"
    );

    const modalTitle = document.getElementById(
        "booking-modal-title"
    );

    const modalDescription = document.getElementById(
        "booking-modal-description"
    );

    const modalBookingId = document.getElementById(
        "modal-booking-id"
    );

    const modalBookingAction = document.getElementById(
        "modal-booking-action"
    );

    const complaintReasonGroup = document.getElementById(
        "complaint-reason-group"
    );

    const closeBookingModal = document.getElementById(
        "close-booking-modal"
    );

    document.querySelectorAll(
        "[data-modal-action]"
    ).forEach(function (button) {
        button.addEventListener(
            "click",

            function () {
                const action = button.dataset.modalAction;

                const bookingId = button.dataset.bookingId;

                modalBookingId.value = bookingId;

                modalBookingAction.value = action;

                if (action === "cancel_booking") {
                    modalTitle.textContent =
                        "Yêu cầu hủy đặt phòng";

                    modalDescription.textContent =
                        "Nếu đơn đã thanh toán, yêu cầu sẽ được gửi tới QTV để xử lý hoàn tiền.";

                    complaintReasonGroup.style.display =
                        "none";
                } else {
                    modalTitle.textContent =
                        "Gửi khiếu nại";

                    modalDescription.textContent =
                        "Mô tả vấn đề để QTV kiểm tra và hỗ trợ bạn.";

                    complaintReasonGroup.style.display =
                        "block";
                }

                bookingModal.classList.add(
                    "open"
                );
            }
        );
    });

    closeBookingModal.addEventListener(
        "click",

        function () {
            bookingModal.classList.remove(
                "open"
            );
        }
    );

    bookingModal.addEventListener(
        "click",

        function (event) {
            if (event.target === bookingModal) {
                bookingModal.classList.remove(
                    "open"
                );
            }
        }
    );
</script>

</body>
</html>