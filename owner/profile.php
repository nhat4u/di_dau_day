<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("owner");

$ownerId = currentUserId();

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$errors = [];
$success = isset($_GET["saved"])
    ? "Đã lưu hồ sơ thành công. Thông tin hiện đã được khóa."
    : "";

$profileStatement = $pdo->prepare(
    "SELECT *
     FROM owner_profiles
     WHERE user_id = :user_id
     LIMIT 1"
);

$profileStatement->execute([
    "user_id" => $ownerId
]);

$profile = $profileStatement->fetch(PDO::FETCH_ASSOC);

$citizenId = "";
$address = "";
$bankName = "";
$bankAccount = "";
$bankAccountName = "";

if ($profile) {
    $citizenId = $profile["citizen_id"];
    $address = $profile["address"];
    $bankName = $profile["bank_name"];
    $bankAccount = $profile["bank_account"];
    $bankAccountName = $profile["bank_account_name"];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($profile) {
        $errors[] =
            "Hồ sơ đã được khóa. Bạn không thể tự chỉnh sửa.";
    } else {
        $csrfToken = $_POST["csrf_token"] ?? "";

        $citizenId = trim($_POST["citizen_id"] ?? "");
        $address = trim($_POST["address"] ?? "");
        $bankName = trim($_POST["bank_name"] ?? "");
        $bankAccount = trim($_POST["bank_account"] ?? "");
        $bankAccountName = trim(
            $_POST["bank_account_name"] ?? ""
        );

        if (
            !hash_equals(
                $_SESSION["csrf_token"],
                $csrfToken
            )
        ) {
            $errors[] = "Yêu cầu không hợp lệ.";
        }

        if (!preg_match("/^[0-9]{12}$/", $citizenId)) {
            $errors[] =
                "Số CCCD phải gồm đúng 12 chữ số.";
        }

        if (strlen($address) < 10) {
            $errors[] =
                "Vui lòng nhập địa chỉ thường trú đầy đủ.";
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
            $checkStatement = $pdo->prepare(
                "SELECT id
                 FROM owner_profiles
                 WHERE citizen_id = :citizen_id
                 LIMIT 1"
            );

            $checkStatement->execute([
                "citizen_id" => $citizenId
            ]);

            if ($checkStatement->fetch()) {
                $errors[] =
                    "Số CCCD đã được sử dụng.";
            }
        }

        if (empty($errors)) {
            try {
                $insertStatement = $pdo->prepare(
                    "INSERT INTO owner_profiles (
                        user_id,
                        citizen_id,
                        address,
                        bank_name,
                        bank_account,
                        bank_account_name
                     ) VALUES (
                        :user_id,
                        :citizen_id,
                        :address,
                        :bank_name,
                        :bank_account,
                        :bank_account_name
                     )"
                );

                $insertStatement->execute([
                    "user_id" => $ownerId,
                    "citizen_id" => $citizenId,
                    "address" => $address,
                    "bank_name" => $bankName,
                    "bank_account" => $bankAccount,
                    "bank_account_name" => $bankAccountName
                ]);

                header("Location: profile.php?saved=1");
                exit;
            } catch (PDOException $exception) {
                $errors[] =
                    "Không thể lưu hồ sơ. Vui lòng thử lại.";
            }
        }
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

    <title>Hồ sơ chủ homestay - Đi Đâu Đây</title>

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
                <p>THÔNG TIN XÁC MINH VÀ NHẬN TIỀN</p>
                <h1>Hồ sơ chủ homestay</h1>
            </div>

            <a
                href="index.php"
                class="button button-outline"
            >
                ← Tổng quan
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
                    <h2>Thông tin cá nhân</h2>

                    <p>
                        Hồ sơ chỉ được nhập một lần.
                        Hãy kiểm tra kỹ trước khi lưu.
                    </p>
                </div>

                <?php if ($profile): ?>
                    <span class="status status-approved">
                        Đã khóa
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($profile): ?>

                <div class="form-grid">

                    <div class="form-group">
                        <label>Số căn cước công dân</label>

                        <input
                            type="text"
                            value="<?php
                            echo htmlspecialchars($citizenId);
                            ?>"
                            readonly
                        >
                    </div>

                    <div class="form-group">
                        <label>Ngân hàng nhận tiền</label>

                        <input
                            type="text"
                            value="<?php
                            echo htmlspecialchars($bankName);
                            ?>"
                            readonly
                        >
                    </div>

                    <div class="form-group full-width">
                        <label>Địa chỉ thường trú</label>

                        <textarea readonly><?php
                        echo htmlspecialchars($address);
                        ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Số tài khoản ngân hàng</label>

                        <input
                            type="text"
                            value="<?php
                            echo htmlspecialchars($bankAccount);
                            ?>"
                            readonly
                        >
                    </div>

                    <div class="form-group">
                        <label>Tên chủ tài khoản</label>

                        <input
                            type="text"
                            value="<?php
                            echo htmlspecialchars(
                                $bankAccountName
                            );
                            ?>"
                            readonly
                        >
                    </div>

                </div>

                <div class="form-footer">
                    <a
                        href="request_profile_change.php"
                        class="button button-outline"
                    >
                        Yêu cầu QTV thay đổi thông tin
                    </a>
                </div>

            <?php else: ?>

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
                                placeholder="Nhập đúng 12 chữ số"
                                maxlength="12"
                                pattern="[0-9]{12}"
                                required
                            >

                            <small class="form-help">
                                Không thể tự sửa sau khi lưu.
                            </small>
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
                                <option value="">
                                    -- Chọn ngân hàng --
                                </option>

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
                                placeholder="Số nhà, phường/xã, quận/huyện, tỉnh/thành phố"
                                required
                            ><?php
                            echo htmlspecialchars($address);
                            ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="bank_account">
                                Số tài khoản ngân hàng
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
                                placeholder="NGUYEN HOANG NAM"
                                required
                            >
                        </div>

                    </div>

                    <div class="form-footer">
                        <button
                            type="submit"
                            class="button button-primary"
                            onclick="return confirm(
                                'Bạn đã kiểm tra chính xác toàn bộ thông tin chưa? Sau khi lưu, bạn sẽ không thể tự sửa.'
                            )"
                        >
                            Xác nhận và khóa hồ sơ
                        </button>
                    </div>

                </form>

            <?php endif; ?>

        </section>

    </main>
</div>

</body>
</html>