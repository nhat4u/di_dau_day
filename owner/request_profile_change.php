<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("owner");

$ownerId = currentUserId();

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
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

if (!$profileStatement->fetch()) {
    header("Location: profile.php");
    exit;
}

$errors = [];
$success = isset($_GET["sent"])
    ? "Đã gửi yêu cầu đến QTV."
    : "";

$reason = "";
$requestedInformation = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $reason = trim($_POST["reason"] ?? "");
    $requestedInformation = trim(
        $_POST["requested_information"] ?? ""
    );

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Yêu cầu không hợp lệ.";
    }

    if (strlen($reason) < 10) {
        $errors[] =
            "Vui lòng mô tả lý do thay đổi rõ hơn.";
    }

    if (strlen($requestedInformation) < 10) {
        $errors[] =
            "Vui lòng ghi rõ thông tin cần thay đổi.";
    }

    if (empty($errors)) {
        $pendingStatement = $pdo->prepare(
            "SELECT id
             FROM profile_change_requests
             WHERE owner_id = :owner_id
               AND status = 'pending'
             LIMIT 1"
        );

        $pendingStatement->execute([
            "owner_id" => $ownerId
        ]);

        if ($pendingStatement->fetch()) {
            $errors[] =
                "Bạn đã có một yêu cầu đang chờ QTV xử lý.";
        }
    }

    if (empty($errors)) {
        $insertStatement = $pdo->prepare(
            "INSERT INTO profile_change_requests (
                owner_id,
                reason,
                requested_information
             ) VALUES (
                :owner_id,
                :reason,
                :requested_information
             )"
        );

        $insertStatement->execute([
            "owner_id" => $ownerId,
            "reason" => $reason,
            "requested_information" =>
                $requestedInformation
        ]);

        header(
            "Location: request_profile_change.php?sent=1"
        );
        exit;
    }
}

$requestStatement = $pdo->prepare(
    "SELECT *
     FROM profile_change_requests
     WHERE owner_id = :owner_id
     ORDER BY created_at DESC"
);

$requestStatement->execute([
    "owner_id" => $ownerId
]);

$requests = $requestStatement->fetchAll(PDO::FETCH_ASSOC);

$statusNames = [
    "pending" => "Chờ QTV xử lý",
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

    <title>Yêu cầu đổi hồ sơ - Đi Đâu Đây</title>

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
            <a href="index.php">Tổng quan</a>

            <a href="profile.php" class="active">
                Hồ sơ chủ homestay
            </a>

            <a href="homestays.php">
                Homestay của tôi
            </a>

            <a href="add_homestay.php">
                Thêm homestay
            </a>

            <a href="bookings.php">Đơn đặt phòng</a>
            <a href="wallet.php">Ví của tôi</a>
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
                <p>YÊU CẦU QTV HỖ TRỢ</p>

                <h1>Thay đổi thông tin hồ sơ</h1>
            </div>

            <a
                href="profile.php"
                class="button button-outline"
            >
                ← Quay lại hồ sơ
            </a>
        </header>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div>
                        • <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ""): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <section class="panel form-panel">

            <div class="panel-header">
                <div>
                    <h2>Gửi yêu cầu thay đổi</h2>

                    <p>
                        QTV sẽ kiểm tra trước khi cập nhật hồ sơ.
                    </p>
                </div>
            </div>

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
                        <label for="reason">
                            Lý do cần thay đổi
                        </label>

                        <textarea
                            id="reason"
                            name="reason"
                            placeholder="Ví dụ: Tôi đã đổi tài khoản ngân hàng nhận tiền..."
                            required
                        ><?php
                        echo htmlspecialchars($reason);
                        ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="requested_information">
                            Thông tin muốn thay đổi
                        </label>

                        <textarea
                            id="requested_information"
                            name="requested_information"
                            placeholder="Ghi rõ thông tin cũ và thông tin mới..."
                            required
                        ><?php
                        echo htmlspecialchars(
                            $requestedInformation
                        );
                        ?></textarea>

                        <small class="form-help">
                            Không gửi mật khẩu tài khoản tại đây.
                        </small>
                    </div>

                </div>

                <div class="form-footer">
                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Gửi yêu cầu cho QTV
                    </button>
                </div>

            </form>

        </section>

        <br>

        <section class="panel">

            <div class="panel-header">
                <div>
                    <h2>Lịch sử yêu cầu</h2>

                    <p>
                        Theo dõi trạng thái xử lý từ QTV.
                    </p>
                </div>
            </div>

            <?php if (empty($requests)): ?>

                <div class="empty-state">
                    Bạn chưa gửi yêu cầu thay đổi nào.
                </div>

            <?php else: ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>NGÀY GỬI</th>
                            <th>LÝ DO</th>
                            <th>THÔNG TIN THAY ĐỔI</th>
                            <th>TRẠNG THÁI</th>
                        </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($requests as $request): ?>
                            <tr>
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