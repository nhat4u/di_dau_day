<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("owner");

$ownerId = currentUserId();

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/* Chỉ được thêm homestay sau khi hoàn thiện hồ sơ */

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

/* Tạo đường dẫn slug cho homestay */

function createSlug($text)
{
    $text = str_replace(
        ["Đ", "đ"],
        ["D", "d"],
        $text
    );

    $converted = iconv(
        "UTF-8",
        "ASCII//TRANSLIT//IGNORE",
        $text
    );

    if ($converted !== false) {
        $text = $converted;
    }

    $text = strtolower($text);
    $text = preg_replace("/[^a-z0-9]+/", "-", $text);

    return trim($text, "-");
}

/* Giá trị mặc định của biểu mẫu */

$errors = [];

$name = "";
$roomRank = "standard";
$description = "";
$address = "";
$province = "";
$touristDestination = "";
$maxGuests = 2;

$priceFirst2Hours = "";
$priceCombo4Hours = "";
$priceExtraHour = "";

$priceOvernightWeekday = "";
$priceOvernightWeekend = "";

$priceDayNightWeekday = "";
$priceDayNightWeekend = "";

$priceDayWeekday = "";
$priceDayWeekend = "";

$pricePerHour = "";
$overnightPrice = "";

$autoCheckin = true;
$hasBathtub = false;
$hasBalcony = false;
$hasMiniPool = false;

