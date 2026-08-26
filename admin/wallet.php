<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("admin");

date_default_timezone_set("Asia/Ho_Chi_Minh");

$adminId = currentUserId();

/*
|--------------------------------------------------------------------------
| Tạo ví QTV nếu chưa tồn tại
|--------------------------------------------------------------------------
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

/*
|--------------------------------------------------------------------------
| Lấy thông tin ví QTV
|--------------------------------------------------------------------------
*/

$walletStatement = $pdo->prepare(
    "SELECT
        id,
        user_id,
        pending_balance,
        available_balance,
        total_earned,
        updated_at

     FROM wallets

     WHERE user_id = :user_id

     LIMIT 1"
);

$walletStatement->execute([
    "user_id" => $adminId
]);

$wallet = $walletStatement->fetch(
    PDO::FETCH_ASSOC
);

if (!$wallet) {
    exit("Không tìm thấy ví quản trị viên.");
}

$walletId = (int) $wallet["id"];

/*
|--------------------------------------------------------------------------
| Thống kê các khoản website đang giữ
|--------------------------------------------------------------------------
*/

$bookingSummaryStatement = $pdo->query(
    "SELECT

        COALESCE(
            SUM(
                CASE
                    WHEN payments.status = 'held'
                    THEN payments.amount

                    ELSE 0
                END
            ),
            0
        ) AS website_held_amount,

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status IN (
                        'funds_held',
                        'confirmed'
                    )

                    AND payments.status = 'held'

                    THEN payments.amount * 0.10

                    ELSE 0
                END
            ),
            0
        ) AS pending_admin_income,

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status = 'disputed'

                    AND payments.status = 'held'

                    THEN payments.amount

                    ELSE 0
                END
            ),
            0
        ) AS disputed_amount,

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status = 'disputed'

                    AND payments.status = 'held'

                    THEN 1

                    ELSE 0
                END
            ),
            0
        ) AS disputed_booking_count,

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status IN (
                        'funds_held',
                        'confirmed'
                    )

                    AND payments.status = 'held'

                    THEN 1

                    ELSE 0
                END
            ),
            0
        ) AS pending_booking_count

     FROM bookings

     LEFT JOIN payments

        ON payments.booking_id = bookings.id"
);

$bookingSummary = $bookingSummaryStatement->fetch(
    PDO::FETCH_ASSOC
);

$websiteHeldAmount = (float) (
    $bookingSummary["website_held_amount"] ?? 0
);

$pendingAdminIncome = (float) (
    $bookingSummary["pending_admin_income"] ?? 0
);

$disputedAmount = (float) (
    $bookingSummary["disputed_amount"] ?? 0
);

$disputedBookingCount = (int) (
    $bookingSummary["disputed_booking_count"] ?? 0
);

$pendingBookingCount = (int) (
    $bookingSummary["pending_booking_count"] ?? 0
);

/*
|--------------------------------------------------------------------------
| Thống kê đơn đã quyết toán
|--------------------------------------------------------------------------
*/

$settlementStatement = $pdo->query(
    "SELECT

        COUNT(*) AS completed_count,

        COALESCE(
            SUM(gross_amount),
            0
        ) AS gross_total,

        COALESCE(
            SUM(platform_fee),
            0
        ) AS platform_fee_total,

        COALESCE(
            SUM(owner_amount),
            0
        ) AS owner_amount_total

     FROM settlements

     WHERE status = 'completed'"
);

$settlementSummary = $settlementStatement->fetch(
    PDO::FETCH_ASSOC
);

$completedSettlementCount = (int) (
    $settlementSummary["completed_count"] ?? 0
);

$ownerAmountTotal = (float) (
    $settlementSummary["owner_amount_total"] ?? 0
);

/*
|--------------------------------------------------------------------------
| Lịch sử giao dịch ví QTV
|--------------------------------------------------------------------------
*/

$transactionStatement = $pdo->prepare(
    "SELECT

        wallet_transactions.id,

        wallet_transactions.transaction_type,

        wallet_transactions.direction,

        wallet_transactions.amount,

        wallet_transactions.balance_after,

        wallet_transactions.description,

        wallet_transactions.status,

        wallet_transactions.created_at,

        bookings.booking_code,

        homestays.name AS homestay_name

     FROM wallet_transactions

     LEFT JOIN bookings

        ON bookings.id =
            wallet_transactions.booking_id

     LEFT JOIN homestays

        ON homestays.id =
            bookings.homestay_id

     WHERE wallet_transactions.wallet_id = :wallet_id

     ORDER BY wallet_transactions.created_at DESC,

              wallet_transactions.id DESC

     LIMIT 100"
);

