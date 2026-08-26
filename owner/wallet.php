<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("owner");

date_default_timezone_set("Asia/Ho_Chi_Minh");

$ownerId = currentUserId();

/*
|--------------------------------------------------------------------------
| Tạo ví nếu chủ homestay chưa có
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
    "user_id" => $ownerId
]);

/*
|--------------------------------------------------------------------------
| Lấy thông tin ví
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
    "user_id" => $ownerId
]);

$wallet = $walletStatement->fetch(PDO::FETCH_ASSOC);

if (!$wallet) {
    exit("Không tìm thấy thông tin ví của chủ homestay.");
}

$walletId = (int) $wallet["id"];

/*
|--------------------------------------------------------------------------
| Tính tiền đang chờ quyết toán và tiền đang bị giữ
|--------------------------------------------------------------------------
*/

$bookingSummaryStatement = $pdo->prepare(
    "SELECT

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status IN (
                        'funds_held',
                        'confirmed'
                    )
                    AND payments.status = 'held'
                    THEN bookings.total_amount * 0.90

                    ELSE 0
                END
            ),
            0
        ) AS pending_income,

        COALESCE(
            SUM(
                CASE
                    WHEN bookings.status = 'disputed'
                    AND payments.status = 'held'
                    THEN bookings.total_amount

                    ELSE 0
                END
            ),
            0
        ) AS disputed_amount,

        COALESCE(
            SUM(
                CASE
                    WHEN payments.status = 'held'
                    THEN bookings.total_amount

                    ELSE 0
                END
            ),
            0
        ) AS website_held_amount,

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
        ) AS pending_booking_count,

        SUM(
            CASE
                WHEN bookings.status = 'disputed'
                AND payments.status = 'held'
                THEN 1

                ELSE 0
            END
        ) AS disputed_booking_count

     FROM bookings

     INNER JOIN homestays
        ON homestays.id = bookings.homestay_id

     LEFT JOIN payments
        ON payments.booking_id = bookings.id

     WHERE homestays.owner_id = :owner_id"
);

$bookingSummaryStatement->execute([
    "owner_id" => $ownerId
]);

$bookingSummary = $bookingSummaryStatement->fetch(
    PDO::FETCH_ASSOC
);

$pendingIncome = (float) (
    $bookingSummary["pending_income"] ?? 0
);

$disputedAmount = (float) (
    $bookingSummary["disputed_amount"] ?? 0
);

$websiteHeldAmount = (float) (
    $bookingSummary["website_held_amount"] ?? 0
);

$pendingBookingCount = (int) (
    $bookingSummary["pending_booking_count"] ?? 0
);

$disputedBookingCount = (int) (
    $bookingSummary["disputed_booking_count"] ?? 0
);

/*
|--------------------------------------------------------------------------
| Thống kê những đơn đã quyết toán
|--------------------------------------------------------------------------
*/

$settlementStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS completed_count,

        COALESCE(
            SUM(owner_amount),
            0
        ) AS settled_total

     FROM settlements

     WHERE owner_id = :owner_id

       AND status = 'completed'"
);

$settlementStatement->execute([
    "owner_id" => $ownerId
]);

$settlementSummary = $settlementStatement->fetch(
    PDO::FETCH_ASSOC
);

$completedSettlementCount = (int) (
    $settlementSummary["completed_count"] ?? 0
);

/*
|--------------------------------------------------------------------------
| Lịch sử biến động số dư
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
| Hàm hỗ trợ hiển thị
|--------------------------------------------------------------------------
*/

function walletEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function walletMoney($amount)
{
    return number_format(
        (float) $amount,
        0,
        ",",
        "."
    ) . "đ";
}

function walletTransactionName($type)
{
    $names = [
        "owner_income" => "Nhận tiền từ đơn đặt phòng",
        "platform_fee" => "Phí nền tảng",
        "withdrawal" => "Rút tiền",
        "refund" => "Hoàn tiền",
        "adjustment" => "Điều chỉnh số dư"
    ];

    return $names[$type] ?? "Giao dịch ví";
}

