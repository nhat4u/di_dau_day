<?php

/*
|--------------------------------------------------------------------------
| Chỉ cho phép chạy bằng PHP trên dòng lệnh
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== "cli") {

    http_response_code(403);

    exit(
        "Tác vụ này chỉ được chạy bằng PHP CLI."
    );

}

require_once __DIR__ . "/../config/database.php";

date_default_timezone_set(
    "Asia/Ho_Chi_Minh"
);

/*
|--------------------------------------------------------------------------
| Thời gian chờ sau khi trả phòng
|--------------------------------------------------------------------------
|
| 24 nghĩa là khách không xác nhận sau 24 giờ
| thì hệ thống tự quyết toán.
|
*/

const AUTO_SETTLE_DELAY_HOURS = 24;

/*
|--------------------------------------------------------------------------
| Hàm ghi thông báo
|--------------------------------------------------------------------------
*/

function autoSettleLog($message)
{

    echo "["

        . date("d/m/Y H:i:s")

        . "] "

        . $message

        . PHP_EOL;

}

/*
|--------------------------------------------------------------------------
| Tìm tài khoản QTV nhận 10%
|--------------------------------------------------------------------------
*/

$adminStatement = $pdo->prepare(
    "SELECT id

     FROM users

     WHERE role = 'admin'

     ORDER BY id ASC

     LIMIT 1"
);

$adminStatement->execute();

$admin = $adminStatement->fetch(
    PDO::FETCH_ASSOC
);

if (!$admin) {

    autoSettleLog(
        "Không tìm thấy tài khoản QTV."
    );

    exit(1);

}

$adminId = (int) $admin["id"];

/*
|--------------------------------------------------------------------------
| Xác định mốc thời gian đủ điều kiện
|--------------------------------------------------------------------------
*/

$cutoffDate = (
    new DateTimeImmutable(
        "now",

        new DateTimeZone(
            "Asia/Ho_Chi_Minh"
        )
    )
)

    ->modify(
        "-" . AUTO_SETTLE_DELAY_HOURS . " hours"
    )

    ->format(
        "Y-m-d H:i:s"
    );

autoSettleLog(
    "Bắt đầu kiểm tra đơn trả phòng trước "
    . $cutoffDate
);

/*
|--------------------------------------------------------------------------
| Chỉ lấy đơn:
|
| - Chủ homestay đã xác nhận.
| - Website đang giữ tiền.
| - Đã quá 24 giờ kể từ lúc trả phòng.
| - Không có yêu cầu hoàn tiền đang chờ.
|--------------------------------------------------------------------------
*/

$candidateStatement = $pdo->prepare(
    "SELECT

        bookings.id,

        bookings.booking_code

     FROM bookings

     INNER JOIN payments

        ON payments.booking_id = bookings.id

     WHERE bookings.status = 'confirmed'

       AND payments.status = 'held'

       AND bookings.check_out <= :cutoff_date

       AND NOT EXISTS (

            SELECT 1

            FROM refund_requests

            WHERE refund_requests.booking_id =
                bookings.id

              AND refund_requests.status =
                'pending'

       )

     ORDER BY bookings.check_out ASC

     LIMIT 100"
);

$candidateStatement->execute([

    "cutoff_date" => $cutoffDate

]);

$candidates = $candidateStatement->fetchAll(
    PDO::FETCH_ASSOC
);

if (empty($candidates)) {

    autoSettleLog(
        "Không có đơn nào đủ điều kiện quyết toán."
    );

    exit(0);

}

$successCount = 0;

$errorCount = 0;

/*
|--------------------------------------------------------------------------
| Quyết toán từng đơn
|--------------------------------------------------------------------------
*/

