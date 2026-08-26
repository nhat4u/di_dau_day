<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/session.php";

$errors = [];
$account = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $account = trim($_POST["account"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($account === "") {
        $errors[] = "Vui lòng nhập email hoặc số điện thoại.";
    }

    if ($password === "") {
        $errors[] = "Vui lòng nhập mật khẩu.";
    }

    if (empty($errors)) {
        $statement = $pdo->prepare(
            "SELECT *
             FROM users
             WHERE email = :account
                OR phone = :account
             LIMIT 1"
        );

        $statement->execute([
            "account" => $account
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user["password"])) {
            $errors[] = "Email, số điện thoại hoặc mật khẩu không chính xác.";
        } elseif ($user["status"] === "pending") {
            $errors[] = "Tài khoản của bạn đang chờ QTV phê duyệt.";
        } elseif ($user["status"] === "rejected") {
            $errors[] = "Tài khoản chủ homestay đã bị từ chối.";
        } elseif ($user["status"] === "blocked") {
            $errors[] = "Tài khoản đã bị khóa.";
        } else {
            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["full_name"] = $user["full_name"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["role"] = $user["role"];

            if ($user["role"] === "admin") {
                header("Location: ../admin/index.php");
            } elseif ($user["role"] === "owner") {
                header("Location: ../owner/index.php");
            } else {
                header("Location: ../index.php");
            }

            exit;
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

    <title>Đăng nhập - Đi Đâu Đây</title>

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
            <span>CHÀO MỪNG TRỞ LẠI</span>

            <h1>
                Chuyến đi của bạn đang chờ.
            </h1>

            <p>
                Đăng nhập để tiếp tục khám phá, quản lý
                homestay và theo dõi những chuyến đi của bạn.
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
            <p>ĐĂNG NHẬP</p>

            <h2>Xin chào bạn</h2>

            <span>
                Nhập thông tin tài khoản để tiếp tục.
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

        <form method="POST">
            <div class="form-group">
                <label for="account">
                    Email hoặc số điện thoại
                </label>

                <input
                    type="text"
                    id="account"
                    name="account"
                    value="<?php echo htmlspecialchars($account); ?>"
                    placeholder="Email hoặc số điện thoại"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">
                    Mật khẩu
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Nhập mật khẩu"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="submit-button">
                Đăng nhập
            </button>
        </form>

        <div class="auth-link">
            Chưa có tài khoản?
            <a href="register.php">Đăng ký ngay</a>
        </div>
    </section>

</div>
</body>
</html>