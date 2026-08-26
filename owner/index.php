<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("owner");

$ownerId = currentUserId();

$countStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_homestays,
        SUM(status = 'approved') AS active_homestays,
        SUM(status = 'maintenance') AS maintenance_homestays
     FROM homestays
     WHERE owner_id = :owner_id"
);

$countStatement->execute([
    "owner_id" => $ownerId
]);

$homestayCounts = $countStatement->fetch(PDO::FETCH_ASSOC);

$bookingStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM bookings
     INNER JOIN homestays
        ON homestays.id = bookings.homestay_id
     WHERE homestays.owner_id = :owner_id"
);

$bookingStatement->execute([
    "owner_id" => $ownerId
]);

$totalBookings = (int) $bookingStatement->fetchColumn();

$walletStatement = $pdo->prepare(
    "SELECT pending_balance, available_balance, total_earned
     FROM wallets
     WHERE user_id = :user_id
     LIMIT 1"
);

$walletStatement->execute([
    "user_id" => $ownerId
]);

$wallet = $walletStatement->fetch(PDO::FETCH_ASSOC);

if (!$wallet) {
    $wallet = [
        "pending_balance" => 0,
        "available_balance" => 0,
        "total_earned" => 0
    ];
}

$profileStatement = $pdo->prepare(
    "SELECT id
     FROM owner_profiles
     WHERE user_id = :user_id
     LIMIT 1"
);

$profileStatement->execute([
    "user_id" => $ownerId
]);

$hasProfile = (bool) $profileStatement->fetch();

$homestayStatement = $pdo->prepare(
    "SELECT id, name, province, tourist_destination,
            max_guests, price_per_hour,
            overnight_price, status
     FROM homestays
     WHERE owner_id = :owner_id
     ORDER BY created_at DESC
     LIMIT 5"
);

$homestayStatement->execute([
    "owner_id" => $ownerId
]);

$homestays = $homestayStatement->fetchAll(PDO::FETCH_ASSOC);