$transactionStatement->execute([
    "wallet_id" => $walletId
]);

$transactions = $transactionStatement->fetchAll(
    PDO::FETCH_ASSOC
);

/*
|--------------------------------------------------------------------------
| Hàm hỗ trợ
|--------------------------------------------------------------------------
*/

function adminWalletEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function adminWalletMoney($amount)
{
    return number_format(
        (float) $amount,
        0,
        ",",
        "."
    ) . "đ";
}

function adminWalletTransactionName($type)
{
    $names = [

        "platform_fee" =>
            "Nhận phí quản trị từ đơn đặt phòng",

        "owner_income" =>
            "Thu nhập chủ homestay",

        "withdrawal" =>
            "Rút tiền",

        "refund" =>
            "Hoàn tiền",

        "adjustment" =>
            "Điều chỉnh số dư"

    ];

    return $names[$type] ?? "Giao dịch ví";
}

function adminWalletStatusName($status)
{
    $names = [

        "pending" =>
            "Đang xử lý",

        "completed" =>
            "Thành công",

        "failed" =>
            "Thất bại"

    ];

    return $names[$status] ?? $status;
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
        Ví QTV - Đi Đâu Đây
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >

    <style>

        .admin-wallet-grid {
            display: grid;
            grid-template-columns: repeat(
                4,
                minmax(0, 1fr)
            );
            gap: 16px;
            margin-bottom: 22px;
        }

        .admin-wallet-card {
            min-height: 138px;
            padding: 20px;
            border: 1px solid #dfe7e2;
            border-radius: 14px;
            background: #ffffff;
        }

        .admin-wallet-card small {
            display: block;
            margin-bottom: 15px;
            color: #77827d;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        .admin-wallet-card strong {
            display: block;
            color: #14513b;
            font-size: 28px;
        }

        .admin-wallet-card span {
            display: block;
            margin-top: 10px;
            color: #8b9590;
            font-size: 12px;
        }

        .admin-wallet-warning {
            margin-bottom: 22px;
            padding: 16px 20px;
            border: 1px solid #efdfc5;
            border-radius: 12px;
            color: #875921;
            background: #fff6e8;
            font-size: 13px;
            line-height: 1.7;
        }

        .admin-wallet-layout {
            display: grid;
            grid-template-columns:
                minmax(0, 1fr)
                380px;
            gap: 20px;
            align-items: start;
        }

        .admin-wallet-panel {
            overflow: hidden;
            border: 1px solid #dfe7e2;
            border-radius: 15px;
            background: #ffffff;
        }

        .admin-wallet-panel-header {
            padding: 21px 23px;
            border-bottom: 1px solid #e8edea;
        }

        .admin-wallet-panel-header h2 {
            margin: 0 0 5px;
            color: #23322b;
            font-size: 20px;
        }

        .admin-wallet-panel-header p {
            margin: 0;
            color: #86908b;
            font-size: 13px;
        }

        .admin-wallet-table-wrap {
            overflow-x: auto;
        }

        .admin-wallet-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }

        .admin-wallet-table th {
            padding: 15px 18px;
            border-bottom: 1px solid #e8edea;
            color: #76827c;
            background: #f7f9f8;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-align: left;
            text-transform: uppercase;
        }

        .admin-wallet-table td {
            padding: 18px;
            border-bottom: 1px solid #edf1ef;
            color: #3c4943;
            font-size: 13px;
            vertical-align: top;
        }

        .admin-wallet-table tr:last-child td {
            border-bottom: none;
        }

        .admin-wallet-title {
            display: block;
            margin-bottom: 6px;
            color: #20372c;
            font-weight: 700;
        }

        .admin-wallet-meta {
            display: block;
            color: #87918c;
            font-size: 12px;
            line-height: 1.6;
        }

        .admin-wallet-credit {
            color: #178154;
            font-weight: 800;
        }

        .admin-wallet-debit {
            color: #c74b47;
            font-weight: 800;
        }

        .admin-wallet-status {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .admin-wallet-status-completed {
            color: #216947;
            background: #eaf6ee;
        }

        .admin-wallet-status-pending {
            color: #91601e;
            background: #fff4e3;
        }

        .admin-wallet-status-failed {
            color: #ab4545;
            background: #fcecec;
        }

        .admin-wallet-empty {
            padding: 70px 20px;
            color: #89938e;
            text-align: center;
        }

        .admin-wallet-empty strong {
            display: block;
            margin-bottom: 8px;
            color: #4d5e56;
        }

        .admin-wallet-balance {
            overflow: hidden;
            border: 1px solid #dfe7e2;
            border-radius: 15px;
            background: #ffffff;
        }

        .admin-wallet-balance-main {
            padding: 25px;
            color: #ffffff;
            background: #18533f;
        }

        .admin-wallet-balance-main small {
            display: block;
            color: rgba(
                255,
                255,
                255,
                0.78
            );
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.7px;
            text-transform: uppercase;
        }

        .admin-wallet-balance-main h2 {
            margin: 18px 0 20px;
            color: #ffffff;
            font-size: 36px;
        }

        .admin-wallet-balance-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 0;
            border-top: 1px solid rgba(
                255,
                255,
                255,
                0.17
            );
            font-size: 12px;
        }

        .admin-wallet-balance-row span {
            color: rgba(
                255,
                255,
                255,
                0.77
            );
        }

        .admin-wallet-description {
            padding: 20px 23px;
            color: #77827d;
            font-size: 13px;
            line-height: 1.7;
        }

        @media (max-width: 1100px) {

            .admin-wallet-grid {
                grid-template-columns: repeat(
                    2,
                    minmax(0, 1fr)
                );
            }

            .admin-wallet-layout {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 650px) {

            .admin-wallet-grid {
                grid-template-columns: 1fr;
            }

            .admin-wallet-balance-main h2 {
                font-size: 30px;
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

            <a
                href="wallet.php"
                class="active"
            >

                Ví QTV

            </a>

            <a href="bookings.php">

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

                        echo adminWalletEscape(
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

                    QUẢN LÝ DOANH THU HỆ THỐNG

                </p>

                <h1>

                    Ví QTV

                </h1>

            </div>

            <a
                href="index.php"
                class="button button-outline"
            >

                ← Tổng quan

            </a>

        </header>

        <section class="admin-wallet-grid">

            <article class="admin-wallet-card">

                <small>

                    Số dư khả dụng

                </small>

                <strong>

                    <?php

                    echo adminWalletMoney(
                        $wallet["available_balance"]
                    );

                    ?>

                </strong>

                <span>

                    Tiền đã cộng vào ví QTV

                </span>

            </article>

            <article class="admin-wallet-card">

                <small>

                    Website đang giữ

                </small>

                <strong>

                    <?php

                    echo adminWalletMoney(
                        $websiteHeldAmount
                    );

                    ?>

                </strong>

                <span>

                    Bao gồm đơn đang có khiếu nại

                </span>

            </article>

            <article class="admin-wallet-card">

                <small>

                    Phí quản trị đang chờ

                </small>

                <strong>

                    <?php

                    echo adminWalletMoney(
                        $pendingAdminIncome
                    );

                    ?>

                </strong>

                <span>

                    <?php

                    echo $pendingBookingCount;

                    ?>

                    đơn chưa quyết toán

                </span>

            </article>

            <article class="admin-wallet-card">

                <small>

                    Tổng phí đã nhận

                </small>

                <strong>

                    <?php

                    echo adminWalletMoney(
                        $wallet["total_earned"]
                    );

                    ?>

                </strong>

                <span>

                    <?php

                    echo $completedSettlementCount;

                    ?>

                    đơn đã hoàn tất

                </span>

            </article>

        </section>

        <?php if (
            $disputedBookingCount > 0
        ): ?>

            <div class="admin-wallet-warning">

                Có

                <strong>

                    <?php

                    echo $disputedBookingCount;

                    ?>

                </strong>

                đơn đang khiếu nại hoặc yêu cầu hoàn tiền.

                Website hiện đang giữ

                <strong>

                    <?php

                    echo adminWalletMoney(
                        $disputedAmount
                    );

                    ?>

                </strong>

                cho những đơn này.

            </div>

        <?php endif; ?>

        <div class="admin-wallet-layout">

            <section class="admin-wallet-panel">

                <div class="admin-wallet-panel-header">

                    <h2>

                        Lịch sử giao dịch ví QTV

                    </h2>

                    <p>

                        Theo dõi các khoản phí quản trị đã nhận
                        từ những đơn hoàn tất.

                    </p>

                </div>

                <?php if (
                    empty($transactions)
                ): ?>

                    <div class="admin-wallet-empty">

                        <strong>

                            Chưa có giao dịch nào

                        </strong>

                        Tiền sẽ xuất hiện sau khi đơn đặt phòng
                        được quyết toán thành công.

                    </div>

                <?php else: ?>

                    <div class="admin-wallet-table-wrap">

                        <table class="admin-wallet-table">

                            <thead>

                                <tr>

                                    <th>

                                        Giao dịch

                                    </th>

                                    <th>

                                        Đơn đặt phòng

                                    </th>

                                    <th>

                                        Số tiền

                                    </th>

                                    <th>

                                        Số dư sau giao dịch

                                    </th>

                                    <th>

                                        Trạng thái

                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $transactions
                                    as $transaction
                                ): ?>

                                    <?php

                                    $isCredit =
                                        $transaction["direction"]
                                        === "credit";

                                    $status =
                                        $transaction["status"];

                                    ?>

                                    <tr>

                                        <td>

                                            <span
                                                class="admin-wallet-title"
                                            >

                                                <?php

                                                echo adminWalletEscape(
                                                    adminWalletTransactionName(
                                                        $transaction[
                                                            "transaction_type"
                                                        ]
                                                    )
                                                );

                                                ?>

                                            </span>

                                            <span
                                                class="admin-wallet-meta"
                                            >

                                                <?php

                                                echo date(
                                                    "d/m/Y H:i",

                                                    strtotime(
                                                        $transaction[
                                                            "created_at"
                                                        ]
                                                    )
                                                );

                                                ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?php if (
                                                !empty(
                                                    $transaction[
                                                        "booking_code"
                                                    ]
                                                )
                                            ): ?>

                                                <span
                                                    class="admin-wallet-title"
                                                >

                                                    <?php

                                                    echo adminWalletEscape(
                                                        $transaction[
                                                            "booking_code"
                                                        ]
                                                    );

                                                    ?>

                                                </span>

                                                <span
                                                    class="admin-wallet-meta"
                                                >

                                                    <?php

                                                    echo adminWalletEscape(
                                                        $transaction[
                                                            "homestay_name"
                                                        ] ?? ""
                                                    );

                                                    ?>

                                                </span>

                                            <?php else: ?>

                                                —

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <span
                                                class="<?php

                                                echo $isCredit

                                                    ? "admin-wallet-credit"

                                                    : "admin-wallet-debit";

                                                ?>"
                                            >

                                                <?php

                                                echo $isCredit

                                                    ? "+ "

                                                    : "- ";

                                                echo adminWalletMoney(
                                                    $transaction[
                                                        "amount"
                                                    ]
                                                );

                                                ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?php

                                            echo adminWalletMoney(
                                                $transaction[
                                                    "balance_after"
                                                ]
                                            );

                                            ?>

                                        </td>

                                        <td>

                                            <span
                                                class="admin-wallet-status admin-wallet-status-<?php

                                                echo adminWalletEscape(
                                                    $status
                                                );

                                                ?>"
                                            >

                                                <?php

                                                echo adminWalletEscape(
                                                    adminWalletStatusName(
                                                        $status
                                                    )
                                                );

                                                ?>

                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

            <aside class="admin-wallet-balance">

                <div class="admin-wallet-balance-main">

                    <small>

                        Số dư khả dụng của QTV

                    </small>

                    <h2>

                        <?php

                        echo adminWalletMoney(
                            $wallet[
                                "available_balance"
                            ]
                        );

                        ?>

                    </h2>

                    <div class="admin-wallet-balance-row">

                        <span>

                            Phí quản trị đang chờ

                        </span>

                        <strong>

                            <?php

                            echo adminWalletMoney(
                                $pendingAdminIncome
                            );

                            ?>

                        </strong>

                    </div>

                    <div class="admin-wallet-balance-row">

                        <span>

                            Tổng phí đã nhận

                        </span>

                        <strong>

                            <?php

                            echo adminWalletMoney(
                                $wallet[
                                    "total_earned"
                                ]
                            );

                            ?>

                        </strong>

                    </div>

                    <div class="admin-wallet-balance-row">

                        <span>

                            Đã trả cho chủ homestay

                        </span>

                        <strong>

                            <?php

                            echo adminWalletMoney(
                                $ownerAmountTotal
                            );

                            ?>

                        </strong>

                    </div>

                </div>

                <div class="admin-wallet-description">

                    Sau khi đơn đặt phòng hoàn tất và không
                    có khiếu nại, hệ thống sẽ cộng 10% giá trị
                    đơn vào ví QTV.

                    <br><br>

                    Phần còn lại được cộng vào ví của chủ
                    homestay tương ứng.

                </div>

            </aside>

        </div>

    </main>

</div>

</body>

</html>