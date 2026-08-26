<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

requireRole("owner");

$ownerId = currentUserId();

$homestayId = (int) ($_GET["id"] ?? 0);

if ($homestayId <= 0) {
    header("Location: homestays.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/* Lấy homestay và bảng giá */

$homestayStatement = $pdo->prepare(
    "SELECT
        h.*,

        hp.price_first_2_hours,
        hp.price_combo_4_hours,
        hp.price_extra_hour,

        hp.price_overnight_weekday,
        hp.price_overnight_weekend,

        hp.price_day_night_weekday,
        hp.price_day_night_weekend,

        hp.price_day_weekday,
        hp.price_day_weekend

    FROM homestays AS h

    LEFT JOIN homestay_prices AS hp
        ON hp.homestay_id = h.id

    WHERE h.id = :id
        AND h.owner_id = :owner_id

    LIMIT 1"
);

$homestayStatement->execute([
    "id" => $homestayId,
    "owner_id" => $ownerId
]);

$homestay = $homestayStatement->fetch(
    PDO::FETCH_ASSOC
);

if (!$homestay) {
    http_response_code(404);

    exit(
        "Không tìm thấy homestay hoặc bạn không có quyền chỉnh sửa."
    );
}

/* Danh sách các mức giá */

$priceFields = [

    "price_first_2_hours" => [
        "label" => "Giá 2 giờ đầu",
        "group" => "Giá theo giờ và combo",
        "placeholder" => "130000"
    ],

    "price_combo_4_hours" => [
        "label" => "Giá combo 4 giờ",
        "group" => "Giá theo giờ và combo",
        "placeholder" => "190000"
    ],

    "price_extra_hour" => [
        "label" => "Giá thêm mỗi giờ",
        "group" => "Giá theo giờ và combo",
        "placeholder" => "40000"
    ],

    "price_overnight_weekday" => [
        "label" => "Giá đêm T2–T5 (22h–10h)",
        "group" => "Giá theo khung giờ",
        "placeholder" => "330000"
    ],

    "price_overnight_weekend" => [
        "label" => "Giá đêm T6–CN (22h–10h)",
        "group" => "Giá theo khung giờ",
        "placeholder" => "380000"
    ],

    "price_day_night_weekday" => [
        "label" => "Giá ngày đêm T2–T5 (15h–10h)",
        "group" => "Giá theo khung giờ",
        "placeholder" => "450000"
    ],

    "price_day_night_weekend" => [
        "label" => "Giá ngày đêm T6–CN (15h–10h)",
        "group" => "Giá theo khung giờ",
        "placeholder" => "490000"
    ],

    "price_day_weekday" => [
        "label" => "Giá ban ngày T2–T5 (11h–21h)",
        "group" => "Giá theo khung giờ",
        "placeholder" => "300000"
    ],

    "price_day_weekend" => [
        "label" => "Giá ban ngày T6–CN (11h–21h)",
        "group" => "Giá theo khung giờ",
        "placeholder" => "330000"
    ]

];

/* Dữ liệu ban đầu */

$formData = [
    "name" => $homestay["name"],
    "room_rank" => $homestay["room_rank"],
    "description" => $homestay["description"],
    "address" => $homestay["address"],
    "province" => $homestay["province"],

    "tourist_destination" =>
        $homestay["tourist_destination"],

    "max_guests" =>
        (int) $homestay["max_guests"],

    "status" =>
        $homestay["status"],

    "has_bathtub" =>
        (int) $homestay["has_bathtub"],

    "has_balcony" =>
        (int) $homestay["has_balcony"],

    "has_mini_pool" =>
        (int) ($homestay["has_mini_pool"] ?? 0)
];

foreach ($priceFields as $fieldName => $fieldInformation) {
    $formData[$fieldName] =
        $homestay[$fieldName] ?? "";
}

$errors = [];

/* Kiểm tra chủ nhà có được đổi trạng thái hay không */

$canChangeStatus = in_array(
    $homestay["status"],
    ["approved", "maintenance"],
    true
);

/* Xử lý các thao tác */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    $action = $_POST["action"] ?? "";

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Yêu cầu không hợp lệ.";
    }

    /*
     * Chọn một ảnh làm ảnh bìa.
     */

    if (
        empty($errors) &&
        $action === "set_cover"
    ) {
        $imageId = (int) ($_POST["image_id"] ?? 0);

        $checkImageStatement = $pdo->prepare(
            "SELECT id

             FROM homestay_images

             WHERE id = :id
                AND homestay_id = :homestay_id

             LIMIT 1"
        );

        $checkImageStatement->execute([
            "id" => $imageId,
            "homestay_id" => $homestayId
        ]);

        if (!$checkImageStatement->fetch()) {
            $errors[] = "Không tìm thấy ảnh cần chọn.";
        } else {
            try {
                $pdo->beginTransaction();

                $removeOldCoverStatement = $pdo->prepare(
                    "UPDATE homestay_images

                     SET is_cover = 0

                     WHERE homestay_id = :homestay_id"
                );

                $removeOldCoverStatement->execute([
                    "homestay_id" => $homestayId
                ]);

                $setCoverStatement = $pdo->prepare(
                    "UPDATE homestay_images

                     SET is_cover = 1

                     WHERE id = :id
                        AND homestay_id = :homestay_id"
                );

                $setCoverStatement->execute([
                    "id" => $imageId,
                    "homestay_id" => $homestayId
                ]);

                $pdo->commit();

                header(
                    "Location: edit_homestay.php?id=" .
                    $homestayId .
                    "&cover_updated=1"
                );

                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] =
                    "Không thể thay đổi ảnh bìa.";
            }
        }
    }

    /*
     * Xóa ảnh.
     */

    if (
        empty($errors) &&
        $action === "delete_image"
    ) {
        $imageId = (int) ($_POST["image_id"] ?? 0);

        $imageStatement = $pdo->prepare(
            "SELECT
                id,
                image_path,
                is_cover

             FROM homestay_images

             WHERE id = :id
                AND homestay_id = :homestay_id

             LIMIT 1"
        );

        $imageStatement->execute([
            "id" => $imageId,
            "homestay_id" => $homestayId
        ]);

        $imageToDelete = $imageStatement->fetch(
            PDO::FETCH_ASSOC
        );

        $imageCountStatement = $pdo->prepare(
            "SELECT COUNT(*)

             FROM homestay_images

             WHERE homestay_id = :homestay_id"
        );

        $imageCountStatement->execute([
            "homestay_id" => $homestayId
        ]);

        $existingImageCount = (int) (
            $imageCountStatement->fetchColumn()
        );

        if (!$imageToDelete) {
            $errors[] = "Không tìm thấy ảnh cần xóa.";
        } elseif ($existingImageCount <= 1) {
            $errors[] =
                "Homestay phải giữ lại ít nhất một ảnh.";
        } else {
            try {
                $pdo->beginTransaction();

                $deleteImageStatement = $pdo->prepare(
                    "DELETE FROM homestay_images

                     WHERE id = :id
                        AND homestay_id = :homestay_id"
                );

                $deleteImageStatement->execute([
                    "id" => $imageId,
                    "homestay_id" => $homestayId
                ]);

                if (
                    (int) $imageToDelete["is_cover"] === 1
                ) {
                    $nextCoverStatement = $pdo->prepare(
                        "SELECT id

                         FROM homestay_images

                         WHERE homestay_id = :homestay_id

                         ORDER BY
                            sort_order ASC,
                            id ASC

                         LIMIT 1"
                    );

                    $nextCoverStatement->execute([
                        "homestay_id" => $homestayId
                    ]);

                    $nextCoverId = (
                        $nextCoverStatement->fetchColumn()
                    );

                    if ($nextCoverId) {
                        $newCoverStatement = $pdo->prepare(
                            "UPDATE homestay_images

                             SET is_cover = 1

                             WHERE id = :id
                                AND homestay_id = :homestay_id"
                        );

                        $newCoverStatement->execute([
                            "id" => $nextCoverId,
                            "homestay_id" => $homestayId
                        ]);
                    }
                }

                $pdo->commit();

                $imageFilePath =
                    __DIR__ .
                    "/../" .
                    ltrim(
                        $imageToDelete["image_path"],
                        "/"
                    );

                $uploadDirectory = realpath(
                    __DIR__ . "/../uploads/homestays"
                );

                $realImagePath = realpath(
                    $imageFilePath
                );

                if (
                    $uploadDirectory !== false &&
                    $realImagePath !== false &&
                    str_starts_with(
                        $realImagePath,
                        $uploadDirectory .
                        DIRECTORY_SEPARATOR
                    ) &&
                    is_file($realImagePath)
                ) {
                    unlink($realImagePath);
                }

                header(
                    "Location: edit_homestay.php?id=" .
                    $homestayId .
                    "&image_deleted=1"
                );

                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] =
                    "Không thể xóa ảnh homestay.";
            }
        }
    }

    /*
     * Lưu thông tin homestay.
     */

    if (
        empty($errors) &&
        $action === "save_homestay"
    ) {
        $formData["name"] = trim(
            $_POST["name"] ?? ""
        );

        $formData["room_rank"] =
            $_POST["room_rank"] ?? "standard";

        $formData["description"] = trim(
            $_POST["description"] ?? ""
        );

        $formData["address"] = trim(
            $_POST["address"] ?? ""
        );

        $formData["province"] = trim(
            $_POST["province"] ?? ""
        );

        $formData["tourist_destination"] = trim(
            $_POST["tourist_destination"] ?? ""
        );

        $formData["max_guests"] = (int) (
            $_POST["max_guests"] ?? 2
        );

        $formData["has_bathtub"] = isset(
            $_POST["has_bathtub"]
        ) ? 1 : 0;

        $formData["has_balcony"] = isset(
            $_POST["has_balcony"]
        ) ? 1 : 0;

        $formData["has_mini_pool"] = isset(
            $_POST["has_mini_pool"]
        ) ? 1 : 0;

        if ($canChangeStatus) {
            $formData["status"] =
                $_POST["status"] ??
                $homestay["status"];
        } else {
            $formData["status"] =
                $homestay["status"];
        }

        foreach (
            $priceFields as $fieldName => $fieldInformation
        ) {
            $formData[$fieldName] = (float) (
                $_POST[$fieldName] ?? 0
            );
        }

        /* Kiểm tra thông tin */

        if (strlen($formData["name"]) < 3) {
            $errors[] =
                "Tên homestay phải có ít nhất 3 ký tự.";
        }

        if (
            !in_array(
                $formData["room_rank"],
                ["standard", "deluxe", "premium"],
                true
            )
        ) {
            $errors[] =
                "Hạng phòng không hợp lệ.";
        }

        if (
            strlen(
                $formData["description"]
            ) < 20
        ) {
            $errors[] =
                "Phần giới thiệu phải có ít nhất 20 ký tự.";
        }

        if (
            strlen(
                $formData["address"]
            ) < 10
        ) {
            $errors[] =
                "Vui lòng nhập địa chỉ đầy đủ.";
        }

        if ($formData["province"] === "") {
            $errors[] =
                "Vui lòng nhập tỉnh hoặc thành phố.";
        }

        if (
            $formData["tourist_destination"] === ""
        ) {
            $errors[] =
                "Vui lòng nhập điểm du lịch gần nhất.";
        }

        if (
            $formData["max_guests"] < 1 ||
            $formData["max_guests"] > 4
        ) {
            $errors[] =
                "Sức chứa chỉ được từ 1 đến 4 khách.";
        }

        if (
            $canChangeStatus &&
            !in_array(
                $formData["status"],
                ["approved", "maintenance"],
                true
            )
        ) {
            $errors[] =
                "Trạng thái homestay không hợp lệ.";
        }

        foreach (
            $priceFields as $fieldName => $fieldInformation
        ) {
            if ($formData[$fieldName] <= 0) {
                $errors[] =
                    $fieldInformation["label"] .
                    " phải lớn hơn 0.";
            }
        }

        if (
            $formData["price_combo_4_hours"] <
            $formData["price_first_2_hours"]
        ) {
            $errors[] =
                "Giá combo 4 giờ không được thấp hơn giá 2 giờ đầu.";
        }

        /* Kiểm tra ảnh mới */

        $newImages = $_FILES["new_images"] ?? null;

        $newImageCount = 0;

        if (
            $newImages &&
            isset($newImages["name"]) &&
            is_array($newImages["name"])
        ) {
            foreach (
                $newImages["name"] as $newImageName
            ) {
                if ($newImageName !== "") {
                    $newImageCount++;
                }
            }
        }

        $countCurrentImagesStatement = $pdo->prepare(
            "SELECT COUNT(*)

             FROM homestay_images

             WHERE homestay_id = :homestay_id"
        );

        $countCurrentImagesStatement->execute([
            "homestay_id" => $homestayId
        ]);

        $currentImageCount = (int) (
            $countCurrentImagesStatement->fetchColumn()
        );

        if (
            $currentImageCount + $newImageCount > 8
        ) {
            $errors[] =
                "Mỗi homestay chỉ được có tối đa 8 ảnh.";
        }

        $allowedMimeTypes = [
            "image/jpeg" => "jpg",
            "image/png" => "png",
            "image/webp" => "webp"
        ];

        $validNewImages = [];

        if ($newImageCount > 0) {
            $fileInformation = new finfo(
                FILEINFO_MIME_TYPE
            );

            foreach (
                $newImages["name"] as $index => $fileName
            ) {
                if ($fileName === "") {
                    continue;
                }

                if (
                    $newImages["error"][$index]
                    !== UPLOAD_ERR_OK
                ) {
                    $errors[] =
                        "Có ảnh tải lên không thành công.";

                    continue;
                }

                if (
                    $newImages["size"][$index]
                    > 5 * 1024 * 1024
                ) {
                    $errors[] =
                        "Mỗi ảnh không được lớn hơn 5MB.";

                    continue;
                }

                $temporaryPath =
                    $newImages["tmp_name"][$index];

                $mimeType =
                    $fileInformation->file(
                        $temporaryPath
                    );

                if (
                    !isset(
                        $allowedMimeTypes[$mimeType]
                    )
                ) {
                    $errors[] =
                        "Chỉ chấp nhận ảnh JPG, PNG hoặc WEBP.";

                    continue;
                }

                $validNewImages[] = [
                    "temporary_path" => $temporaryPath,
                    "extension" =>
                        $allowedMimeTypes[$mimeType]
                ];
            }
        }

        $uploadDirectory =
            __DIR__ . "/../uploads/homestays/";

        if (
            $newImageCount > 0 &&
            !is_dir($uploadDirectory)
        ) {
            $errors[] =
                "Không tìm thấy thư mục uploads/homestays.";
        }

        if (
            $newImageCount > 0 &&
            is_dir($uploadDirectory) &&
            !is_writable($uploadDirectory)
        ) {
            $errors[] =
                "Thư mục ảnh không cho phép ghi dữ liệu.";
        }

        if (empty($errors)) {
            $movedFiles = [];

            try {
                $pdo->beginTransaction();

                /*
                 * Giữ khoảng giá tương thích
                 * với cấu trúc homestays hiện tại.
                 */

                $minimumDisplayPrice =
                    $formData[
                        "price_first_2_hours"
                    ];

                $maximumDisplayPrice = max(
                    $formData[
                        "price_first_2_hours"
                    ],
                    $formData[
                        "price_combo_4_hours"
                    ],
                    $formData[
                        "price_overnight_weekday"
                    ],
                    $formData[
                        "price_overnight_weekend"
                    ],
                    $formData[
                        "price_day_night_weekday"
                    ],
                    $formData[
                        "price_day_night_weekend"
                    ],
                    $formData[
                        "price_day_weekday"
                    ],
                    $formData[
                        "price_day_weekend"
                    ]
                );

                /* Cập nhật thông tin chính */

                $updateHomestayStatement = $pdo->prepare(
                    "UPDATE homestays

                     SET
                        name = :name,
                        room_rank = :room_rank,
                        description = :description,
                        address = :address,
                        province = :province,
                        tourist_destination = :tourist_destination,
                        max_guests = :max_guests,
                        price_per_hour = :price_per_hour,
                        overnight_price = :overnight_price,
                        auto_checkin = 1,
                        has_bathtub = :has_bathtub,
                        has_balcony = :has_balcony,
                        has_mini_pool = :has_mini_pool,
                        status = :status

                     WHERE id = :id
                        AND owner_id = :owner_id"
                );

                $updateHomestayStatement->execute([
                    "name" =>
                        $formData["name"],

                    "room_rank" =>
                        $formData["room_rank"],

                    "description" =>
                        $formData["description"],

                    "address" =>
                        $formData["address"],

                    "province" =>
                        $formData["province"],

                    "tourist_destination" =>
                        $formData[
                            "tourist_destination"
                        ],

                    "max_guests" =>
                        $formData["max_guests"],

                    "price_per_hour" =>
                        $minimumDisplayPrice,

                    "overnight_price" =>
                        $maximumDisplayPrice,

                    "has_bathtub" =>
                        $formData["has_bathtub"],

                    "has_balcony" =>
                        $formData["has_balcony"],
                    
                    "has_mini_pool" =>
                        $formData["has_mini_pool"],

                    "status" =>
                        $formData["status"],

                    "id" => $homestayId,

                    "owner_id" => $ownerId
                ]);

                /* Cập nhật hoặc tạo bảng giá */

                $updatePricesStatement = $pdo->prepare(
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
                    )

                    ON DUPLICATE KEY UPDATE

                        price_first_2_hours =
                            VALUES(price_first_2_hours),

                        price_combo_4_hours =
                            VALUES(price_combo_4_hours),

                        price_extra_hour =
                            VALUES(price_extra_hour),

                        price_overnight_weekday =
                            VALUES(price_overnight_weekday),

                        price_overnight_weekend =
                            VALUES(price_overnight_weekend),

                        price_day_night_weekday =
                            VALUES(price_day_night_weekday),

                        price_day_night_weekend =
                            VALUES(price_day_night_weekend),

                        price_day_weekday =
                            VALUES(price_day_weekday),

                        price_day_weekend =
                            VALUES(price_day_weekend)"
                );

                $updatePricesStatement->execute([
                    "homestay_id" => $homestayId,

                    "price_first_2_hours" =>
                        $formData[
                            "price_first_2_hours"
                        ],

                    "price_combo_4_hours" =>
                        $formData[
                            "price_combo_4_hours"
                        ],

                    "price_extra_hour" =>
                        $formData[
                            "price_extra_hour"
                        ],

                    "price_overnight_weekday" =>
                        $formData[
                            "price_overnight_weekday"
                        ],

                    "price_overnight_weekend" =>
                        $formData[
                            "price_overnight_weekend"
                        ],

                    "price_day_night_weekday" =>
                        $formData[
                            "price_day_night_weekday"
                        ],

                    "price_day_night_weekend" =>
                        $formData[
                            "price_day_night_weekend"
                        ],

                    "price_day_weekday" =>
                        $formData[
                            "price_day_weekday"
                        ],

                    "price_day_weekend" =>
                        $formData[
                            "price_day_weekend"
                        ]
                ]);

                /* Thêm ảnh mới */

                if (!empty($validNewImages)) {
                    $sortOrderStatement = $pdo->prepare(
                        "SELECT
                            COALESCE(
                                MAX(sort_order),
                                -1
                            )

                         FROM homestay_images

                         WHERE homestay_id =
                            :homestay_id"
                    );

                    $sortOrderStatement->execute([
                        "homestay_id" => $homestayId
                    ]);

                    $nextSortOrder = (int) (
                        $sortOrderStatement->fetchColumn()
                    ) + 1;

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

                    foreach (
                        $validNewImages as $image
                    ) {
                        $newFileName =
                            $homestayId .
                            "_" .
                            bin2hex(
                                random_bytes(8)
                            ) .
                            "." .
                            $image["extension"];

                        $destinationPath =
                            $uploadDirectory .
                            $newFileName;

                        if (
                            !move_uploaded_file(
                                $image[
                                    "temporary_path"
                                ],
                                $destinationPath
                            )
                        ) {
                            throw new Exception(
                                "Không thể lưu ảnh homestay."
                            );
                        }

                        $movedFiles[] =
                            $destinationPath;

                        $insertImageStatement->execute([
                            "homestay_id" =>
                                $homestayId,

                            "image_path" =>
                                "uploads/homestays/" .
                                $newFileName,

                            "is_cover" => 0,

                            "sort_order" =>
                                $nextSortOrder
                        ]);

                        $nextSortOrder++;
                    }
                }

                $pdo->commit();

                header(
                    "Location: edit_homestay.php?id=" .
                    $homestayId .
                    "&saved=1"
                );

                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                foreach (
                    $movedFiles as $movedFile
                ) {
                    if (is_file($movedFile)) {
                        unlink($movedFile);
                    }
                }

                $errors[] =
                    "Không thể lưu thông tin homestay.";
            }
        }
    }
}