foreach (
    $candidates
    as $candidate
) {

    $bookingId = (int) $candidate["id"];

    $bookingCode =
        $candidate["booking_code"];

    try {

        $pdo->beginTransaction();

        /*
        |------------------------------------------------------------------
        | Khóa đơn để tránh chia tiền trùng
        |------------------------------------------------------------------
        */

        $bookingStatement = $pdo->prepare(
            "SELECT

                bookings.id,

                bookings.booking_code,

                bookings.total_amount,

                bookings.status,

                bookings.check_out,

                homestays.owner_id,

                payments.id AS payment_id,

                payments.status AS payment_status

             FROM bookings

             INNER JOIN homestays

                ON homestays.id =
                    bookings.homestay_id

             INNER JOIN payments

                ON payments.booking_id =
                    bookings.id

             WHERE bookings.id = :booking_id

             LIMIT 1

             FOR UPDATE"
        );

        $bookingStatement->execute([

            "booking_id" => $bookingId

        ]);

        $booking = $bookingStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$booking) {

            throw new RuntimeException(
                "Không tìm thấy đơn đặt phòng."
            );

        }

        if (
            $booking["status"] !== "confirmed"
        ) {

            throw new RuntimeException(
                "Đơn không còn ở trạng thái đã xác nhận."
            );

        }

        if (
            $booking["payment_status"] !== "held"
        ) {

            throw new RuntimeException(
                "Khoản thanh toán không còn được giữ."
            );

        }

        if (
            strtotime(
                $booking["check_out"]
            )

            >

            strtotime(
                $cutoffDate
            )
        ) {

            throw new RuntimeException(
                "Đơn chưa đủ thời gian tự quyết toán."
            );

        }

        /*
        |------------------------------------------------------------------
        | Kiểm tra lại khiếu nại và yêu cầu hoàn tiền
        |------------------------------------------------------------------
        */

        $refundStatement = $pdo->prepare(
            "SELECT id

             FROM refund_requests

             WHERE booking_id = :booking_id

               AND status = 'pending'

             LIMIT 1

             FOR UPDATE"
        );

        $refundStatement->execute([

            "booking_id" => $bookingId

        ]);

        if (
            $refundStatement->fetch()
        ) {

            throw new RuntimeException(
                "Đơn đang có khiếu nại hoặc yêu cầu hoàn tiền."
            );

        }

        /*
        |------------------------------------------------------------------
        | Kiểm tra lịch sử quyết toán
        |------------------------------------------------------------------
        */

        $existingSettlementStatement =
            $pdo->prepare(
                "SELECT

                    id,

                    status

                 FROM settlements

                 WHERE booking_id =
                    :booking_id

                 LIMIT 1

                 FOR UPDATE"
            );

        $existingSettlementStatement->execute([

            "booking_id" => $bookingId

        ]);

        $existingSettlement =
            $existingSettlementStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (
            $existingSettlement

            &&

            $existingSettlement["status"]
                === "completed"
        ) {

            throw new RuntimeException(
                "Đơn đã được quyết toán trước đó."
            );

        }

        /*
        |------------------------------------------------------------------
        | Tính khoản QTV và chủ homestay nhận
        |------------------------------------------------------------------
        */

        $ownerId = (int) $booking["owner_id"];

        $grossAmount = (float) (
            $booking["total_amount"]
        );

        if (
            $grossAmount <= 0
        ) {

            throw new RuntimeException(
                "Giá trị đơn đặt phòng không hợp lệ."
            );

        }

        $platformFee = round(
            $grossAmount * 0.10,
            0
        );

        $ownerAmount =
            $grossAmount - $platformFee;

        /*
        |------------------------------------------------------------------
        | Tạo ví nếu chưa tồn tại
        |------------------------------------------------------------------
        */

        $createWalletStatement =
            $pdo->prepare(
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
        |------------------------------------------------------------------
        | Khóa ví QTV
        |------------------------------------------------------------------
        */

        $adminWalletStatement =
            $pdo->prepare(
                "SELECT

                    id,

                    available_balance

                 FROM wallets

                 WHERE user_id =
                    :user_id

                 LIMIT 1

                 FOR UPDATE"
            );

        $adminWalletStatement->execute([

            "user_id" => $adminId

        ]);

        $adminWallet =
            $adminWalletStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$adminWallet) {

            throw new RuntimeException(
                "Không tìm thấy ví QTV."
            );

        }

        /*
        |------------------------------------------------------------------
        | Khóa ví chủ homestay
        |------------------------------------------------------------------
        */

        $ownerWalletStatement =
            $pdo->prepare(
                "SELECT

                    id,

                    available_balance

                 FROM wallets

                 WHERE user_id =
                    :user_id

                 LIMIT 1

                 FOR UPDATE"
            );

        $ownerWalletStatement->execute([

            "user_id" => $ownerId

        ]);

        $ownerWallet =
            $ownerWalletStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$ownerWallet) {

            throw new RuntimeException(
                "Không tìm thấy ví chủ homestay."
            );

        }

        /*
        |------------------------------------------------------------------
        | Cộng tiền vào ví QTV
        |------------------------------------------------------------------
        */

        $updateAdminWalletStatement =
            $pdo->prepare(
                "UPDATE wallets

                 SET

                    available_balance =

                        available_balance
                        + :available_amount,

                    total_earned =

                        total_earned
                        + :earned_amount,

                    pending_balance =

                        GREATEST(

                            pending_balance
                            - :pending_amount,

                            0

                        )

                 WHERE id = :wallet_id"
            );

        $updateAdminWalletStatement->execute([

            "available_amount" =>
                $platformFee,

            "earned_amount" =>
                $platformFee,

            "pending_amount" =>
                $platformFee,

            "wallet_id" =>
                $adminWallet["id"]

        ]);

        /*
        |------------------------------------------------------------------
        | Cộng tiền vào ví chủ homestay
        |------------------------------------------------------------------
        */

        $updateOwnerWalletStatement =
            $pdo->prepare(
                "UPDATE wallets

                 SET

                    available_balance =

                        available_balance
                        + :available_amount,

                    total_earned =

                        total_earned
                        + :earned_amount,

                    pending_balance =

                        GREATEST(

                            pending_balance
                            - :pending_amount,

                            0

                        )

                 WHERE id = :wallet_id"
            );

        $updateOwnerWalletStatement->execute([

            "available_amount" =>
                $ownerAmount,

            "earned_amount" =>
                $ownerAmount,

            "pending_amount" =>
                $ownerAmount,

            "wallet_id" =>
                $ownerWallet["id"]

        ]);

        /*
        |------------------------------------------------------------------
        | Tính số dư sau giao dịch
        |------------------------------------------------------------------
        */

        $adminBalanceAfter =

            (float) $adminWallet[
                "available_balance"
            ]

            +

            $platformFee;

        $ownerBalanceAfter =

            (float) $ownerWallet[
                "available_balance"
            ]

            +

            $ownerAmount;

        /*
        |------------------------------------------------------------------
        | Ghi lịch sử giao dịch
        |------------------------------------------------------------------
        */

        $insertTransactionStatement =
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

        /*
        |------------------------------------------------------------------
        | Giao dịch của QTV
        |------------------------------------------------------------------
        */

        $insertTransactionStatement->execute([

            "wallet_id" =>
                $adminWallet["id"],

            "booking_id" =>
                $bookingId,

            "transaction_type" =>
                "platform_fee",

            "amount" =>
                $platformFee,

            "balance_after" =>
                $adminBalanceAfter,

            "description" =>

                "Tự động nhận phí quản trị từ đơn "

                . $bookingCode

        ]);

        /*
        |------------------------------------------------------------------
        | Giao dịch của chủ homestay
        |------------------------------------------------------------------
        */

        $insertTransactionStatement->execute([

            "wallet_id" =>
                $ownerWallet["id"],

            "booking_id" =>
                $bookingId,

            "transaction_type" =>
                "owner_income",

            "amount" =>
                $ownerAmount,

            "balance_after" =>
                $ownerBalanceAfter,

            "description" =>

                "Tự động nhận thu nhập từ đơn "

                . $bookingCode

        ]);

        /*
        |------------------------------------------------------------------
        | Tạo hoặc cập nhật thông tin quyết toán
        |------------------------------------------------------------------
        */

        if (
            $existingSettlement
        ) {

            $updateSettlementStatement =
                $pdo->prepare(
                    "UPDATE settlements

                     SET

                        owner_id =
                            :owner_id,

                        gross_amount =
                            :gross_amount,

                        platform_fee =
                            :platform_fee,

                        owner_amount =
                            :owner_amount,

                        status =
                            'completed',

                        settled_at =
                            NOW()

                     WHERE id =
                        :settlement_id"
                );

            $updateSettlementStatement->execute([

                "owner_id" =>
                    $ownerId,

                "gross_amount" =>
                    $grossAmount,

                "platform_fee" =>
                    $platformFee,

                "owner_amount" =>
                    $ownerAmount,

                "settlement_id" =>
                    $existingSettlement[
                        "id"
                    ]

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

                "booking_id" =>
                    $bookingId,

                "owner_id" =>
                    $ownerId,

                "gross_amount" =>
                    $grossAmount,

                "platform_fee" =>
                    $platformFee,

                "owner_amount" =>
                    $ownerAmount

            ]);

        }

        /*
        |------------------------------------------------------------------
        | Đánh dấu khoản thanh toán đã quyết toán
        |------------------------------------------------------------------
        */

        $updatePaymentStatement =
            $pdo->prepare(
                "UPDATE payments

                 SET

                    status =
                        'settled'

                 WHERE booking_id =
                    :booking_id

                   AND status =
                    'held'"
            );

        $updatePaymentStatement->execute([

            "booking_id" =>

                $bookingId

        ]);

        if (
            $updatePaymentStatement->rowCount()
            !== 1
        ) {

            throw new RuntimeException(
                "Không thể cập nhật thanh toán."
            );

        }

        /*
        |------------------------------------------------------------------
        | Đánh dấu đơn đặt phòng đã hoàn tất
        |------------------------------------------------------------------
        */

        $updateBookingStatement =
            $pdo->prepare(
                "UPDATE bookings

                 SET

                    status =
                        'completed'

                 WHERE id =
                    :booking_id

                   AND status =
                    'confirmed'"
            );

        $updateBookingStatement->execute([

            "booking_id" =>

                $bookingId

        ]);

        if (
            $updateBookingStatement->rowCount()
            !== 1
        ) {

            throw new RuntimeException(
                "Không thể cập nhật trạng thái đơn."
            );

        }

        $pdo->commit();

        $successCount++;

        autoSettleLog(

            "Đã quyết toán đơn "

            . $bookingCode

            . " | QTV: "

            . number_format(
                $platformFee,
                0,
                ",",
                "."
            )

            . "đ"

            . " | Chủ homestay: "

            . number_format(
                $ownerAmount,
                0,
                ",",
                "."
            )

            . "đ"

        );

    } catch (
        Throwable $exception
    ) {

        if (
            $pdo->inTransaction()
        ) {

            $pdo->rollBack();

        }

        $errorCount++;

        autoSettleLog(

            "Không thể quyết toán đơn "

            . $bookingCode

            . ": "

            . $exception->getMessage()

        );

    }

}

/*
|--------------------------------------------------------------------------
| Tổng kết
|--------------------------------------------------------------------------
*/

autoSettleLog(

    "Hoàn tất. Thành công: "

    . $successCount

    . " | Lỗi: "

    . $errorCount

);

exit(

    $errorCount > 0

        ? 1

        : 0

);