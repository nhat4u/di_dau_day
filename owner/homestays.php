<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("owner");

$ownerId = currentUserId();

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$deleteError = "";

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "delete_homestay"
) {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $deleteError = "Yêu cầu không hợp lệ.";

    } else {
        $homestayId = (int) ($_POST["homestay_id"] ?? 0);

        $bookingCheckStatement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM bookings
             WHERE homestay_id = :homestay_id
             AND status IN (
                 'pending_payment',
                 'funds_held',
                 'confirmed',
                 'disputed'
             )"
        );

        $bookingCheckStatement->execute([
            "homestay_id" => $homestayId
        ]);

        $activeBookingCount =
            (int) $bookingCheckStatement->fetchColumn();

        if ($activeBookingCount > 0) {
            $deleteError =
                "Không thể xóa homestay đang có đơn đặt phòng hoặc khiếu nại chưa xử lý.";

        } else {
            $deleteStatement = $pdo->prepare(
                "UPDATE homestays
                 SET is_deleted = 1
                 WHERE id = :homestay_id
                 AND owner_id = :owner_id"
            );

            $deleteStatement->execute([
                "homestay_id" => $homestayId,
                "owner_id" => $ownerId
            ]);

            header("Location: homestays.php?deleted=1");
            exit;
        }
    }
}

$errors = [];

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/* Thay đổi trạng thái homestay */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    $homestayId = (int) (
        $_POST["homestay_id"] ?? 0
    );

    $action = $_POST["action"] ?? "";

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Yêu cầu không hợp lệ.";
    }

    if (
        !in_array(
            $action,
            ["maintenance", "activate"],
            true
        )
    ) {
        $errors[] = "Thao tác không hợp lệ.";
    }

    if ($homestayId <= 0) {
        $errors[] = "Không tìm thấy homestay.";
    }

    if (empty($errors)) {
        $checkHomestayStatement = $pdo->prepare(
            "SELECT id, status

             FROM homestays

             WHERE id = :id
                AND owner_id = :owner_id

             LIMIT 1"
        );

        $checkHomestayStatement->execute([
            "id" => $homestayId,
            "owner_id" => $ownerId
        ]);

        $existingHomestay =
            $checkHomestayStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$existingHomestay) {
            $errors[] =
                "Bạn không có quyền quản lý homestay này.";
        } elseif (
            !in_array(
                $existingHomestay["status"],
                ["approved", "maintenance"],
                true
            )
        ) {
            $errors[] =
                "Không thể thay đổi trạng thái homestay này.";
        } else {
            $newStatus = $action === "maintenance"
                ? "maintenance"
                : "approved";

            $updateStatusStatement = $pdo->prepare(
                "UPDATE homestays

                 SET status = :status

                 WHERE id = :id
                    AND owner_id = :owner_id"
            );

            $updateStatusStatement->execute([
                "status" => $newStatus,
                "id" => $homestayId,
                "owner_id" => $ownerId
            ]);

            header(
                "Location: homestays.php?updated=1"
            );

            exit;
        }
    }
}

/* Lấy danh sách homestay của chủ nhà */

$homestayStatement = $pdo->prepare(
    "SELECT
        h.*,

        (
            SELECT hi.image_path

            FROM homestay_images AS hi

            WHERE hi.homestay_id = h.id

            ORDER BY
                hi.is_cover DESC,
                hi.sort_order ASC,
                hi.id ASC

            LIMIT 1
        ) AS cover_image,

        COALESCE(
            LEAST(
                hp.price_first_2_hours,
                hp.price_combo_4_hours,
                hp.price_overnight_weekday,
                hp.price_overnight_weekend,
                hp.price_day_night_weekday,
                hp.price_day_night_weekend,
                hp.price_day_weekday,
                hp.price_day_weekend
            ),
            h.price_per_hour
        ) AS min_price,

        COALESCE(
            GREATEST(
                hp.price_first_2_hours,
                hp.price_combo_4_hours,
                hp.price_overnight_weekday,
                hp.price_overnight_weekend,
                hp.price_day_night_weekday,
                hp.price_day_night_weekend,
                hp.price_day_weekday,
                hp.price_day_weekend
            ),
            h.overnight_price
        ) AS max_price,

        (
            SELECT COUNT(*)

            FROM bookings AS b

            WHERE b.homestay_id = h.id
        ) AS booking_count

    FROM homestays AS h

    LEFT JOIN homestay_prices AS hp
        ON hp.homestay_id = h.id

    WHERE h.owner_id = :owner_id
    AND is_deleted = 0

    ORDER BY h.created_at DESC"
);

$homestayStatement->execute([
    "owner_id" => $ownerId
]);

$homestays = $homestayStatement->fetchAll(
    PDO::FETCH_ASSOC
);

/* Thống kê */

