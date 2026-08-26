<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/session.php";

$errors = [];
$success = "";

$fullName = "";
$email = "";
$phone = "";
$role = "guest";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    $role = $_POST["role"] ?? "guest";

    if ($fullName === "") {
        $errors[] = "Vui lòng nhập họ và tên.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Địa chỉ email không hợp lệ.";
    }

    if (!preg_match("/^0[0-9]{9}$/", $phone)) {
        $errors[] = "Số điện thoại phải gồm 10 số và bắt đầu bằng số 0.";
    }

    if (strlen($password) < 6) {
        $errors[] = "Mật khẩu phải có ít nhất 6 ký tự.";
    }

    if ($password !== $confirmPassword) {
        $errors[] = "Mật khẩu nhập lại không trùng khớp.";
    }

    if (!in_array($role, ["guest", "owner"], true)) {
        $errors[] = "Vai trò tài khoản không hợp lệ.";
    }

    if (empty($errors)) {
        $checkStatement = $pdo->prepare(
            "SELECT id FROM users
             WHERE email = :email OR phone = :phone
             LIMIT 1"
        );

        $checkStatement->execute([
            "email" => $email,
            "phone" => $phone
        ]);

        if ($checkStatement->fetch()) {
            $errors[] = "Email hoặc số điện thoại đã được sử dụng.";
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $status = $role === "owner" ? "pending" : "approved";
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $insertStatement = $pdo->prepare(
                "INSERT INTO users
                    (full_name, email, phone, password, role, status)
                 VALUES
                    (:full_name, :email, :phone, :password, :role, :status)"
            );

            $insertStatement->execute([
                "full_name" => $fullName,
                "email" => $email,
                "phone" => $phone,
                "password" => $passwordHash,
                "role" => $role,
                "status" => $status
            ]);

            $userId = $pdo->lastInsertId();

            if ($role === "owner") {
                $walletStatement = $pdo->prepare(
                    "INSERT INTO wallets (user_id) VALUES (:user_id)"
                );

                $walletStatement->execute([
                    "user_id" => $userId
                ]);
            }

            $pdo->commit();

            if ($role === "owner") {
                $success =
                    "Đăng ký thành công! Tài khoản chủ homestay đang chờ QTV duyệt.";
            } else {
                $success =
                    "Đăng ký thành công! Bạn có thể đăng nhập ngay.";
            }

            $fullName = "";
            $email = "";
            $phone = "";
            $role = "guest";
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = "Không thể tạo tài khoản. Vui lòng thử lại.";
        }
    }
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

    <title>Đăng ký - Đi Đâu Đây</title>

    <link
        rel="stylesheet"
        href="../assets/css/auth.css"
    >
</head>

<body>
<div class="auth-page">

    <section class="auth-introduction">
        <a href="../index.php" class="logo">
            Đi Đâu Đây
        </a>

        <div class="introduction-content">
            <span>KHÁM PHÁ VIỆT NAM</span>

            <h1>
                Một chốn nhỏ cho chuyến đi lớn.
            </h1>

            <p>
                Tìm và đặt homestay tại những điểm du lịch
                trên khắp Việt Nam.
            </p>
        </div>

        <div class="introduction-footer">
            © 2026 Đi Đâu Đây
        </div>
    </section>

    <section class="auth-content">
        <a href="../index.php" class="back-home">
            ← Về trang chủ
        </a>

        <div class="auth-header">
            <p>TẠO TÀI KHOẢN</p>

            <h2>Chào mừng bạn</h2>

            <span>
                Đăng ký làm khách thuê hoặc chủ homestay.
            </span>
        </div>

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

        <form method="POST">

            <div class="form-row">
                <div class="form-group">
                    <label for="full_name">
                        Họ và tên
                    </label>

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        value="<?php echo htmlspecialchars($fullName); ?>"
                        placeholder="Nguyễn Văn A"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="phone">
                        Số điện thoại
                    </label>

                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        value="<?php echo htmlspecialchars($phone); ?>"
                        placeholder="0912345678"
                        maxlength="10"
                        pattern="0[0-9]{9}"
                        required
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="email">
                    Email
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?php echo htmlspecialchars($email); ?>"
                    placeholder="email@example.com"
                    required
                >
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password">
                        Mật khẩu
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Ít nhất 6 ký tự"
                        minlength="6"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="confirm_password">
                        Nhập lại mật khẩu
                    </label>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="Nhập lại mật khẩu"
                        minlength="6"
                        required
                    >
                </div>
            </div>

            <div class="form-group">
                <label>Bạn đăng ký với vai trò</label>
            </div>

            <div class="role-options">
                <label class="role-card">
                    <input
                        type="radio"
                        name="role"
                        value="guest"
                        <?php echo $role === "guest" ? "checked" : ""; ?>
                    >

                    <span>🧳 Khách thuê</span>
                </label>

                <label class="role-card">
                    <input
                        type="radio"
                        name="role"
                        value="owner"
                        <?php echo $role === "owner" ? "checked" : ""; ?>
                    >

                    <span>🏡 Chủ homestay</span>
                </label>
            </div>

            <button type="submit" class="submit-button">
                Đăng ký
            </button>
        </form>

        <div class="auth-link">
            Đã có tài khoản?
            <a href="login.php">Đăng nhập</a>
        </div>
    </section>

</div>
</body>
</html>