/* Lấy ảnh hiện tại */

$imagesStatement = $pdo->prepare(
    "SELECT
        id,
        image_path,
        is_cover,
        sort_order

     FROM homestay_images

     WHERE homestay_id = :homestay_id

     ORDER BY
        is_cover DESC,
        sort_order ASC,
        id ASC"
);

$imagesStatement->execute([
    "homestay_id" => $homestayId
]);

$images = $imagesStatement->fetchAll(
    PDO::FETCH_ASSOC
);

/* Thông báo */

$successMessage = "";

if (isset($_GET["saved"])) {
    $successMessage =
        "Cập nhật homestay thành công.";
}

if (isset($_GET["cover_updated"])) {
    $successMessage =
        "Đã thay đổi ảnh bìa homestay.";
}

if (isset($_GET["image_deleted"])) {
    $successMessage =
        "Đã xóa ảnh homestay.";
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

    <title>
        Chỉnh sửa homestay - Đi Đâu Đây
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >

    <style>
        .edit-panel {
            margin-bottom: 22px;
            overflow: hidden;
            border: 1px solid #dfe7e3;
            border-radius: 17px;
            background: #ffffff;
        }

        .edit-panel-header {
            padding: 21px 23px;
            border-bottom: 1px solid #e8edeb;
        }

        .edit-panel-header h2 {
            margin: 0;
            color: #25372f;
            font-size: 20px;
        }

        .edit-panel-header p {
            margin: 5px 0 0;
            color: #7b8780;
            font-size: 13px;
        }

        .edit-content {
            padding: 23px;
        }

        .amenity-box {
            padding: 17px;
            border: 1px solid #e0e7e3;
            border-radius: 10px;
            color: #54645d;
            background: #f7faf8;
            font-size: 13px;
            line-height: 1.9;
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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

        .image-grid {
            display: grid;
            grid-template-columns: repeat(
                4,
                minmax(0, 1fr)
            );
            gap: 17px;
        }

        .image-card {
            overflow: hidden;
            border: 1px solid #e1e7e4;
            border-radius: 13px;
            background: #ffffff;
        }

        .image-preview {
            position: relative;
            height: 160px;
            overflow: hidden;
            background: #eaf0ed;
        }

        .image-preview img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }

        .cover-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            padding: 6px 9px;
            border-radius: 999px;
            color: #ffffff;
            background: #1d624c;
            font-size: 10px;
            font-weight: 800;
        }

        .image-actions {
            padding: 12px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .image-actions form {
            margin: 0;
        }

        .image-button {
            padding: 8px 10px;
            border: none;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }

        .image-button-cover {
            color: #235944;
            background: #eaf3ee;
        }

        .image-button-delete {
            color: #a14343;
            background: #fdeeee;
        }

        .message-success {
            margin-bottom: 18px;
            padding: 14px 17px;
            border: 1px solid #cde8d6;
            border-radius: 10px;
            color: #266643;
            background: #edf8f0;
        }

        .message-error {
            margin-bottom: 18px;
            padding: 14px 17px;
            border: 1px solid #efcccc;
            border-radius: 10px;
            color: #974545;
            background: #fff1f1;
        }

        @media (max-width: 1000px) {
            .image-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 650px) {
            .image-grid,
            .checkbox-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<style>
    .checkbox-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }

    @media (max-width: 750px) {
        .checkbox-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

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
                    QUẢN LÝ THÔNG TIN
                </p>

                <h1>
                    Chỉnh sửa homestay
                </h1>

            </div>

            <a
                href="homestays.php"
                class="button button-outline"
            >
                ← Homestay của tôi
            </a>

        </header>

        <?php if ($successMessage !== ""): ?>

            <div class="message-success">

                <?php
                echo htmlspecialchars(
                    $successMessage
                );
                ?>

            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="message-error">

                <?php foreach (
                    $errors as $error
                ): ?>

                    <div>
                        •
                        <?php
                        echo htmlspecialchars(
                            $error
                        );
                        ?>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <section class="edit-panel">

            <div class="edit-panel-header">

                <h2>
                    Thông tin homestay
                </h2>

                <p>
                    Cập nhật thông tin, bảng giá và tiện nghi.
                </p>

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

                <input
                    type="hidden"
                    name="action"
                    value="save_homestay"
                >

                <div class="edit-content">

                    <div class="form-grid">

                        <div class="form-group">

                            <label for="name">
                                Tên homestay
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="<?php
                                echo htmlspecialchars(
                                    $formData["name"]
                                );
                                ?>"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label for="room_rank">
                                Hạng phòng
                            </label>

                            <select
                                id="room_rank"
                                name="room_rank"
                                required
                            >

                                <?php foreach (
                                    [
                                        "standard" => "Standard",
                                        "deluxe" => "Deluxe",
                                        "premium" => "Premium"
                                    ] as $rankValue => $rankName
                                ): ?>

                                    <option
                                        value="<?php
                                        echo htmlspecialchars(
                                            $rankValue
                                        );
                                        ?>"
                                        <?php
                                        echo (
                                            $formData[
                                                "room_rank"
                                            ] ===
                                            $rankValue
                                        )
                                            ? "selected"
                                            : "";
                                        ?>
                                    >
                                        <?php
                                        echo htmlspecialchars(
                                            $rankName
                                        );
                                        ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div
                            class="form-group full-width"
                        >

                            <label for="description">
                                Giới thiệu homestay
                            </label>

                            <textarea
                                id="description"
                                name="description"
                                required
                            ><?php
                            echo htmlspecialchars(
                                $formData[
                                    "description"
                                ]
                            );
                            ?></textarea>

                        </div>

                        <div
                            class="form-group full-width"
                        >

                            <label for="address">
                                Địa chỉ cụ thể
                            </label>

                            <input
                                type="text"
                                id="address"
                                name="address"
                                value="<?php
                                echo htmlspecialchars(
                                    $formData[
                                        "address"
                                    ]
                                );
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
                                echo htmlspecialchars(
                                    $formData[
                                        "province"
                                    ]
                                );
                                ?>"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label
                                for="tourist_destination"
                            >
                                Điểm du lịch gần nhất
                            </label>

                            <input
                                type="text"
                                id="tourist_destination"
                                name="tourist_destination"
                                value="<?php
                                echo htmlspecialchars(
                                    $formData[
                                        "tourist_destination"
                                    ]
                                );
                                ?>"
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
                                        value="<?php
                                        echo $number;
                                        ?>"
                                        <?php
                                        echo (
                                            (int) $formData[
                                                "max_guests"
                                            ] === $number
                                        )
                                            ? "selected"
                                            : "";
                                        ?>
                                    >
                                        Tối đa

                                        <?php
                                        echo $number;
                                        ?>

                                        khách
                                    </option>

                                <?php endfor; ?>

                            </select>

                        </div>

                        <div class="form-group">

                            <label>
                                Giường
                            </label>

                            <input
                                type="text"
                                value="Giường đôi: 1"
                                readonly
                            >

                        </div>

                        <?php if (
                            $canChangeStatus
                        ): ?>

                            <div class="form-group">

                                <label for="status">
                                    Trạng thái
                                </label>

                                <select
                                    id="status"
                                    name="status"
                                >

                                    <option
                                        value="approved"
                                        <?php
                                        echo (
                                            $formData[
                                                "status"
                                            ] ===
                                            "approved"
                                        )
                                            ? "selected"
                                            : "";
                                        ?>
                                    >
                                        Đang hoạt động
                                    </option>

                                    <option
                                        value="maintenance"
                                        <?php
                                        echo (
                                            $formData[
                                                "status"
                                            ] ===
                                            "maintenance"
                                        )
                                            ? "selected"
                                            : "";
                                        ?>
                                    >
                                        Đang bảo trì
                                    </option>

                                </select>

                            </div>

                        <?php endif; ?>

                        <?php

                        $currentPriceGroup = "";

                        ?>

                        <?php foreach (
                            $priceFields as
                            $fieldName => $fieldInformation
                        ): ?>

                            <?php if (
                                $currentPriceGroup !==
                                $fieldInformation["group"]
                            ): ?>

                                <div
                                    class="form-group full-width"
                                >

                                    <h3
                                        style="margin: 12px 0 4px;"
                                    >
                                        <?php
                                        echo htmlspecialchars(
                                            $fieldInformation[
                                                "group"
                                            ]
                                        );
                                        ?>
                                    </h3>

                                </div>

                                <?php

                                $currentPriceGroup =
                                    $fieldInformation[
                                        "group"
                                    ];

                                ?>

                            <?php endif; ?>

                            <div class="form-group">

                                <label
                                    for="<?php
                                    echo htmlspecialchars(
                                        $fieldName
                                    );
                                    ?>"
                                >
                                    <?php
                                    echo htmlspecialchars(
                                        $fieldInformation[
                                            "label"
                                        ]
                                    );
                                    ?>
                                </label>

                                <input
                                    type="number"
                                    id="<?php
                                    echo htmlspecialchars(
                                        $fieldName
                                    );
                                    ?>"
                                    name="<?php
                                    echo htmlspecialchars(
                                        $fieldName
                                    );
                                    ?>"
                                    value="<?php
                                    echo htmlspecialchars(
                                        (string) $formData[
                                            $fieldName
                                        ]
                                    );
                                    ?>"
                                    min="10000"
                                    step="1000"
                                    placeholder="<?php
                                    echo htmlspecialchars(
                                        $fieldInformation[
                                            "placeholder"
                                        ]
                                    );
                                    ?>"
                                    required
                                >

                            </div>

                        <?php endforeach; ?>

                        <div
                            class="form-group full-width"
                        >

                            <label>
                                Tiện nghi mặc định
                            </label>

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

                        <div
                            class="form-group full-width"
                        >

                            <label>
                                Tiện ích bổ sung
                            </label>

                            <div class="checkbox-grid">

                                <label
                                    class="checkbox-item"
                                >

                                    <input
                                        type="checkbox"
                                        name="has_bathtub"
                                        <?php
                                        echo (
                                            (int) $formData[
                                                "has_bathtub"
                                            ] === 1
                                        )
                                            ? "checked"
                                            : "";
                                        ?>
                                    >

                                    Có bồn tắm

                                </label>

                                <label
                                    class="checkbox-item"
                                >

                                    <input
                                        type="checkbox"
                                        name="has_balcony"
                                        <?php
                                        echo (
                                            (int) $formData[
                                                "has_balcony"
                                            ] === 1
                                        )
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
                                        echo (
                                            (int) $formData[
                                                "has_mini_pool"
                                            ] === 1
                                        )
                                            ? "checked"
                                            : "";
                                        ?>
                                    >

                                    Có bể bơi mini

                                </label>

                            </div>

                        </div>

                        <div
                            class="form-group full-width"
                        >

                            <label for="new_images">
                                Thêm ảnh mới
                            </label>

                            <input
                                type="file"
                                id="new_images"
                                name="new_images[]"
                                accept=".jpg,.jpeg,.png,.webp"
                                multiple
                            >

                            <small class="form-help">

                                Hiện có

                                <?php
                                echo count($images);
                                ?>

                                / 8 ảnh.

                                Mỗi ảnh tối đa 5MB.

                            </small>

                        </div>

                    </div>

                </div>

                <div class="form-footer">

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Lưu thay đổi
                    </button>

                </div>

            </form>

        </section>

        <section class="edit-panel">

            <div class="edit-panel-header">

                <h2>
                    Ảnh homestay
                </h2>

                <p>
                    Chọn ảnh bìa hoặc xóa ảnh không cần thiết.
                </p>

            </div>

            <div class="edit-content">

                <?php if (empty($images)): ?>

                    <p>
                        Chưa có ảnh homestay.
                    </p>

                <?php else: ?>

                    <div class="image-grid">

                        <?php foreach (
                            $images as $image
                        ): ?>

                            <article class="image-card">

                                <div
                                    class="image-preview"
                                >

                                    <img
                                        src="../<?php
                                        echo htmlspecialchars(
                                            $image[
                                                "image_path"
                                            ]
                                        );
                                        ?>"
                                        alt="Ảnh homestay"
                                    >

                                    <?php if (
                                        (int) $image[
                                            "is_cover"
                                        ] === 1
                                    ): ?>

                                        <span
                                            class="cover-badge"
                                        >
                                            Ảnh bìa
                                        </span>

                                    <?php endif; ?>

                                </div>

                                <div
                                    class="image-actions"
                                >

                                    <?php if (
                                        (int) $image[
                                            "is_cover"
                                        ] !== 1
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
                                                name="action"
                                                value="set_cover"
                                            >

                                            <input
                                                type="hidden"
                                                name="image_id"
                                                value="<?php
                                                echo (int) (
                                                    $image[
                                                        "id"
                                                    ]
                                                );
                                                ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="image-button image-button-cover"
                                            >
                                                Làm ảnh bìa
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                    <?php if (
                                        count($images) > 1
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
                                                name="action"
                                                value="delete_image"
                                            >

                                            <input
                                                type="hidden"
                                                name="image_id"
                                                value="<?php
                                                echo (int) (
                                                    $image[
                                                        "id"
                                                    ]
                                                );
                                                ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="image-button image-button-delete"
                                                onclick="return confirm('Bạn có chắc muốn xóa ảnh này?');"
                                            >
                                                Xóa ảnh
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

</body>
</html>