/* Xử lý khi chủ nhà gửi biểu mẫu */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    $name = trim($_POST["name"] ?? "");
    $roomRank = $_POST["room_rank"] ?? "standard";
    $description = trim($_POST["description"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $province = trim($_POST["province"] ?? "");

    $touristDestination = trim(
        $_POST["tourist_destination"] ?? ""
    );

    $maxGuests = (int) ($_POST["max_guests"] ?? 2);

    /* Nhận bảng giá */

    $priceFirst2Hours = (float) (
        $_POST["price_first_2_hours"] ?? 0
    );

    $priceCombo4Hours = (float) (
        $_POST["price_combo_4_hours"] ?? 0
    );

    $priceExtraHour = (float) (
        $_POST["price_extra_hour"] ?? 0
    );

    $priceOvernightWeekday = (float) (
        $_POST["price_overnight_weekday"] ?? 0
    );

    $priceOvernightWeekend = (float) (
        $_POST["price_overnight_weekend"] ?? 0
    );

    $priceDayNightWeekday = (float) (
        $_POST["price_day_night_weekday"] ?? 0
    );

    $priceDayNightWeekend = (float) (
        $_POST["price_day_night_weekend"] ?? 0
    );

    $priceDayWeekday = (float) (
        $_POST["price_day_weekday"] ?? 0
    );

    $priceDayWeekend = (float) (
        $_POST["price_day_weekend"] ?? 0
    );

    /*
     * Hai giá tạm dùng cho các trang cũ đang đọc
     * dữ liệu trực tiếp từ bảng homestays.
     */

    $pricePerHour = $priceFirst2Hours;

    $overnightPrice = max(
        $priceFirst2Hours,
        $priceCombo4Hours,
        $priceOvernightWeekday,
        $priceOvernightWeekend,
        $priceDayNightWeekday,
        $priceDayNightWeekend,
        $priceDayWeekday,
        $priceDayWeekend
    );

    /*
     * Check-in/out tự động luôn là tiện nghi mặc định.
     */

    $autoCheckin = true;

    $hasBathtub = isset($_POST["has_bathtub"]);
    $hasBalcony = isset($_POST["has_balcony"]);
   $hasMiniPool = isset($_POST["has_mini_pool"]);

    /* Kiểm tra CSRF */

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Yêu cầu không hợp lệ.";
    }

    /* Kiểm tra thông tin homestay */

    if (strlen($name) < 3) {
        $errors[] = "Tên homestay phải có ít nhất 3 ký tự.";
    }

    if (
        !in_array(
            $roomRank,
            ["standard", "deluxe", "premium"],
            true
        )
    ) {
        $errors[] = "Hạng phòng không hợp lệ.";
    }

    if (strlen($description) < 20) {
        $errors[] =
            "Phần giới thiệu phải có ít nhất 20 ký tự.";
    }

    if (strlen($address) < 10) {
        $errors[] = "Vui lòng nhập địa chỉ đầy đủ.";
    }

    if ($province === "") {
        $errors[] = "Vui lòng nhập tỉnh hoặc thành phố.";
    }

    if ($touristDestination === "") {
        $errors[] = "Vui lòng nhập điểm du lịch gần nhất.";
    }

    if ($maxGuests < 1 || $maxGuests > 4) {
        $errors[] =
            "Sức chứa chỉ được từ 1 đến tối đa 4 khách.";
    }

    /* Kiểm tra bảng giá */

    $priceList = [
        "Giá 2 giờ đầu" => $priceFirst2Hours,
        "Giá combo 4 giờ" => $priceCombo4Hours,
        "Giá thêm mỗi giờ" => $priceExtraHour,
        "Giá đêm T2-T5" => $priceOvernightWeekday,
        "Giá đêm T6-CN" => $priceOvernightWeekend,
        "Giá ngày đêm T2-T5" => $priceDayNightWeekday,
        "Giá ngày đêm T6-CN" => $priceDayNightWeekend,
        "Giá ban ngày T2-T5" => $priceDayWeekday,
        "Giá ban ngày T6-CN" => $priceDayWeekend
    ];

    foreach ($priceList as $priceName => $priceValue) {
        if ($priceValue <= 0) {
            $errors[] =
                $priceName . " phải lớn hơn 0.";
        }
    }

    if (
        $priceCombo4Hours > 0 &&
        $priceFirst2Hours > 0 &&
        $priceCombo4Hours < $priceFirst2Hours
    ) {
        $errors[] =
            "Giá combo 4 giờ không được thấp hơn giá 2 giờ đầu.";
    }

    /* Kiểm tra ảnh tải lên */

    $uploadedImages = $_FILES["images"] ?? null;
    $imageCount = 0;

    if (
        $uploadedImages &&
        isset($uploadedImages["name"]) &&
        is_array($uploadedImages["name"])
    ) {
        foreach ($uploadedImages["name"] as $imageName) {
            if ($imageName !== "") {
                $imageCount++;
            }
        }
    }

    if ($imageCount < 1) {
        $errors[] =
            "Vui lòng chọn ít nhất một ảnh homestay.";
    }

    if ($imageCount > 8) {
        $errors[] =
            "Mỗi homestay chỉ được tải tối đa 8 ảnh.";
    }

    $allowedMimeTypes = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp"
    ];

    $validImages = [];

    if ($imageCount >= 1 && $imageCount <= 8) {
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);

        foreach (
            $uploadedImages["name"] as $index => $fileName
        ) {
            if ($fileName === "") {
                continue;
            }

            if (
                $uploadedImages["error"][$index]
                !== UPLOAD_ERR_OK
            ) {
                $errors[] =
                    "Có ảnh tải lên không thành công.";

                continue;
            }

            if (
                $uploadedImages["size"][$index]
                > 5 * 1024 * 1024
            ) {
                $errors[] =
                    "Mỗi ảnh không được lớn hơn 5MB.";

                continue;
            }

            $temporaryPath =
                $uploadedImages["tmp_name"][$index];

            $mimeType = $fileInfo->file($temporaryPath);

            if (!isset($allowedMimeTypes[$mimeType])) {
                $errors[] =
                    "Chỉ chấp nhận ảnh JPG, PNG hoặc WEBP.";

                continue;
            }

            $validImages[] = [
                "temporary_path" => $temporaryPath,
                "extension" => $allowedMimeTypes[$mimeType]
            ];
        }
    }

    /* Kiểm tra thư mục lưu ảnh */

    if (empty($errors)) {
        $uploadDirectory =
            __DIR__ . "/../uploads/homestays/";

        if (!is_dir($uploadDirectory)) {
            $errors[] =
                "Không tìm thấy thư mục uploads/homestays.";
        } elseif (!is_writable($uploadDirectory)) {
            $errors[] =
                "Thư mục ảnh không cho phép ghi dữ liệu.";
        }
    }

    /* Lưu homestay */

    if (empty($errors)) {
        $slugBase = createSlug($name);

        if ($slugBase === "") {
            $slugBase = "homestay";
        }

        $slug = $slugBase;
        $slugNumber = 1;

        $checkSlugStatement = $pdo->prepare(
            "SELECT id
             FROM homestays
             WHERE slug = :slug
             LIMIT 1"
        );

        while (true) {
            $checkSlugStatement->execute([
                "slug" => $slug
            ]);

            if (!$checkSlugStatement->fetch()) {
                break;
            }

            $slugNumber++;
            $slug = $slugBase . "-" . $slugNumber;
        }

        $movedFiles = [];

        try {
            $pdo->beginTransaction();

            /* Lưu thông tin chính */

            $insertHomestayStatement = $pdo->prepare(
                "INSERT INTO homestays (
                    owner_id,
                    name,
                    slug,
                    room_rank,
                    description,
                    address,
                    province,
                    tourist_destination,
                    max_guests,
                    price_per_hour,
                    minimum_hours,
                    overnight_price,
                    auto_checkin,
                    has_bathtub,
                    has_balcony,
                    has_mini_pool
                 ) VALUES (
                    :owner_id,
                    :name,
                    :slug,
                    :room_rank,
                    :description,
                    :address,
                    :province,
                    :tourist_destination,
                    :max_guests,
                    :price_per_hour,
                    2,
                    :overnight_price,
                    :auto_checkin,
                    :has_bathtub,
                    :has_balcony,
                    :has_mini_pool
                 )"
            );

            $insertHomestayStatement->execute([
                "owner_id" => $ownerId,
                "name" => $name,
                "slug" => $slug,
                "room_rank" => $roomRank,
                "description" => $description,
                "address" => $address,
                "province" => $province,
                "tourist_destination" =>
                    $touristDestination,
                "max_guests" => $maxGuests,
                "price_per_hour" => $pricePerHour,
                "overnight_price" => $overnightPrice,

                /* Luôn bật check-in/out tự động */
                "auto_checkin" => 1,

                "has_bathtub" => $hasBathtub ? 1 : 0,
                "has_balcony" => $hasBalcony ? 1 : 0,
                "has_mini_pool" => $hasMiniPool ? 1 : 0
            ]);

            $homestayId = (int) $pdo->lastInsertId();

            /* Lưu toàn bộ bảng giá */

            $priceStatement = $pdo->prepare(
                "INSERT INTO homestay_prices (
                    homestay_id,
                    price_first_2_hours,
                    price_combo_4_hours,
                    price_extra_hour,
                    price_overnight_weekday,
                    price_overnight_weekend,
                    price_day_night_weekday,
                    price_day_night_weekend,
                    price_day_weekday,
                    price_day_weekend
                 ) VALUES (
                    :homestay_id,
                    :price_first_2_hours,
                    :price_combo_4_hours,
                    :price_extra_hour,
                    :price_overnight_weekday,
                    :price_overnight_weekend,
                    :price_day_night_weekday,
                    :price_day_night_weekend,
                    :price_day_weekday,
                    :price_day_weekend
                 )"
            );

            $priceStatement->execute([
                "homestay_id" => $homestayId,
                "price_first_2_hours" =>
                    $priceFirst2Hours,
                "price_combo_4_hours" =>
                    $priceCombo4Hours,
                "price_extra_hour" =>
                    $priceExtraHour,
                "price_overnight_weekday" =>
                    $priceOvernightWeekday,
                "price_overnight_weekend" =>
                    $priceOvernightWeekend,
                "price_day_night_weekday" =>
                    $priceDayNightWeekday,
                "price_day_night_weekend" =>
                    $priceDayNightWeekend,
                "price_day_weekday" =>
                    $priceDayWeekday,
                "price_day_weekend" =>
                    $priceDayWeekend
            ]);

            /* Chuẩn bị lưu ảnh */

            $insertImageStatement = $pdo->prepare(
                "INSERT INTO homestay_images (
                    homestay_id,
                    image_path,
                    is_cover,
                    sort_order
                 ) VALUES (
                    :homestay_id,
                    :image_path,
                    :is_cover,
                    :sort_order
                 )"
            );

            foreach ($validImages as $index => $image) {
                $newFileName =
                    $homestayId . "_" .
                    bin2hex(random_bytes(8)) . "." .
                    $image["extension"];

                $destinationPath =
                    $uploadDirectory . $newFileName;

                if (
                    !move_uploaded_file(
                        $image["temporary_path"],
                        $destinationPath
                    )
                ) {
                    throw new Exception(
                        "Không thể lưu ảnh homestay."
                    );
                }

                $movedFiles[] = $destinationPath;

                $insertImageStatement->execute([
                    "homestay_id" => $homestayId,
                    "image_path" =>
                        "uploads/homestays/" . $newFileName,
                    "is_cover" => $index === 0 ? 1 : 0,
                    "sort_order" => $index
                ]);
            }

            $pdo->commit();

            header("Location: index.php");
            exit;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    foreach ($movedFiles as $movedFile) {
        if (is_file($movedFile)) {
            unlink($movedFile);
        }
    }

    $errors[] = "Lỗi chi tiết: " . $exception->getMessage();
}
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($movedFiles as $movedFile) {
                if (is_file($movedFile)) {
                    unlink($movedFile);
                }
            }

            $errors[] =
                "Không thể thêm homestay. Vui lòng thử lại.";
                  $exception->getMessage();
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

    <title>Thêm homestay - Đi Đâu Đây</title>

    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >

    <style>
        .amenity-box {
            padding: 18px;
            border: 1px solid #e1e7e4;
            border-radius: 10px;
            color: #53635d;
            background: #f8faf9;
            font-size: 12px;
            line-height: 1.8;
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .checkbox-item {
            padding: 13px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid #e1e7e4;
            border-radius: 9px;
            cursor: pointer;
        }

        .checkbox-item input {
            width: auto;
        }

        @media (max-width: 750px) {
            .checkbox-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
            <a href="profile.php">Hồ sơ chủ homestay</a>
            <a href="homestays.php">Homestay của tôi</a>

            <a href="add_homestay.php" class="active">
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
                <p>ĐĂNG CHỖ Ở MỚI</p>
                <h1>Thêm homestay</h1>
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

        <section class="panel">

            <div class="panel-header">
                <div>
                    <h2>Thông tin homestay</h2>

                    <p>
                        Home mới sẽ tự động hiển thị sau khi thêm.
                    </p>
                </div>
            </div>

            <form
                method="POST"
                enctype="multipart/form-data"
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
                        <label for="name">Tên homestay</label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="<?php
                            echo htmlspecialchars($name);
                            ?>"
                            placeholder="Ví dụ: Nhà Gió Thông"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="room_rank">Hạng phòng</label>

                        <select
                            id="room_rank"
                            name="room_rank"
                            required
                        >
                            <option
                                value="standard"
                                <?php
                                echo $roomRank === "standard"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Standard
                            </option>

                            <option
                                value="deluxe"
                                <?php
                                echo $roomRank === "deluxe"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Deluxe
                            </option>

                            <option
                                value="premium"
                                <?php
                                echo $roomRank === "premium"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Premium
                            </option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label for="description">
                            Giới thiệu homestay
                        </label>

                        <textarea
                            id="description"
                            name="description"
                            placeholder="Mô tả không gian, phong cách và vị trí..."
                            required
                        ><?php
                        echo htmlspecialchars($description);
                        ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="address">
                            Địa chỉ cụ thể
                        </label>

                        <input
                            type="text"
                            id="address"
                            name="address"
                            value="<?php
                            echo htmlspecialchars($address);
                            ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="province">
                            Tỉnh hoặc thành phố
                        </label>

                        <input
                            type="text"
                            id="province"
                            name="province"
                            value="<?php
                            echo htmlspecialchars($province);
                            ?>"
                            placeholder="Ví dụ: Lâm Đồng"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="tourist_destination">
                            Điểm du lịch gần nhất
                        </label>

                        <input
                            type="text"
                            id="tourist_destination"
                            name="tourist_destination"
                            value="<?php
                            echo htmlspecialchars(
                                $touristDestination
                            );
                            ?>"
                            placeholder="Ví dụ: Đà Lạt"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="max_guests">
                            Sức chứa
                        </label>

                        <select
                            id="max_guests"
                            name="max_guests"
                            required
                        >
                            <?php for (
                                $number = 1;
                                $number <= 4;
                                $number++
                            ): ?>
                                <option
                                    value="<?php echo $number; ?>"
                                    <?php
                                    echo $maxGuests === $number
                                        ? "selected"
                                        : "";
                                    ?>
                                >
                                    Tối đa
                                    <?php echo $number; ?>
                                    khách
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Giường</label>

                        <input
                            type="text"
                            value="Giường đôi: 1"
                            readonly
                        >
                    </div>

                    <div class="form-group full-width">
                        <h3 style="margin: 10px 0 5px;">
                            Giá theo giờ và combo
                        </h3>

                        <small class="form-help">
                            Chủ homestay tự nhập giá cho từng
                            gói dịch vụ.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="price_first_2_hours">
                            Giá 2 giờ đầu
                        </label>

                        <input
                            type="number"
                            id="price_first_2_hours"
                            name="price_first_2_hours"
                            value="<?php
                            echo htmlspecialchars(
                                (string) $priceFirst2Hours
                            );
                            ?>"
                            min="10000"
                            step="1000"
                            placeholder="Ví dụ: 130000"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="price_combo_4_hours">
                            Giá combo 4 giờ
                        </label>

                        <input
                            type="number"
                            id="price_combo_4_hours"
                            name="price_combo_4_hours"
                            value="<?php
                            echo htmlspecialchars(
                                (string) $priceCombo4Hours
                            );
                            ?>"
                            min="10000"
                            step="1000"
                            placeholder="Ví dụ: 190000"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="price_extra_hour">
                            Giá thêm mỗi giờ
                        </label>

                        <input
                            type="number"
                            id="price_extra_hour"
                            name="price_extra_hour"
                            value="<?php
                            echo htmlspecialchars(
                                (string) $priceExtraHour
                            );
                            ?>"
                            min="10000"
                            step="1000"
                            placeholder="Ví dụ: 40000"
                            required
                        >
                    </div>

                    <div class="form-group full-width">
                        <h3 style="margin: 10px 0 5px;">
                            Giá theo khung giờ
                        </h3>

                        <small class="form-help">
                            T2–T5 là ngày thường,
                            T6–CN là cuối tuần.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="price_overnight_weekday">
                            Giá đêm T2–T5 (22h–10h)
                        </label>

                        <input
                            type="number"
                            id="price_overnight_weekday"
                            name="price_overnight_weekday"
                            value="<?php
                            echo htmlspecialchars(
                                (string) $priceOvernightWeekday
                            );
                            ?>"
                            min="10000"
                            step="1000"
                            placeholder="Ví dụ: 330000"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="price_overnight_weekend">
                            Giá đêm T6–CN (22h–10h)
                        </label>

                        <input
                            type="number"
                            id="price_overnight_weekend"
                            name="price_overnight_weekend"
                            value="<?php
                            echo htmlspecialchars(
                                (string) $priceOvernightWeekend
                            );
                            ?>"
                            min="10000"
                            step="1000"
                            placeholder="Ví dụ: 380000"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="price_day_night_weekday">
                            Giá ngày đêm T2–T5 (15h–10h)
                        </label>

                        <input
                            type="number"
                            id="price_day_night_weekday"
                            name="price_day_night_weekday"
                            value="<?php
                            echo htmlspecialchars(
                                (string) $priceDayNightWeekday
                            );
                            ?>"
                            min="10000"
                            step="1000"
                            placeholder="Ví dụ: 450000"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="price_day_night_weekend">
                            Giá ngày đêm T6–CN (15h–10h)
                        </label>

                        <input
                            type="number"
                            id="price_day_night_weekend"
                            name="price_day_night_weekend"
                            value="<?php
                            echo htmlspecialchars(
                                (string) $priceDayNightWeekend
                            );
                            ?>"
                            min="10000"
                            step="1000"
                            placeholder="Ví dụ: 490000"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="price_day_weekday">
                            Giá ban ngày T2–T5 (11h–21h)
                        </label>

                        <input
                            type="number"
                            id="price_day_weekday"
                            name="price_day_weekday"
                            value="<?php
                            echo htmlspecialchars(
                                (string) $priceDayWeekday
                            );
                            ?>"
                            min="10000"
                            step="1000"
                            placeholder="Ví dụ: 300000"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="price_day_weekend">
                            Giá ban ngày T6–CN (11h–21h)
                        </label>

                        <input
                            type="number"
                            id="price_day_weekend"
                            name="price_day_weekend"
                            value="<?php
                            echo htmlspecialchars(
                                (string) $priceDayWeekend
                            );
                            ?>"
                            min="10000"
                            step="1000"
                            placeholder="Ví dụ: 330000"
                            required
                        >
                    </div>

                    <div class="form-group full-width">
                        <label>Tiện nghi mặc định</label>

                        <div class="amenity-box">
                            ✓ Wi-Fi &nbsp;
                            ✓ Điều hòa &nbsp;
                            ✓ Bếp riêng &nbsp;
                            ✓ Máy giặt &nbsp;
                            ✓ Bãi đỗ xe &nbsp;
                            ✓ Máy chiếu Netflix &nbsp;
                            ✓ Gương toàn thân &nbsp;
                            ✓ Board game &nbsp;
                            ✓ Nhà vệ sinh khép kín &nbsp;
                            ✓ Check-in/out tự động
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label>
                            Tiện ích và dịch vụ khác
                        </label>

                        <div class="checkbox-grid">

                            <label class="checkbox-item">
                                <input
                                    type="checkbox"
                                    name="has_bathtub"
                                    <?php
                                    echo $hasBathtub
                                        ? "checked"
                                        : "";
                                    ?>
                                >

                                Có bồn tắm
                            </label>

                            <label class="checkbox-item">
                                <input
                                    type="checkbox"
                                    name="has_balcony"
                                    <?php
                                    echo $hasBalcony
                                        ? "checked"
                                        : "";
                                    ?>
                                >

                                Có ban công
                            </label>

                            <label class="checkbox-item">

                            <input
                                type="checkbox"
                                name="has_mini_pool"
                                <?php
                                echo $hasMiniPool
                                    ? "checked"
                                    : "";
                                ?>
                            >

                            Có bể bơi mini

                        </label>

                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label for="images">
                            Ảnh homestay
                        </label>

                        <input
                            type="file"
                            id="images"
                            name="images[]"
                            accept=".jpg,.jpeg,.png,.webp"
                            multiple
                            required
                        >

                        <small class="form-help">
                            Chọn từ 1 đến 8 ảnh.
                            Ảnh đầu tiên sẽ được dùng làm ảnh bìa.
                            Mỗi ảnh tối đa 5MB.
                        </small>
                    </div>

                </div>

                <div class="form-footer">
                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Thêm homestay
                    </button>
                </div>

            </form>

        </section>

    </main>
</div>

</body>
</html>