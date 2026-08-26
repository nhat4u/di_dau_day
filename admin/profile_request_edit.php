<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("admin");

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$requestId = (int) ($_GET["id"] ?? $_POST["request_id"] ?? 0);

$requestStatement = $pdo->prepare(
    "SELECT
        profile_change_requests.*,
        users.full_name,
        users.email,
        users.phone,
        owner_profiles.citizen_id,
        owner_profiles.address,
        owner_profiles.bank_name,
        owner_profiles.bank_account,
        owner_profiles.bank_account_name
     FROM profile_change_requests
     INNER JOIN users
        ON users.id = profile_change_requests.owner_id
     INNER JOIN owner_profiles
        ON owner_profiles.user_id = users.id
     WHERE profile_change_requests.id = :id
     LIMIT 1"
);

$requestStatement->execute([
    "id" => $requestId
]);

$request = $requestStatement->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    exit("Không tìm thấy yêu cầu thay đổi hồ sơ.");
}

$errors = [];

$citizenId = $request["citizen_id"];
$address = $request["address"];
$bankName = $request["bank_name"];
$bankAccount = $request["bank_account"];
$bankAccountName = $request["bank_account_name"];
$adminNote = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $action = $_POST["action"] ?? "";

    $citizenId = trim($_POST["citizen_id"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $bankName = trim($_POST["bank_name"] ?? "");
    $bankAccount = trim($_POST["bank_account"] ?? "");
    $bankAccountName = trim(
        $_POST["bank_account_name"] ?? ""
    );

    $adminNote = trim($_POST["admin_note"] ?? "");

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Yêu cầu không hợp lệ.";
    }

    if ($request["status"] !== "pending") {
        $errors[] = "Yêu cầu này đã được xử lý.";
    }

    if ($action === "reject") {
        if (strlen($adminNote) < 5) {
            $errors[] =
                "Vui lòng ghi lý do từ chối cho chủ homestay.";
        }

        if (empty($errors)) {
            $rejectStatement = $pdo->prepare(
                "UPDATE profile_change_requests
                 SET status = 'rejected',
                     admin_note = :admin_note,
                     processed_by = :processed_by,
                     processed_at = NOW()
                 WHERE id = :id
                   AND status = 'pending'"
            );

            $rejectStatement->execute([
                "admin_note" => $adminNote,
                "processed_by" => currentUserId(),
                "id" => $requestId
            ]);

            header("Location: profile_requests.php");
            exit;
        }
    } elseif ($action === "complete") {
        if (!preg_match("/^[0-9]{12}$/", $citizenId)) {
            $errors[] =
                "Số CCCD phải gồm đúng 12 chữ số.";
        }

        if (strlen($address) < 10) {
            $errors[] =
                "Địa chỉ thường trú chưa đầy đủ.";
        }

        if ($bankName === "") {
            $errors[] = "Vui lòng chọn ngân hàng.";
        }

        if (
            !preg_match(
                "/^[0-9]{6,20}$/",
                $bankAccount
            )
        ) {
            $errors[] =
                "Số tài khoản phải gồm từ 6 đến 20 chữ số.";
        }

        if ($bankAccountName === "") {
            $errors[] =
                "Vui lòng nhập tên chủ tài khoản.";
        }

        if (empty($errors)) {
            $checkCitizenStatement = $pdo->prepare(
                "SELECT id
                 FROM owner_profiles
                 WHERE citizen_id = :citizen_id
                   AND user_id != :user_id
                 LIMIT 1"
            );

            $checkCitizenStatement->execute([
                "citizen_id" => $citizenId,
                "user_id" => $request["owner_id"]
            ]);

            if ($checkCitizenStatement->fetch()) {
                $errors[] =
                    "Số CCCD đã thuộc tài khoản khác.";
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $updateProfileStatement = $pdo->prepare(
                    "UPDATE owner_profiles
                     SET citizen_id = :citizen_id,
                         address = :address,
                         bank_name = :bank_name,
                         bank_account = :bank_account,
                         bank_account_name = :bank_account_name
                     WHERE user_id = :user_id"
                );

                $updateProfileStatement->execute([
                    "citizen_id" => $citizenId,
                    "address" => $address,
                    "bank_name" => $bankName,
                    "bank_account" => $bankAccount,
                    "bank_account_name" => $bankAccountName,
                    "user_id" => $request["owner_id"]
                ]);

                $completeRequestStatement = $pdo->prepare(
                    "UPDATE profile_change_requests
                     SET status = 'completed',
                         admin_note = :admin_note,
                         processed_by = :processed_by,
                         processed_at = NOW()
                     WHERE id = :id
                       AND status = 'pending'"
                );

                $completeRequestStatement->execute([
                    "admin_note" => $adminNote,
                    "processed_by" => currentUserId(),
                    "id" => $requestId
                ]);

                $pdo->commit();

                header("Location: profile_requests.php");
                exit;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] =
                    "Không thể cập nhật hồ sơ.";
            }
        }
    } else {
        $errors[] = "Thao tác không hợp lệ.";
    }
}