function walletTransactionStatus($status)
{
    $names = [
        "pending" => "Đang xử lý",
        "completed" => "Thành công",
        "failed" => "Thất bại"
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

    <title>Ví của tôi - Đi Đâu Đây</title>

    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >

    <style>

        .wallet-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .wallet-summary-card {
            min-height: 136px;
            padding: 20px;
            border: 1px solid #e0e7e3;
            border-radius: 14px;
            background: #ffffff;
        }

        .wallet-summary-card small {
            display: block;
            margin-bottom: 14px;
            color: #77837e;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        .wallet-summary-card strong {
            display: block;
            color: #14503b;
            font-size: 28px;
            line-height: 1.2;
        }

        .wallet-summary-card span {
            display: block;
            margin-top: 10px;
            color: #8a9590;
            font-size: 12px;
        }

        .wallet-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 370px;
            gap: 20px;
            align-items: start;
        }

        .wallet-panel {
            overflow: hidden;
            border: 1px solid #e0e7e3;
            border-radius: 15px;
            background: #ffffff;
        }

        .wallet-panel-header {
            padding: 21px 23px;
            border-bottom: 1px solid #e7ece9;
        }

        .wallet-panel-header h2 {
            margin: 0 0 5px;
            color: #21302a;
            font-size: 20px;
        }

        .wallet-panel-header p {
            margin: 0;
            color: #84908a;
            font-size: 13px;
        }

        .wallet-balance-card {
            overflow: hidden;
            border: 1px solid #e0e7e3;
            border-radius: 15px;
            background: #ffffff;
        }

        .wallet-balance-main {
            padding: 25px;
            color: #ffffff;
            background: #18533f;
        }

        .wallet-balance-main small {
            display: block;
            color: rgba(255, 255, 255, 0.78);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.7px;
            text-transform: uppercase;
        }

        .wallet-balance-main h2 {
            margin: 18px 0 20px;
            color: #ffffff;
            font-size: 36px;
        }

        .wallet-balance-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.17);
            font-size: 12px;
        }

        .wallet-balance-row span {
            color: rgba(255, 255, 255, 0.76);
        }

        .wallet-balance-description {
            padding: 19px 23px;
            color: #76827c;
            font-size: 13px;
            line-height: 1.7;
        }

        .wallet-notice {
            margin-bottom: 22px;
            padding: 16px 19px;
            border: 1px solid #d5e8dc;
            border-radius: 12px;
            color: #245b44;
            background: #edf7f0;
            font-size: 13px;
            line-height: 1.7;
        }

        .wallet-notice-warning {
            border-color: #f0dfc3;
            color: #865920;
            background: #fff6e7;
        }

        .wallet-table-wrap {
            overflow-x: auto;
        }

        .wallet-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }

        .wallet-table th {
            padding: 15px 18px;
            border-bottom: 1px solid #e7ece9;
            color: #77837e;
            background: #f7f9f8;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-align: left;
            text-transform: uppercase;
        }

        .wallet-table td {
            padding: 17px 18px;
            border-bottom: 1px solid #edf1ef;
            color: #3b4842;
            font-size: 13px;
            vertical-align: top;
        }

        .wallet-table tbody tr:last-child td {
            border-bottom: none;
        }

        .wallet-transaction-title {
            display: block;
            margin-bottom: 5px;
            color: #1d342a;
            font-weight: 700;
        }

        .wallet-transaction-meta {
            display: block;
            color: #87918c;
            font-size: 12px;
            line-height: 1.6;
        }

        .wallet-amount-credit {
            color: #168255;
            font-weight: 800;
        }

        .wallet-amount-debit {
            color: #cc524b;
            font-weight: 800;
        }

        .wallet-status {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .wallet-status-completed {
            color: #206a47;
            background: #eaf6ee;
        }

        .wallet-status-pending {
            color: #99631d;
            background: #fff4e4;
        }

        .wallet-status-failed {
            color: #ac4444;
            background: #fcecec;
        }

        .wallet-empty {
            padding: 70px 20px;
            color: #8a9590;
            text-align: center;
        }

        .wallet-empty strong {
            display: block;
            margin-bottom: 8px;
            color: #53635d;
            font-size: 15px;
        }

        @media (max-width: 1100px) {

            .wallet-summary-grid {
                grid-template-columns: repeat(
                    2,
                    minmax(0, 1fr)
                );
            }

            .wallet-layout {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 650px) {

            .wallet-summary-grid {
                grid-template-columns: 1fr;
            }

            .wallet-balance-main h2 {
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

            <a href="bookings.php">
                Đơn đặt phòng
            </a>

            <a
                href="wallet.php"
                class="active"
            >
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
                        echo walletEscape(
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
                    QUẢN LÝ THU NHẬP
                </p>

                <h1>
                    Ví của tôi
                </h1>

            </div>

            <a
                href="bookings.php"
                class="button button-outline"
            >
                ← Xem đơn đặt phòng
            </a>

        </header>

        <section class="wallet-summary-grid">

            <article class="wallet-summary-card">

                <small>
                    Số dư khả dụng
                </small>

                <strong>
                    <?php
                    echo walletMoney(
                        $wallet["available_balance"]
                    );
                    ?>
                </strong>

                <span>
                    Tiền đã được cộng vào ví
                </span>

            </article>

            <article class="wallet-summary-card">

                <small>
                    Đang chờ quyết toán
                </small>

                <strong>
                    <?php
                    echo walletMoney(
                        $pendingIncome
                    );
                    ?>
                </strong>

                <span>

                    <?php
                    echo $pendingBookingCount;
                    ?>

                    đơn chưa hoàn tất

                </span>

            </article>

            <article class="wallet-summary-card">

                <small>
                    Tổng thu nhập
                </small>

                <strong>
                    <?php
                    echo walletMoney(
                        $wallet["total_earned"]
                    );
                    ?>
                </strong>

                <span>

                    <?php
                    echo $completedSettlementCount;
                    ?>

                    đơn đã quyết toán

                </span>

            </article>

            <article class="wallet-summary-card">

                <small>
                    Tiền đang có khiếu nại
                </small>

                <strong>
                    <?php
                    echo walletMoney(
                        $disputedAmount
                    );
                    ?>
                </strong>

                <span>

                    <?php
                    echo $disputedBookingCount;
                    ?>

                    đơn chờ QTV xử lý

                </span>

            </article>

        </section>

        <?php if ($disputedBookingCount > 0): ?>

            <div class="wallet-notice wallet-notice-warning">

                Hiện có

                <strong>
                    <?php
                    echo $disputedBookingCount;
                    ?>
                </strong>

                đơn đang khiếu nại hoặc yêu cầu hoàn tiền.

                Website đang tạm giữ

                <strong>
                    <?php
                    echo walletMoney(
                        $disputedAmount
                    );
                    ?>
                </strong>

                cho đến khi quản trị viên xử lý xong.

            </div>

        <?php elseif ($pendingBookingCount > 0): ?>

            <div class="wallet-notice">

                Bạn có

                <strong>
                    <?php
                    echo $pendingBookingCount;
                    ?>
                </strong>

                đơn đang chờ hoàn tất.

                Thu nhập dự kiến được cộng vào ví là

                <strong>
                    <?php
                    echo walletMoney(
                        $pendingIncome
                    );
                    ?>
                </strong>.

            </div>

        <?php endif; ?>

        <div class="wallet-layout">

            <section class="wallet-panel">

                <div class="wallet-panel-header">

                    <h2>
                        Lịch sử giao dịch
                    </h2>

                    <p>
                        Theo dõi những lần cộng tiền và thay đổi
                        số dư trong ví.
                    </p>

                </div>

                <?php if (empty($transactions)): ?>

                    <div class="wallet-empty">

                        <strong>
                            Chưa có giao dịch nào
                        </strong>

                        Tiền sẽ xuất hiện sau khi đơn đặt phòng
                        được quyết toán thành công.

                    </div>

                <?php else: ?>

                    <div class="wallet-table-wrap">

                        <table class="wallet-table">

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

                                    $transactionStatus =
                                        $transaction["status"];

                                    $statusClass =
                                        "wallet-status-"
                                        . $transactionStatus;

                                    ?>

                                    <tr>

                                        <td>

                                            <span
                                                class="wallet-transaction-title"
                                            >

                                                <?php
                                                echo walletEscape(
                                                    walletTransactionName(
                                                        $transaction[
                                                            "transaction_type"
                                                        ]
                                                    )
                                                );
                                                ?>

                                            </span>

                                            <span
                                                class="wallet-transaction-meta"
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
                                                    class="wallet-transaction-title"
                                                >

                                                    <?php
                                                    echo walletEscape(
                                                        $transaction[
                                                            "booking_code"
                                                        ]
                                                    );
                                                    ?>

                                                </span>

                                                <span
                                                    class="wallet-transaction-meta"
                                                >

                                                    <?php
                                                    echo walletEscape(
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
                                                    ? "wallet-amount-credit"
                                                    : "wallet-amount-debit";
                                                ?>"
                                            >

                                                <?php
                                                echo $isCredit
                                                    ? "+ "
                                                    : "- ";

                                                echo walletMoney(
                                                    $transaction[
                                                        "amount"
                                                    ]
                                                );
                                                ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?php
                                            echo walletMoney(
                                                $transaction[
                                                    "balance_after"
                                                ]
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <span
                                                class="wallet-status <?php
                                                echo walletEscape(
                                                    $statusClass
                                                );
                                                ?>"
                                            >

                                                <?php
                                                echo walletEscape(
                                                    walletTransactionStatus(
                                                        $transactionStatus
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

            <aside class="wallet-balance-card">

                <div class="wallet-balance-main">

                    <small>
                        Số dư khả dụng
                    </small>

                    <h2>

                        <?php
                        echo walletMoney(
                            $wallet["available_balance"]
                        );
                        ?>

                    </h2>

                    <div class="wallet-balance-row">

                        <span>
                            Đang chờ quyết toán
                        </span>

                        <strong>

                            <?php
                            echo walletMoney(
                                $pendingIncome
                            );
                            ?>

                        </strong>

                    </div>

                    <div class="wallet-balance-row">

                        <span>
                            Tổng thu nhập
                        </span>

                        <strong>

                            <?php
                            echo walletMoney(
                                $wallet["total_earned"]
                            );
                            ?>

                        </strong>

                    </div>

                    <div class="wallet-balance-row">

                        <span>
                            Website đang giữ
                        </span>

                        <strong>

                            <?php
                            echo walletMoney(
                                $websiteHeldAmount
                            );
                            ?>

                        </strong>

                    </div>

                </div>

                <div class="wallet-balance-description">

                    Khi khách hoàn tất kỳ nghỉ và không có khiếu
                    nại, 90% giá trị đơn đặt phòng sẽ được cộng
                    vào ví của bạn.

                    <br><br>

                    Nếu có yêu cầu hoàn tiền hoặc khiếu nại,
                    khoản tiền liên quan sẽ được giữ lại cho
                    đến khi quản trị viên xử lý.

                </div>

            </aside>

        </div>

    </main>

</div>

</body>

</html>