$statusNames = [
    "draft" => "Bản nháp",
    "pending" => "Chờ duyệt",
    "approved" => "Đang hoạt động",
    "rejected" => "Bị từ chối",
    "maintenance" => "Đang bảo trì"
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

    <title>Chủ homestay - Đi Đâu Đây</title>

    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >
</head>

<body>

<div class="dashboard">

    <aside class="sidebar">

        <a href="../index.php" class="sidebar-logo">
            Đi Đâu Đây
        </a>

        <div class="sidebar-label">
            KHU VỰC CHỦ HOMESTAY
        </div>

        <nav class="sidebar-menu">
            <a href="index.php" class="active">
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

            <a href="wallet.php">
                Ví của tôi
            </a>
        </nav>

        <div class="sidebar-bottom">

            <div class="sidebar-user">
                <div class="sidebar-avatar">CN</div>

                <div>
                    <strong>
                        <?php
                        echo htmlspecialchars(currentUserName());
                        ?>
                    </strong>

                    <small>Chủ homestay</small>
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
                <p>TRANG QUẢN LÝ</p>

                <h1>
                    Xin chào,
                    <?php
                    echo htmlspecialchars(currentUserName());
                    ?>
                </h1>
            </div>

            <div class="header-actions">
                <a
                    href="../index.php"
                    class="button button-outline"
                >
                    Xem trang khách
                </a>

                <a
                    href="add_homestay.php"
                    class="button button-primary"
                >
                    + Thêm homestay
                </a>
            </div>
        </header>

        <?php if (!$hasProfile): ?>

            <div
                class="alert"
                style="
                    color: #8b5c2c;
                    background: #fff2dc;
                    line-height: 1.6;
                "
            >
                <strong>Hồ sơ của bạn chưa hoàn thiện.</strong>

                Vui lòng bổ sung CCCD và tài khoản ngân hàng
                trước khi đăng homestay.

                <a
                    href="profile.php"
                    style="
                        margin-left: 8px;
                        color: #1f5b4b;
                        font-weight: bold;
                    "
                >
                    Hoàn thiện hồ sơ →
                </a>
            </div>

        <?php endif; ?>

        <section class="stat-grid">

            <article class="stat-card">
                <small>TỔNG HOMESTAY</small>

                <strong>
                    <?php
                    echo (int) $homestayCounts["total_homestays"];
                    ?>
                </strong>

                <span>Homestay đã tạo</span>
            </article>

            <article class="stat-card">
                <small>ĐANG HOẠT ĐỘNG</small>

                <strong>
                    <?php
                    echo (int) $homestayCounts["active_homestays"];
                    ?>
                </strong>

                <span>Đang hiển thị với khách</span>
            </article>

            <article class="stat-card">
                <small>ĐƠN ĐẶT PHÒNG</small>

                <strong>
                    <?php echo $totalBookings; ?>
                </strong>

                <span>Tổng số đơn đã nhận</span>
            </article>

            <article class="stat-card">
                <small>TỔNG THU NHẬP</small>

                <strong>
                    <?php
                    echo number_format(
                        $wallet["total_earned"],
                        0,
                        ",",
                        "."
                    );
                    ?>đ
                </strong>

                <span>90% từ các đơn hoàn tất</span>
            </article>

        </section>

        <section class="dashboard-grid">

            <div class="panel">

                <div class="panel-header">
                    <div>
                        <h2>Homestay của tôi</h2>

                        <p>
                            Những homestay được tạo gần đây.
                        </p>
                    </div>

                    <a
                        href="add_homestay.php"
                        class="button button-primary"
                    >
                        Thêm homestay
                    </a>
                </div>

                <?php if (empty($homestays)): ?>

                    <div class="empty-state">
                        Bạn chưa có homestay nào.

                        <br><br>

                        Hoàn thiện hồ sơ rồi thêm homestay đầu tiên.
                    </div>

                <?php else: ?>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                            <tr>
                                <th>HOMESTAY</th>
                                <th>ĐỊA ĐIỂM</th>
                                <th>GIÁ</th>
                                <th>TRẠNG THÁI</th>
                            </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($homestays as $homestay): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?php
                                            echo htmlspecialchars(
                                                $homestay["name"]
                                            );
                                            ?>
                                        </strong>

                                        <small>
                                            Tối đa
                                            <?php
                                            echo $homestay["max_guests"];
                                            ?>
                                            khách
                                        </small>
                                    </td>

                                    <td>
                                        <strong>
                                            <?php
                                            echo htmlspecialchars(
                                                $homestay[
                                                    "tourist_destination"
                                                ]
                                            );
                                            ?>
                                        </strong>

                                        <small>
                                            <?php
                                            echo htmlspecialchars(
                                                $homestay["province"]
                                            );
                                            ?>
                                        </small>
                                    </td>

                                    <td>
                                        <strong>
                                            <?php
                                            echo number_format(
                                                $homestay[
                                                    "price_per_hour"
                                                ],
                                                0,
                                                ",",
                                                "."
                                            );
                                            ?>đ/giờ
                                        </strong>

                                        <small>
                                            Qua đêm:
                                            <?php
                                            echo number_format(
                                                $homestay[
                                                    "overnight_price"
                                                ],
                                                0,
                                                ",",
                                                "."
                                            );
                                            ?>đ
                                        </small>
                                    </td>

                                    <td>
                                        <span
                                            class="status status-approved"
                                        >
                                            <?php
                                            echo htmlspecialchars(
                                                $statusNames[
                                                    $homestay["status"]
                                                ] ?? $homestay["status"]
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

            </div>

            <aside class="panel">

                <div class="wallet-card">
                    <small>SỐ DƯ KHẢ DỤNG</small>

                    <strong>
                        <?php
                        echo number_format(
                            $wallet["available_balance"],
                            0,
                            ",",
                            "."
                        );
                        ?>đ
                    </strong>

                    <div class="wallet-row">
                        <span>Đang chờ quyết toán</span>

                        <b>
                            <?php
                            echo number_format(
                                $wallet["pending_balance"],
                                0,
                                ",",
                                "."
                            );
                            ?>đ
                        </b>
                    </div>

                    <div class="wallet-row">
                        <span>Tổng thu nhập</span>

                        <b>
                            <?php
                            echo number_format(
                                $wallet["total_earned"],
                                0,
                                ",",
                                "."
                            );
                            ?>đ
                        </b>
                    </div>
                </div>

                <div class="panel-body">
                    <p
                        style="
                            color: #71807b;
                            font-size: 12px;
                            line-height: 1.7;
                        "
                    >
                        Khi khách hoàn thành kỳ nghỉ và không
                        có khiếu nại, 90% giá trị đơn sẽ được
                        cộng vào ví của bạn.
                    </p>
                </div>

            </aside>

        </section>

    </main>
</div>

</body>
</html>