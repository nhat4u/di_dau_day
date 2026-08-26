<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("admin");

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$success = $_SESSION["admin_message"] ?? "";
unset($_SESSION["admin_message"]);

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $ownerId = (int) ($_POST["owner_id"] ?? 0);
    $decision = $_POST["decision"] ?? "";

    if (
        !hash_equals($_SESSION["csrf_token"], $csrfToken)
    ) {
        $error = "Yêu cầu không hợp lệ.";
    } elseif (
        !in_array($decision, ["approved", "rejected"], true)
    ) {
        $error = "Quyết định không hợp lệ.";
    } else {
        $reviewStatement = $pdo->prepare(
            "UPDATE users
             SET status = :status
             WHERE id = :id
               AND role = 'owner'
               AND status = 'pending'"
        );

        $reviewStatement->execute([
            "status" => $decision,
            "id" => $ownerId
        ]);

        if ($reviewStatement->rowCount() > 0) {
            $_SESSION["admin_message"] =
                $decision === "approved"
                    ? "Đã phê duyệt tài khoản chủ homestay."
                    : "Đã từ chối tài khoản chủ homestay.";

            header("Location: index.php");
            exit;
        }

        $error = "Tài khoản không tồn tại hoặc đã được xử lý.";
    }
}

$pendingOwnerCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM users
     WHERE role = 'owner'
       AND status = 'pending'"
)->fetchColumn();

$approvedOwnerCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM users
     WHERE role = 'owner'
       AND status = 'approved'"
)->fetchColumn();

$approvedHomestayCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM homestays
     WHERE status = 'approved'"
)->fetchColumn();

$heldMoney = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE status = 'held'"
)->fetchColumn();

$ownerStatement = $pdo->query(
    "SELECT id, full_name, email, phone, status, created_at
     FROM users
     WHERE role = 'owner'
     ORDER BY created_at DESC
     LIMIT 10"
);

$owners = $ownerStatement->fetchAll(PDO::FETCH_ASSOC);

$walletStatement = $pdo->prepare(
    "SELECT pending_balance, available_balance, total_earned
     FROM wallets
     WHERE user_id = :user_id
     LIMIT 1"
);

$walletStatement->execute([
    "user_id" => currentUserId()
]);

$wallet = $walletStatement->fetch(PDO::FETCH_ASSOC);

if (!$wallet) {
    $wallet = [
        "pending_balance" => 0,
        "available_balance" => 0,
        "total_earned" => 0
    ];
}