$banks = [
    "Vietcombank",
    "BIDV",
    "VietinBank",
    "Agribank",
    "Techcombank",
    "MB Bank",
    "VPBank",
    "ACB",
    "Sacombank",
    "TPBank"
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

    <title>Xử lý yêu cầu - Đi Đâu Đây</title>

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
            <a href="index.php">Tổng quan</a>

            <a href="index.php">
                Duyệt tài khoản chủ homestay
            </a>

            <a href="profile_requests.php" class="active">
                Yêu cầu sửa hồ sơ
            </a>

            <a href="wallet.php">Ví QTV</a>
            <a href="bookings.php">Đơn đặt phòng</a>
            <a href="refunds.php">Hoàn tiền</a>
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
                <p>QTV XỬ LÝ YÊU CẦU</p>

                <h1>
                    Hồ sơ của
                    <?php
                    echo htmlspecialchars($request["full_name"]);
                    ?>
                </h1>
            </div>

            <a
                href="profile_requests.php"
                class="button button-outline"
            >
                ← Danh sách yêu cầu
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

        <section
            class="panel"
            style="margin-bottom: 20px;"
        >
            <div class="panel-header">
                <div>
                    <h2>Nội dung chủ homestay gửi</h2>

                    <p>
                        Gửi ngày
                        <?php
                        echo date(
                            "d/m/Y H:i",
                            strtotime($request["created_at"])
                        );
                        ?>
                    </p>
                </div>

                <span class="status status-pending">
                    Chờ xử lý
                </span>
            </div>

            <div class="panel-body">
                <p
                    style="
                        margin-bottom: 15px;
                        line-height: 1.7;
                    "
                >
                    <strong>Lý do:</strong><br>

                    <?php
                    echo nl2br(
                        htmlspecialchars($request["reason"])
                    );
                    ?>
                </p>

                <p style="line-height: 1.7;">
                    <strong>Thông tin muốn thay đổi:</strong><br>

                    <?php
                    echo nl2br(
                        htmlspecialchars(
                            $request["requested_information"]
                        )
                    );
                    ?>
                </p>
            </div>
        </section>

        <section class="panel form-panel">

            <div class="panel-header">
                <div>
                    <h2>Thông tin QTV được phép chỉnh sửa</h2>

                    <p>
                        Kiểm tra kỹ trước khi cập nhật hồ sơ.
                    </p>
                </div>
            </div>

            <form method="POST">

                <input
                    type="hidden"
                    name="request_id"
                    value="<?php echo $requestId; ?>"
                >

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

                    <div class="form-group">
                        <label for="citizen_id">
                            Số căn cước công dân
                        </label>

                        <input
                            type="text"
                            id="citizen_id"
                            name="citizen_id"
                            value="<?php
                            echo htmlspecialchars($citizenId);
                            ?>"
                            maxlength="12"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="bank_name">
                            Ngân hàng nhận tiền
                        </label>

                        <select
                            id="bank_name"
                            name="bank_name"
                            required
                        >
                            <?php foreach ($banks as $bank): ?>
                                <option
                                    value="<?php echo $bank; ?>"
                                    <?php
                                    echo $bankName === $bank
                                        ? "selected"
                                        : "";
                                    ?>
                                >
                                    <?php echo $bank; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label for="address">
                            Địa chỉ thường trú
                        </label>

                        <textarea
                            id="address"
                            name="address"
                            required
                        ><?php
                        echo htmlspecialchars($address);
                        ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="bank_account">
                            Số tài khoản
                        </label>

                        <input
                            type="text"
                            id="bank_account"
                            name="bank_account"
                            value="<?php
                            echo htmlspecialchars($bankAccount);
                            ?>"
                            maxlength="20"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="bank_account_name">
                            Tên chủ tài khoản
                        </label>

                        <input
                            type="text"
                            id="bank_account_name"
                            name="bank_account_name"
                            value="<?php
                            echo htmlspecialchars(
                                $bankAccountName
                            );
                            ?>"
                            required
                        >
                    </div>

                    <div class="form-group full-width">
                        <label for="admin_note">
                            Ghi chú của QTV
                        </label>

                        <textarea
                            id="admin_note"
                            name="admin_note"
                            placeholder="Ghi nội dung đã thay đổi hoặc lý do từ chối..."
                        ><?php
                        echo htmlspecialchars($adminNote);
                        ?></textarea>
                    </div>

                </div>

                <div
                    class="form-footer"
                    style="
                        justify-content: space-between;
                    "
                >
                    <button
                        type="submit"
                        name="action"
                        value="reject"
                        class="button button-danger"
                        onclick="return confirm(
                            'Bạn chắc chắn muốn từ chối yêu cầu này?'
                        )"
                    >
                        Từ chối yêu cầu
                    </button>

                    <button
                        type="submit"
                        name="action"
                        value="complete"
                        class="button button-primary"
                        onclick="return confirm(
                            'Xác nhận cập nhật hồ sơ cho chủ homestay?'
                        )"
                    >
                        Lưu thay đổi và hoàn tất
                    </button>
                </div>

            </form>

        </section>

    </main>
</div>

</body>
</html>