<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("admin");

$requestStatement = $pdo->query(
    "SELECT
        profile_change_requests.*,
        users.full_name,
        users.email,
        users.phone
     FROM profile_change_requests
     INNER JOIN users
        ON users.id = profile_change_requests.owner_id
     ORDER BY
        profile_change_requests.status = 'pending' DESC,
        profile_change_requests.created_at DESC"
);

$requests = $requestStatement->fetchAll(PDO::FETCH_ASSOC);

$pendingCount = 0;

foreach ($requests as $request) {
    if ($request["status"] === "pending") {
        $pendingCount++;
    }
}

$statusNames = [
    "pending" => "Chờ xử lý",
    "approved" => "Đã chấp thuận",
    "rejected" => "Đã từ chối",
    "completed" => "Đã cập nhật"
];

$statusClasses = [
    "pending" => "status-pending",
    "approved" => "status-approved",
    "rejected" => "status-rejected",
    "completed" => "status-approved"
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

    <title>Yêu cầu sửa hồ sơ - Đi Đâu Đây</title>

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
            QUẢN TRỊ HỆ THỐNG
        </div>

        <nav class="sidebar-menu">
            <a href="index.php">
                Tổng quan
            </a>

            <a href="index.php#owner-approval">
                Duyệt tài khoản chủ homestay
            </a>

            <a href="profile_requests.php" class="active">
                Yêu cầu sửa hồ sơ
            </a>

            <a href="wallet.php">
                Ví QTV
            </a>

            <a href="bookings.php">
                Đơn đặt phòng
            </a>

            <a href="wallet.php">
                Hoàn tiền
            </a>
        </nav>

        <div class="sidebar-bottom">

            <div class="sidebar-user">
                <div class="sidebar-avatar">QT</div>

                <div>
                    <strong>
                        <?php
                        echo htmlspecialchars(currentUserName());
                        ?>
                    </strong>

                    <small>Quản trị viên</small>
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
                <p>QUẢN LÝ HỒ SƠ</p>

                <h1>Yêu cầu sửa thông tin</h1>
            </div>

            <a
                href="index.php"
                class="button button-outline"
            >
                ← Tổng quan
            </a>
        </header>

        <section class="stat-grid">

            <article class="stat-card">
                <small>YÊU CẦU CHỜ XỬ LÝ</small>

                <strong>
                    <?php echo $pendingCount; ?>
                </strong>

                <span>QTV cần kiểm tra</span>
            </article>

            <article class="stat-card">
                <small>TỔNG YÊU CẦU</small>

                <strong>
                    <?php echo count($requests); ?>
                </strong>

                <span>Bao gồm toàn bộ lịch sử</span>
            </article>

        </section>

        <section class="panel">

            <div class="panel-header">
                <div>
                    <h2>Danh sách yêu cầu</h2>

                    <p>
                        Kiểm tra lý do và cập nhật thông tin
                        giúp chủ homestay.
                    </p>
                </div>
            </div>

            <?php if (empty($requests)): ?>

                <div class="empty-state">
                    Chưa có yêu cầu thay đổi hồ sơ nào.
                </div>

            <?php else: ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>CHỦ HOMESTAY</th>
                            <th>LÝ DO</th>
                            <th>THÔNG TIN MUỐN ĐỔI</th>
                            <th>NGÀY GỬI</th>
                            <th>TRẠNG THÁI</th>
                            <th>THAO TÁC</th>
                        </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            $request["full_name"]
                                        );
                                        ?>
                                    </strong>

                                    <small>
                                        <?php
                                        echo htmlspecialchars(
                                            $request["email"]
                                        );
                                        ?>

                                        <br>

                                        <?php
                                        echo htmlspecialchars(
                                            $request["phone"]
                                        );
                                        ?>
                                    </small>
                                </td>

                                <td>
                                    <?php
                                    echo nl2br(
                                        htmlspecialchars(
                                            $request["reason"]
                                        )
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo nl2br(
                                        htmlspecialchars(
                                            $request[
                                                "requested_information"
                                            ]
                                        )
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo date(
                                        "d/m/Y H:i",
                                        strtotime(
                                            $request["created_at"]
                                        )
                                    );
                                    ?>
                                </td>

                                <td>
                                    <span
                                        class="status <?php
                                        echo $statusClasses[
                                            $request["status"]
                                        ] ?? "status-pending";
                                        ?>"
                                    >
                                        <?php
                                        echo htmlspecialchars(
                                            $statusNames[
                                                $request["status"]
                                            ] ?? $request["status"]
                                        );
                                        ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (
                                        $request["status"] === "pending"
                                    ): ?>

                                        <a
                                            href="profile_request_edit.php?id=<?php
                                            echo $request["id"];
                                            ?>"
                                            class="button button-primary small-button"
                                        >
                                            Xử lý
                                        </a>

                                    <?php else: ?>

                                        <small>Đã xử lý</small>

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