$statusNames = [
    "pending" => "Chờ duyệt",
    "approved" => "Đã duyệt",
    "rejected" => "Từ chối",
    "blocked" => "Đã khóa"
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

    <title>Quản trị - Đi Đâu Đây</title>

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
            <a
                href="index.php"
                id="menu-overview"
                class="active"
            >
                Tổng quan
            </a>

            <a
                href="index.php#owner-approval"
                id="menu-owner-approval"
            >
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

            <a href="refunds.php">
                Hoàn tiền
            </a>
        </nav>

        <div class="sidebar-bottom">

            <div class="sidebar-user">
                <div class="sidebar-avatar">QTV</div>

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
                <p>BẢNG ĐIỀU KHIỂN</p>

                <h1>
                    Tổng quan hệ thống
                </h1>
            </div>

            <div class="header-actions">
                <a
                    href="../index.php"
                    class="button button-outline"
                >
                    Xem trang khách
                </a>
            </div>
        </header>

        <?php if ($success !== ""): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <section class="stat-grid">

            <article class="stat-card">
                <small>TÀI KHOẢN CHỦ HOMESTAY ĐANG CHỜ QTV DUYỆT</small>

                <strong>
                    <?php echo $pendingOwnerCount; ?>
                </strong>

                <span>Tài khoản cần xử lý</span>
            </article>

            <article class="stat-card">
                <small>TÀI KHOẢN ĐÃ ĐƯỢC QTV DUYỆT</small>

                <strong>
                   <?php echo $approvedOwnerCount; ?>
                </strong>

    <span>Chủ homestay được phép đăng bài</span>
</article>
            <article class="stat-card">
                <small>HOMESTAY HOẠT ĐỘNG</small>

                <strong>
                    <?php echo $approvedHomestayCount; ?>
                </strong>

                <span>Đã được QTV phê duyệt</span>
            </article>

            <article class="stat-card">
                <small>TIỀN ĐANG GIỮ HỘ</small>

                <strong>
                    <?php
                    echo number_format(
                        $heldMoney,
                        0,
                        ",",
                        "."
                    );
                    ?>đ
                </strong>

                <span>Chưa được quyết toán</span>
            </article>

        </section>

        <section class="dashboard-grid">

            <div class="panel" id="owner-approval">

                <div class="panel-header">
                    <div>
                        <h2>Duyệt tài khoản chủ homestay</h2>

                    </div>
                </div>

                <?php if (empty($owners)): ?>

                    <div class="empty-state">
                        Chưa có tài khoản chủ homestay nào.
                    </div>

                <?php else: ?>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                            <tr>
                                <th>CHỦ HOMESTAY</th>
                                <th>LIÊN HỆ</th>
                                <th>TRẠNG THÁI</th>
                                <th>THAO TÁC</th>
                            </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($owners as $owner): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?php
                                            echo htmlspecialchars(
                                                $owner["full_name"]
                                            );
                                            ?>
                                        </strong>

                                        <small>
                                            Đăng ký:
                                            <?php
                                            echo date(
                                                "d/m/Y",
                                                strtotime(
                                                    $owner["created_at"]
                                                )
                                            );
                                            ?>
                                        </small>
                                    </td>

                                    <td>
                                        <strong>
                                            <?php
                                            echo htmlspecialchars(
                                                $owner["email"]
                                            );
                                            ?>
                                        </strong>

                                        <small>
                                            <?php
                                            echo htmlspecialchars(
                                                $owner["phone"]
                                            );
                                            ?>
                                        </small>
                                    </td>

                                    <td>
                                        <span
                                            class="status status-<?php
                                            echo htmlspecialchars(
                                                $owner["status"]
                                            );
                                            ?>"
                                        >
                                            <?php
                                            echo htmlspecialchars(
                                                $statusNames[
                                                    $owner["status"]
                                                ] ?? $owner["status"]
                                            );
                                            ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if (
                                            $owner["status"] === "pending"
                                        ): ?>

                                            <div class="table-actions">

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
                                                        name="owner_id"
                                                        value="<?php
                                                        echo $owner["id"];
                                                        ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="decision"
                                                        value="approved"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="button button-primary small-button"
                                                    >
                                                        Duyệt
                                                    </button>
                                                </form>

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
                                                        name="owner_id"
                                                        value="<?php
                                                        echo $owner["id"];
                                                        ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="decision"
                                                        value="rejected"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="button button-danger small-button"
                                                    >
                                                        Từ chối
                                                    </button>
                                                </form>

                                            </div>

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

            </div>

            <aside class="panel" id="wallet">

                <div class="wallet-card">
                    <small>SỐ DƯ KHẢ DỤNG CỦA QTV</small>

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
                        <span>Đang chờ</span>

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
                        <span>Tổng phí đã nhận</span>

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
                        Sau khi khách hoàn thành kỳ nghỉ và
                        không có khiếu nại, 10% giá trị đơn
                        sẽ tự động được cộng vào ví QTV.
                    </p>
                </div>

            </aside>

        </section>

    </main>
</div>

<script>
    function updateAdminMenu() {
        const overviewMenu =
            document.getElementById("menu-overview");

        const ownerApprovalMenu =
            document.getElementById("menu-owner-approval");

        if (!overviewMenu || !ownerApprovalMenu) {
            return;
        }

        const isOwnerApproval =
            window.location.hash === "#owner-approval";

        overviewMenu.classList.toggle(
            "active",
            !isOwnerApproval
        );

        ownerApprovalMenu.classList.toggle(
            "active",
            isOwnerApproval
        );
    }

    window.addEventListener(
        "hashchange",
        updateAdminMenu
    );

    updateAdminMenu();
</script>

</body>
</html>