$totalHomestays = count($homestays);

$activeHomestays = 0;
$maintenanceHomestays = 0;
$totalBookings = 0;

foreach ($homestays as $item) {
    if ($item["status"] === "approved") {
        $activeHomestays++;
    }

    if ($item["status"] === "maintenance") {
        $maintenanceHomestays++;
    }

    $totalBookings += (int) $item["booking_count"];
}

/* Tên hạng phòng */

$roomRankNames = [
    "standard" => "Standard",
    "deluxe" => "Deluxe",
    "premium" => "Premium"
];

/* Tên trạng thái */

$statusNames = [
    "approved" => "Đang hoạt động",
    "maintenance" => "Đang bảo trì",
    "pending" => "Chờ xử lý",
    "rejected" => "Tạm khóa",
    "draft" => "Bản nháp"
];

/* Hiển thị thông báo sau khi đổi trạng thái */

$updatedSuccessfully = isset($_GET["updated"]);

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
        Homestay của tôi - Đi Đâu Đây
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >

    <style>
        .homestay-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 26px;
        }

        .homestay-stat-card {
            min-height: 125px;
            padding: 20px;
            border: 1px solid #dfe7e3;
            border-radius: 16px;
            background: #ffffff;
        }

        .homestay-stat-card small {
            display: block;
            color: #738079;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.07em;
            text-transform: uppercase;
        }

        .homestay-stat-card strong {
            display: block;
            margin: 14px 0 5px;
            color: #174e3d;
            font-size: 29px;
        }

        .homestay-stat-card span {
            color: #89948f;
            font-size: 12px;
        }

        .homestay-panel {
            overflow: hidden;
            border: 1px solid #dfe7e3;
            border-radius: 17px;
            background: #ffffff;
        }

        .homestay-panel-heading {
            padding: 20px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            border-bottom: 1px solid #e7ece9;
        }

        .homestay-panel-heading h2 {
            margin: 0;
            color: #26342e;
            font-size: 20px;
        }

        .homestay-panel-heading p {
            margin: 5px 0 0;
            color: #7c8782;
            font-size: 13px;
        }

        .homestay-table-wrapper {
            overflow-x: auto;
        }

        .homestay-table {
            width: 100%;
            min-width: 1100px;
            border-collapse: collapse;
        }

        .homestay-table th {
            padding: 14px 17px;
            color: #78837e;
            background: #f8faf9;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-align: left;
            text-transform: uppercase;
        }

        .homestay-table td {
            padding: 17px;
            border-top: 1px solid #edf0ef;
            color: #46554d;
            font-size: 13px;
            vertical-align: middle;
        }

        .homestay-overview {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .homestay-cover-image {
            width: 92px;
            height: 72px;
            flex-shrink: 0;
            overflow: hidden;
            border-radius: 11px;
            background: #eaf0ed;
        }

        .homestay-cover-image img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }

        .homestay-cover-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #7b8981;
            font-size: 11px;
        }

        .homestay-name {
            display: block;
            margin-bottom: 5px;
            color: #20382e;
            font-size: 14px;
            font-weight: 800;
        }

        .homestay-address {
            display: block;
            color: #84908a;
            font-size: 12px;
        }

        .room-rank-label {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            color: #356150;
            background: #edf4f0;
            font-size: 11px;
            font-weight: 800;
        }

        .homestay-price-range {
            color: #155641;
            font-weight: 800;
            white-space: nowrap;
        }

        .homestay-status {
            display: inline-block;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .homestay-status.approved {
            color: #206b49;
            background: #e8f5ed;
        }

        .homestay-status.maintenance {
            color: #9b6b23;
            background: #fff2dc;
        }

        .homestay-status.pending,
        .homestay-status.draft {
            color: #826426;
            background: #fff4de;
        }

        .homestay-status.rejected {
            color: #a54242;
            background: #fdeeee;
        }

        .homestay-actions {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .homestay-actions form {
            margin: 0;
        }

        .action-button {
            padding: 9px 11px;
            border: none;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 800;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
        }

        .action-view {
            color: #245645;
            background: #edf4f0;
        }

        .action-edit {
            color: #ffffff;
            background: #205b47;
        }

        .action-maintenance {
            color: #94621f;
            background: #fff1db;
        }

        .action-activate {
            color: #226744;
            background: #e7f5ec;
        }

        .empty-homestay-list {
            padding: 70px 25px;
            color: #77847c;
            text-align: center;
        }

        .empty-homestay-list h3 {
            margin: 0 0 10px;
            color: #31463b;
        }

        .empty-homestay-list p {
            margin: 0 0 20px;
            font-size: 14px;
        }

        .message-success {
            margin-bottom: 18px;
            padding: 14px 17px;
            border: 1px solid #cfe9d7;
            border-radius: 10px;
            color: #276646;
            background: #ecf8ef;
        }

        .message-error {
            margin-bottom: 18px;
            padding: 14px 17px;
            border: 1px solid #f0cccc;
            border-radius: 10px;
            color: #984545;
            background: #fff1f1;
        }

        @media (max-width: 1100px) {
            .homestay-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 650px) {
            .homestay-stats {
                grid-template-columns: 1fr;
            }

            .homestay-panel-heading {
                align-items: flex-start;
                flex-direction: column;
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

            <a
                href="homestays.php"
                class="active"
            >
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

                <div class="sidebar-avatar">
                    CN
                </div>

                <div>

                    <strong>
                        <?php
                        echo htmlspecialchars(
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
                    QUẢN LÝ CHỖ Ở
                </p>

                <h1>
                    Homestay của tôi
                </h1>

            </div>

            <a
                href="add_homestay.php"
                class="button button-primary"
            >
                + Thêm homestay
            </a>

        </header>

        <?php if ($updatedSuccessfully): ?>

            <div class="message-success">
                Cập nhật trạng thái homestay thành công.
            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="message-error">

                <?php foreach ($errors as $error): ?>

                    <div>
                        <?php
                        echo htmlspecialchars($error);
                        ?>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <section class="homestay-stats">

            <article class="homestay-stat-card">

                <small>
                    Tổng homestay
                </small>

                <strong>
                    <?php
                    echo $totalHomestays;
                    ?>
                </strong>

                <span>
                    Homestay đã tạo
                </span>

            </article>

            <article class="homestay-stat-card">

                <small>
                    Đang hoạt động
                </small>

                <strong>
                    <?php
                    echo $activeHomestays;
                    ?>
                </strong>

                <span>
                    Đang hiển thị cho khách
                </span>

            </article>

            <article class="homestay-stat-card">

                <small>
                    Đang bảo trì
                </small>

                <strong>
                    <?php
                    echo $maintenanceHomestays;
                    ?>
                </strong>

                <span>
                    Tạm thời không nhận khách
                </span>

            </article>

            <article class="homestay-stat-card">

                <small>
                    Đơn đặt phòng
                </small>

                <strong>
                    <?php
                    echo $totalBookings;
                    ?>
                </strong>

                <span>
                    Tổng số đơn đã nhận
                </span>

            </article>

        </section>

                    <?php if (isset($_GET["deleted"])): ?>

                <div class="alert alert-success">
                    Homestay đã được xóa thành công.
                </div>

            <?php endif; ?>

            <?php if (!empty($deleteError)): ?>

                <div class="alert alert-error">
                    <?php echo htmlspecialchars($deleteError); ?>
                </div>

            <?php endif; ?>

        <section class="homestay-panel">

            <div class="homestay-panel-heading">

                <div>

                    <h2>
                        Danh sách homestay
                    </h2>

                    <p>
                        Quản lý hình ảnh, bảng giá và trạng thái.
                    </p>

                </div>

                <a
                    href="../index.php#homestays"
                    class="button button-outline"
                >
                    Xem trang khách
                </a>

            </div>

            <?php if (empty($homestays)): ?>

                <div class="empty-homestay-list">

                    <h3>
                        Bạn chưa có homestay nào
                    </h3>

                    <p>
                        Thêm homestay đầu tiên để bắt đầu nhận khách.
                    </p>

                    <a
                        href="add_homestay.php"
                        class="button button-primary"
                    >
                        + Thêm homestay
                    </a>

                </div>

            <?php else: ?>

                <div class="homestay-table-wrapper">

                    <table class="homestay-table">

                        <thead>

                            <tr>

                                <th>
                                    Homestay
                                </th>

                                <th>
                                    Hạng phòng
                                </th>

                                <th>
                                    Sức chứa
                                </th>

                                <th>
                                    Khoảng giá
                                </th>

                                <th>
                                    Trạng thái
                                </th>

                                <th>
                                    Thao tác
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $homestays as $homestay
                            ): ?>

                                <?php

                                $roomRank =
                                    $roomRankNames[
                                        $homestay["room_rank"]
                                    ] ?? "Standard";

                                $status =
                                    $homestay["status"];

                                $statusName =
                                    $statusNames[
                                        $status
                                    ] ?? "Không xác định";

                                $coverImage = "";

                                if (
                                    !empty(
                                        $homestay[
                                            "cover_image"
                                        ]
                                    )
                                ) {
                                    $coverImage =
                                        "../" .
                                        ltrim(
                                            $homestay[
                                                "cover_image"
                                            ],
                                            "/"
                                        );
                                }

                                $detailUrl =
                                    "../homestay.php?slug=" .
                                    urlencode(
                                        $homestay["slug"]
                                    );

                                $editUrl =
                                    "edit_homestay.php?id=" .
                                    (int) $homestay["id"];

                                ?>

                                <tr>

                                    <td>

                                        <div
                                            class="homestay-overview"
                                        >

                                            <div
                                                class="homestay-cover-image"
                                            >

                                                <?php if (
                                                    $coverImage !== ""
                                                ): ?>

                                                    <img
                                                        src="<?php
                                                        echo htmlspecialchars(
                                                            $coverImage
                                                        );
                                                        ?>"
                                                        alt="<?php
                                                        echo htmlspecialchars(
                                                            $homestay[
                                                                "name"
                                                            ]
                                                        );
                                                        ?>"
                                                    >

                                                <?php else: ?>

                                                    <div
                                                        class="homestay-cover-placeholder"
                                                    >
                                                        Chưa có ảnh
                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                            <div>

                                                <span
                                                    class="homestay-name"
                                                >
                                                    <?php
                                                    echo htmlspecialchars(
                                                        $homestay[
                                                            "name"
                                                        ]
                                                    );
                                                    ?>
                                                </span>

                                                <span
                                                    class="homestay-address"
                                                >
                                                    <?php
                                                    echo htmlspecialchars(
                                                        $homestay[
                                                            "tourist_destination"
                                                        ]
                                                    );
                                                    ?>

                                                    ·

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $homestay[
                                                            "province"
                                                        ]
                                                    );
                                                    ?>
                                                </span>

                                            </div>

                                        </div>

                                    </td>

                                    <td>

                                        <span
                                            class="room-rank-label"
                                        >
                                            <?php
                                            echo htmlspecialchars(
                                                $roomRank
                                            );
                                            ?>
                                        </span>

                                    </td>

                                    <td>
                                        Tối đa

                                        <?php
                                        echo (int) (
                                            $homestay[
                                                "max_guests"
                                            ]
                                        );
                                        ?>

                                        khách
                                    </td>

                                    <td>

                                        <span
                                            class="homestay-price-range"
                                        >

                                            <?php
                                            echo number_format(
                                                (float) $homestay[
                                                    "min_price"
                                                ],
                                                0,
                                                ",",
                                                "."
                                            );
                                            ?>đ

                                            –

                                            <?php
                                            echo number_format(
                                                (float) $homestay[
                                                    "max_price"
                                                ],
                                                0,
                                                ",",
                                                "."
                                            );
                                            ?>đ

                                        </span>

                                    </td>

                                    <td>

                                        <span
                                            class="homestay-status <?php
                                            echo htmlspecialchars(
                                                $status
                                            );
                                            ?>"
                                        >
                                            <?php
                                            echo htmlspecialchars(
                                                $statusName
                                            );
                                            ?>
                                        </span>

                                    </td>

                                    <td>

                                        <div
                                            class="homestay-actions"
                                        >

                                            <?php if (
                                                $status === "approved"
                                            ): ?>

                                                <a
                                                    href="<?php
                                                    echo htmlspecialchars(
                                                        $detailUrl
                                                    );
                                                    ?>"
                                                    class="action-button action-view"
                                                >
                                                    Xem
                                                </a>

                                            <?php endif; ?>

                                            <a
                                                href="<?php
                                                echo htmlspecialchars(
                                                    $editUrl
                                                );
                                                ?>"
                                                class="action-button action-edit"
                                            >
                                                Chỉnh sửa
                                            </a>

                                                                                <form
                                        method="POST"
                                        style="display: inline;"
                                        onsubmit="return confirm(
                                            'Bạn có chắc chắn muốn xóa homestay này không?'
                                        );"
                                    >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete_homestay"
                                        >

                                        <input
                                            type="hidden"
                                            name="homestay_id"
                                            value="<?php echo (int) $homestay['id']; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php
                                            echo htmlspecialchars(
                                                $_SESSION['csrf_token']
                                            );
                                            ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="action-button action-delete"
                                        >
                                            Xóa
                                        </button>
                                    </form>

                                            <?php if (
                                                $status === "approved"
                                            ): ?>

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
                                                        name="homestay_id"
                                                        value="<?php
                                                        echo (int) (
                                                            $homestay[
                                                                "id"
                                                            ]
                                                        );
                                                        ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="maintenance"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="action-button action-maintenance"
                                                        onclick="return confirm('Chuyển homestay sang trạng thái bảo trì?');"
                                                    >
                                                        Bảo trì
                                                    </button>

                                                </form>

                                            <?php elseif (
                                                $status === "maintenance"
                                            ): ?>

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
                                                        name="homestay_id"
                                                        value="<?php
                                                        echo (int) (
                                                            $homestay[
                                                                "id"
                                                            ]
                                                        );
                                                        ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="activate"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="action-button action-activate"
                                                    >
                                                        Mở lại
                                                    </button>

                                                </form>

                                            <?php endif; ?>

                                        </div>

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