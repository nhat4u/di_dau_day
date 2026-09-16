-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Máy chủ: 127.0.0.1
-- Thời gian đã tạo: Th9 11, 2026 lúc 04:00 PM
-- Phiên bản máy phục vụ: 10.4.32-MariaDB
-- Phiên bản PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Cơ sở dữ liệu: `di_dau_day`
--

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `bookings`
--

CREATE TABLE `bookings` (
  `id` int(10) UNSIGNED NOT NULL,
  `booking_code` varchar(30) NOT NULL,
  `guest_id` int(10) UNSIGNED NOT NULL,
  `homestay_id` int(10) UNSIGNED NOT NULL,
  `booking_type` enum('hourly','overnight','daytime','day_night') NOT NULL,
  `check_in` datetime NOT NULL,
  `check_out` datetime NOT NULL,
  `guest_count` tinyint(3) UNSIGNED NOT NULL,
  `total_amount` decimal(12,0) NOT NULL,
  `status` enum('pending_payment','funds_held','confirmed','completed','cancelled','refunded','disputed') NOT NULL DEFAULT 'pending_payment',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `bookings`
--

INSERT INTO `bookings` (`id`, `booking_code`, `guest_id`, `homestay_id`, `booking_type`, `check_in`, `check_out`, `guest_count`, `total_amount`, `status`, `created_at`, `updated_at`) VALUES
(1, 'DDD2608241121389D468C', 2, 1, 'hourly', '2026-08-24 14:00:00', '2026-08-24 16:00:00', 2, 150000, 'refunded', '2026-08-24 04:21:38', '2026-08-24 18:05:49'),
(2, 'DDD260824113205E56D76', 2, 1, 'hourly', '2026-08-24 17:00:00', '2026-08-24 19:00:00', 2, 150000, 'completed', '2026-08-24 04:32:05', '2026-08-24 17:08:39'),
(3, 'DDD260824113440547F2D', 2, 1, 'hourly', '2026-08-24 19:00:00', '2026-08-24 21:00:00', 2, 150000, 'completed', '2026-08-24 04:34:40', '2026-08-24 17:08:49'),
(4, 'DDD260824235429893160', 2, 1, 'hourly', '2026-08-25 14:00:00', '2026-08-25 16:00:00', 2, 150000, 'cancelled', '2026-08-24 16:54:29', '2026-08-24 17:09:04'),
(5, 'DDD2609072015120C2206', 5, 4, 'hourly', '2026-09-07 21:03:30', '2026-09-07 23:02:30', 2, 150000, 'completed', '2026-09-07 13:15:12', '2026-09-07 16:06:42'),
(6, 'DDD260907232425537A1E', 5, 4, 'hourly', '2026-09-12 14:00:00', '2026-09-12 16:00:00', 2, 150000, 'refunded', '2026-09-07 16:24:25', '2026-09-07 16:29:16'),
(7, 'DDD26091106411011D441', 2, 3, 'hourly', '2026-09-12 14:00:00', '2026-09-12 20:00:00', 1, 350000, 'confirmed', '2026-09-10 23:41:10', '2026-09-11 00:36:20');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `homestays`
--

CREATE TABLE `homestays` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(180) NOT NULL,
  `room_rank` enum('standard','deluxe','premium') NOT NULL DEFAULT 'standard',
  `description` text NOT NULL,
  `address` varchar(255) NOT NULL,
  `province` varchar(100) NOT NULL,
  `tourist_destination` varchar(150) NOT NULL,
  `max_guests` tinyint(3) UNSIGNED NOT NULL DEFAULT 4,
  `price_per_hour` decimal(12,0) NOT NULL,
  `minimum_hours` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `auto_checkin` tinyint(1) NOT NULL DEFAULT 1,
  `has_bathtub` tinyint(1) NOT NULL DEFAULT 0,
  `has_balcony` tinyint(1) NOT NULL DEFAULT 0,
  `has_mini_pool` tinyint(1) NOT NULL DEFAULT 0,
  `overnight_price` decimal(12,0) NOT NULL,
  `status` enum('draft','pending','approved','rejected','maintenance') NOT NULL DEFAULT 'approved',
  `rejection_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `homestays`
--

INSERT INTO `homestays` (`id`, `owner_id`, `name`, `slug`, `room_rank`, `description`, `address`, `province`, `tourist_destination`, `max_guests`, `price_per_hour`, `minimum_hours`, `auto_checkin`, `has_bathtub`, `has_balcony`, `has_mini_pool`, `overnight_price`, `status`, `rejection_reason`, `created_at`, `updated_at`, `is_deleted`) VALUES
(1, 4, 'Nana Homestay', 'nana-homestay', 'premium', 'Phòng rộng rãi, xinh iu và đầy đủ tiện nghi', 'Xuân Đỉnh', 'Hà Nội', 'Tây Hồ', 3, 150000, 2, 1, 0, 0, 0, 490000, 'approved', NULL, '2026-08-21 17:40:40', '2026-08-24 16:42:07', 0),
(2, 4, 'Lago Homestay', 'lago-homestay', 'deluxe', 'Không gian nhỏ xinh, view triệu đô và đầy đủ tiện nghi.', 'Tây Hồ, Hà Nội', 'Hà Nội', 'Tây Hồ', 2, 150000, 2, 1, 0, 0, 0, 550000, 'approved', NULL, '2026-09-07 02:54:37', '2026-09-07 02:54:37', 0),
(3, 4, 'Ocean Homestay', 'ocean-homestay', 'deluxe', 'Không gian ấm cúng, đầy đủ tiện nghi, ban công thoáng mát.', 'Tây Hồ, Hà Nội', 'Hà Nội', 'Tây Hồ', 2, 190000, 2, 1, 0, 1, 0, 520000, 'approved', NULL, '2026-09-07 02:59:14', '2026-09-07 02:59:14', 0),
(4, 6, 'Sunset Homestay Test', 'sunset-homestay-test', 'premium', 'Không gian rộng rãi, đầy đủ tiện nghi và gần trung tâm.', '123 Đường Hồ Tây, Hà Nội', 'Hà Nội', 'Hồ Tây', 4, 150000, 2, 1, 1, 1, 0, 490000, 'approved', NULL, '2026-09-07 09:13:28', '2026-09-07 09:13:28', 0);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `homestay_images`
--

CREATE TABLE `homestay_images` (
  `id` int(10) UNSIGNED NOT NULL,
  `homestay_id` int(10) UNSIGNED NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `is_cover` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `homestay_images`
--

INSERT INTO `homestay_images` (`id`, `homestay_id`, `image_path`, `is_cover`, `sort_order`, `created_at`) VALUES
(1, 1, 'uploads/homestays/1_8534ace5f0621619.jpg', 1, 0, '2026-08-21 17:40:40'),
(2, 1, 'uploads/homestays/1_33e68bf1ebc919f5.jpg', 0, 1, '2026-08-21 17:40:40'),
(3, 1, 'uploads/homestays/1_4e5c2dd0d0e85b83.jpg', 0, 2, '2026-08-21 17:40:40'),
(4, 2, 'uploads/homestays/2_d3d49a2c5ff7cdee.jpg', 0, 0, '2026-09-07 02:54:37'),
(5, 2, 'uploads/homestays/2_ba92b6864ba0f3f9.jpg', 0, 1, '2026-09-07 02:54:37'),
(6, 2, 'uploads/homestays/2_f2af61bc0331b19c.jpg', 0, 2, '2026-09-07 02:54:37'),
(7, 2, 'uploads/homestays/2_4103da50728dc09b.jpg', 0, 3, '2026-09-07 02:54:37'),
(8, 2, 'uploads/homestays/2_70d7915a5ce926ec.jpg', 0, 4, '2026-09-07 02:54:37'),
(9, 2, 'uploads/homestays/2_bd3649145307e4b5.jpg', 1, 5, '2026-09-07 02:54:37'),
(10, 3, 'uploads/homestays/3_3b1e09bb420610fa.jpg', 0, 0, '2026-09-07 02:59:14'),
(11, 3, 'uploads/homestays/3_b5efadb62b95f2ab.jpg', 0, 1, '2026-09-07 02:59:14'),
(12, 3, 'uploads/homestays/3_9a5765e22e4e3124.jpg', 0, 2, '2026-09-07 02:59:14'),
(13, 3, 'uploads/homestays/3_10d857bb3a93d631.jpg', 1, 3, '2026-09-07 02:59:14'),
(14, 3, 'uploads/homestays/3_5c0d7ee0bccd4791.jpg', 0, 4, '2026-09-07 02:59:14'),
(15, 4, '/uploads/homestays/4/51fc4dfda69b45eea3d5bd2682d09f35.jpg', 1, 1, '2026-09-07 12:18:27');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `homestay_prices`
--

CREATE TABLE `homestay_prices` (
  `id` int(10) UNSIGNED NOT NULL,
  `homestay_id` int(10) UNSIGNED NOT NULL,
  `price_first_2_hours` decimal(12,0) NOT NULL,
  `price_combo_4_hours` decimal(12,0) NOT NULL,
  `price_extra_hour` decimal(12,0) NOT NULL,
  `price_overnight_weekday` decimal(12,0) NOT NULL,
  `price_overnight_weekend` decimal(12,0) NOT NULL,
  `price_day_night_weekday` decimal(12,0) NOT NULL,
  `price_day_night_weekend` decimal(12,0) NOT NULL,
  `price_day_weekday` decimal(12,0) NOT NULL,
  `price_day_weekend` decimal(12,0) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `homestay_prices`
--

INSERT INTO `homestay_prices` (`id`, `homestay_id`, `price_first_2_hours`, `price_combo_4_hours`, `price_extra_hour`, `price_overnight_weekday`, `price_overnight_weekend`, `price_day_night_weekday`, `price_day_night_weekend`, `price_day_weekday`, `price_day_weekend`, `created_at`, `updated_at`) VALUES
(1, 1, 150000, 210000, 40000, 300000, 330000, 450000, 490000, 380000, 420000, '2026-08-21 17:40:40', '2026-08-21 17:40:40'),
(11, 2, 150000, 210000, 50000, 350000, 380000, 500000, 550000, 400000, 430000, '2026-09-07 02:54:37', '2026-09-07 02:54:37'),
(13, 3, 190000, 250000, 50000, 350000, 380000, 490000, 520000, 410000, 450000, '2026-09-07 02:59:14', '2026-09-07 02:59:14'),
(14, 4, 150000, 250000, 70000, 490000, 590000, 750000, 850000, 400000, 450000, '2026-09-07 09:25:10', '2026-09-07 09:25:11');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `owner_profiles`
--

CREATE TABLE `owner_profiles` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `citizen_id` varchar(20) NOT NULL,
  `address` varchar(255) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `bank_account` varchar(50) NOT NULL,
  `bank_account_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `owner_profiles`
--

INSERT INTO `owner_profiles` (`id`, `user_id`, `citizen_id`, `address`, `bank_name`, `bank_account`, `bank_account_name`, `created_at`) VALUES
(1, 4, '001234567291', 'Xuân Đỉnh, Bắc Từ Liêm, Hà Nội', 'Techcombank', '09123456789', 'Minh Nhật', '2026-08-21 16:44:54'),
(2, 6, '001205000006', '456 Nguyễn Trãi, Thanh Xuân, Hà Nội', 'Vietcombank', '1234567890', 'NGUYEN MINH TEST', '2026-09-07 09:04:39');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `payments`
--

CREATE TABLE `payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `transaction_code` varchar(100) DEFAULT NULL,
  `payment_method` enum('bank_transfer','momo','vnpay') NOT NULL,
  `amount` decimal(12,0) NOT NULL,
  `status` enum('pending','held','refunded','settled','failed') NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `payments`
--

INSERT INTO `payments` (`id`, `booking_id`, `transaction_code`, `payment_method`, `amount`, `status`, `paid_at`, `created_at`) VALUES
(1, 1, 'PAY260824113141B59D44', 'bank_transfer', 150000, 'refunded', '2026-08-24 11:31:41', '2026-08-24 04:31:41'),
(2, 2, 'PAY260824113208CB8794', 'momo', 150000, 'settled', '2026-08-24 11:32:08', '2026-08-24 04:32:08'),
(3, 3, 'PAY260824113442A3443F', 'vnpay', 150000, 'settled', '2026-08-24 11:34:42', '2026-08-24 04:34:42'),
(4, 5, 'PAY260907230557BFAF19', 'momo', 150000, 'settled', '2026-09-07 23:05:57', '2026-09-07 16:05:57'),
(5, 6, 'PAY26090723242504CB80', 'vnpay', 150000, 'refunded', '2026-09-07 23:24:25', '2026-09-07 16:24:25'),
(6, 7, 'PAY260911073620E57CEC', 'momo', 350000, 'held', '2026-09-11 07:36:20', '2026-09-11 00:36:20');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `profile_change_requests`
--

CREATE TABLE `profile_change_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `reason` text NOT NULL,
  `requested_information` text NOT NULL,
  `status` enum('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `processed_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `profile_change_requests`
--

INSERT INTO `profile_change_requests` (`id`, `owner_id`, `reason`, `requested_information`, `status`, `admin_note`, `processed_by`, `created_at`, `processed_at`) VALUES
(1, 4, 'abvđâsdadas', 'acxđâsdasdsa', 'completed', '', 3, '2026-08-21 16:51:19', '2026-08-21 23:59:42'),
(2, 4, 'đổi tên', 'họ và tên', 'completed', '', 3, '2026-08-24 16:56:43', '2026-08-24 23:57:08'),
(3, 6, 'Tôi đã chuyển sang địa chỉ mới.', 'Đổi địa chỉ thành: 456 Nguyễn Trãi, Thanh Xuân, Hà Nội.', 'completed', 'QTV đã cập nhật địa chỉ mới.', 3, '2026-09-07 16:36:40', '2026-09-07 23:40:31');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `refund_requests`
--

CREATE TABLE `refund_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `requested_by` int(10) UNSIGNED NOT NULL,
  `reason` enum('guest_cancelled','host_cancelled','power_outage','service_issue','other') NOT NULL,
  `description` text DEFAULT NULL,
  `refund_amount` decimal(12,0) NOT NULL,
  `status` enum('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `resolved_by` int(10) UNSIGNED DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `refund_requests`
--

INSERT INTO `refund_requests` (`id`, `booking_id`, `requested_by`, `reason`, `description`, `refund_amount`, `status`, `admin_note`, `resolved_by`, `resolved_at`, `created_at`) VALUES
(1, 1, 2, 'power_outage', 'mất điện', 150000, 'completed', 'QTV đã duyệt và hoàn tiền cho khách.', 3, '2026-08-25 01:05:49', '2026-08-24 17:09:26'),
(2, 6, 5, 'service_issue', 'Phòng không đúng với thông tin đã đăng.', 150000, 'completed', 'Đã hoàn 150.000 đồng về phương thức thanh toán của khách.', 3, '2026-09-07 23:29:16', '2026-09-07 16:24:25');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `settlements`
--

CREATE TABLE `settlements` (
  `id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `gross_amount` decimal(12,0) NOT NULL,
  `platform_fee` decimal(12,0) NOT NULL,
  `owner_amount` decimal(12,0) NOT NULL,
  `status` enum('pending','held','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `settled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `settlements`
--

INSERT INTO `settlements` (`id`, `booking_id`, `owner_id`, `gross_amount`, `platform_fee`, `owner_amount`, `status`, `settled_at`, `created_at`) VALUES
(1, 2, 4, 150000, 15000, 135000, 'completed', '2026-08-25 00:08:39', '2026-08-24 17:08:39'),
(2, 3, 4, 150000, 15000, 135000, 'completed', '2026-08-25 00:08:49', '2026-08-24 17:08:49'),
(3, 5, 6, 150000, 15000, 135000, 'completed', '2026-09-07 23:06:42', '2026-09-07 16:06:42');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','owner','guest') NOT NULL DEFAULT 'guest',
  `status` enum('pending','approved','rejected','blocked') NOT NULL DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `phone`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Nguyễn Văn A', 'tet@gmail.com', '0123456789', '$2y$12$1.VN2n7NKZY9Tgad61QxK.opN7gST.FaJM1eklgDJX5i7iEe0OUGe', 'guest', 'approved', '2026-08-21 13:35:39', '2026-09-07 08:18:42'),
(2, 'Nguyễn Văn A', 'vana@gmail.com', '0987654321', '$2y$10$/7lVcoJo.TFoRukfkKvhMOIgpLADeLSGsauGfXv79jPmXatb4szu2', 'guest', 'approved', '2026-08-21 13:50:33', '2026-08-21 13:50:33'),
(3, 'QTV Đi Đâu Đây', 'admin@didauday.vn', '0900000000', '$2y$12$1.VN2n7NKZY9Tgad61QxK.opN7gST.FaJM1eklgDJX5i7iEe0OUGe', 'admin', 'approved', '2026-08-21 14:21:49', '2026-09-07 08:33:52'),
(4, 'Nguyễn Hoàng Nam', 'owner1@gmail.com', '0923456789', '$2y$10$ABGNmskw5J4Q7jhY/uVzI.MiuvRV7oRVRb.UCsquNN2pUapZDNFkK', 'owner', 'approved', '2026-08-21 15:44:33', '2026-08-21 15:45:52'),
(5, 'Nguy?n Minh Test', 'guesttest@gmail.com', '0912345678', '$2a$11$NE6SfUzp5bYUJnqJIQF34.pskyTMpv8WgAq6OAJM1DJff2AgpGMqO', 'guest', 'approved', '2026-09-07 01:26:47', '2026-09-07 08:26:47'),
(6, 'Ch? Homestay Test', 'ownertest@gmail.com', '0934567890', '$2a$11$SUYlgcgnqWfe7KZI0.UEiOo2TOgXyIqi58.Ibfb8O4fNRq3v3iu.i', 'owner', 'approved', '2026-09-07 01:30:04', '2026-09-07 08:37:14');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `wallets`
--

CREATE TABLE `wallets` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `pending_balance` decimal(12,0) NOT NULL DEFAULT 0,
  `available_balance` decimal(12,0) NOT NULL DEFAULT 0,
  `total_earned` decimal(12,0) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `wallets`
--

INSERT INTO `wallets` (`id`, `user_id`, `pending_balance`, `available_balance`, `total_earned`, `updated_at`) VALUES
(1, 3, 0, 45000, 45000, '2026-09-07 16:06:42'),
(2, 4, 0, 270000, 270000, '2026-08-24 17:08:49'),
(76, 6, 0, 35000, 135000, '2026-09-07 16:15:20');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `wallet_transactions`
--

CREATE TABLE `wallet_transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `wallet_id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED DEFAULT NULL,
  `transaction_type` enum('platform_fee','owner_income','withdrawal','refund','adjustment') NOT NULL,
  `direction` enum('credit','debit') NOT NULL,
  `amount` decimal(12,0) NOT NULL,
  `balance_after` decimal(12,0) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('pending','completed','failed') NOT NULL DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `wallet_transactions`
--

INSERT INTO `wallet_transactions` (`id`, `wallet_id`, `booking_id`, `transaction_type`, `direction`, `amount`, `balance_after`, `description`, `status`, `created_at`) VALUES
(1, 1, 2, 'platform_fee', 'credit', 15000, 15000, 'Phí nền tảng của đơn DDD260824113205E56D76', 'completed', '2026-08-24 17:08:39'),
(2, 2, 2, 'owner_income', 'credit', 135000, 135000, 'Thu nhập từ đơn DDD260824113205E56D76', 'completed', '2026-08-24 17:08:39'),
(3, 1, 3, 'platform_fee', 'credit', 15000, 30000, 'Phí nền tảng của đơn DDD260824113440547F2D', 'completed', '2026-08-24 17:08:49'),
(4, 2, 3, 'owner_income', 'credit', 135000, 270000, 'Thu nhập từ đơn DDD260824113440547F2D', 'completed', '2026-08-24 17:08:49'),
(5, 76, 5, 'owner_income', 'credit', 135000, 135000, 'Thu nhập từ đơn DDD2609072015120C2206', 'completed', '2026-09-07 16:06:42'),
(6, 1, 5, 'platform_fee', 'credit', 15000, 45000, 'Phí nền tảng của đơn DDD2609072015120C2206', 'completed', '2026-09-07 16:06:42'),
(7, 76, NULL, 'withdrawal', 'debit', 100000, 35000, 'Yêu cầu rút tiền #1', 'completed', '2026-09-07 16:15:20');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `withdrawal_requests`
--

CREATE TABLE `withdrawal_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `wallet_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(12,0) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `bank_account` varchar(50) NOT NULL,
  `bank_account_name` varchar(100) NOT NULL,
  `status` enum('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  `admin_note` varchar(255) DEFAULT NULL,
  `processed_by` int(10) UNSIGNED DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `withdrawal_requests`
--

INSERT INTO `withdrawal_requests` (`id`, `wallet_id`, `amount`, `bank_name`, `bank_account`, `bank_account_name`, `status`, `admin_note`, `processed_by`, `requested_at`, `processed_at`) VALUES
(1, 76, 100000, 'Vietcombank', '1234567890', 'NGUYEN MINH TEST', 'completed', 'Đã chuyển khoản 100.000 đồng cho chủ homestay.', 3, '2026-09-07 16:15:20', '2026-09-07 23:19:57');

--
-- Chỉ mục cho các bảng đã đổ
--

--
-- Chỉ mục cho bảng `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `booking_code` (`booking_code`),
  ADD KEY `fk_booking_guest` (`guest_id`),
  ADD KEY `fk_booking_homestay` (`homestay_id`);

--
-- Chỉ mục cho bảng `homestays`
--
ALTER TABLE `homestays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `fk_homestay_owner` (`owner_id`);

--
-- Chỉ mục cho bảng `homestay_images`
--
ALTER TABLE `homestay_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_image_homestay` (`homestay_id`);

--
-- Chỉ mục cho bảng `homestay_prices`
--
ALTER TABLE `homestay_prices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `homestay_id` (`homestay_id`);

--
-- Chỉ mục cho bảng `owner_profiles`
--
ALTER TABLE `owner_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `citizen_id` (`citizen_id`);

--
-- Chỉ mục cho bảng `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `booking_id` (`booking_id`);

--
-- Chỉ mục cho bảng `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_profile_request_owner` (`owner_id`),
  ADD KEY `fk_profile_request_admin` (`processed_by`);

--
-- Chỉ mục cho bảng `refund_requests`
--
ALTER TABLE `refund_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_refund_booking` (`booking_id`),
  ADD KEY `fk_refund_requester` (`requested_by`),
  ADD KEY `fk_refund_admin` (`resolved_by`);

--
-- Chỉ mục cho bảng `settlements`
--
ALTER TABLE `settlements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `booking_id` (`booking_id`),
  ADD KEY `fk_settlement_owner` (`owner_id`);

--
-- Chỉ mục cho bảng `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone` (`phone`);

--
-- Chỉ mục cho bảng `wallets`
--
ALTER TABLE `wallets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Chỉ mục cho bảng `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_transaction_wallet` (`wallet_id`),
  ADD KEY `fk_transaction_booking` (`booking_id`);

--
-- Chỉ mục cho bảng `withdrawal_requests`
--
ALTER TABLE `withdrawal_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_withdrawal_wallet` (`wallet_id`),
  ADD KEY `fk_withdrawal_admin` (`processed_by`);

--
-- AUTO_INCREMENT cho các bảng đã đổ
--

--
-- AUTO_INCREMENT cho bảng `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT cho bảng `homestays`
--
ALTER TABLE `homestays`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT cho bảng `homestay_images`
--
ALTER TABLE `homestay_images`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT cho bảng `homestay_prices`
--
ALTER TABLE `homestay_prices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT cho bảng `owner_profiles`
--
ALTER TABLE `owner_profiles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT cho bảng `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT cho bảng `refund_requests`
--
ALTER TABLE `refund_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `settlements`
--
ALTER TABLE `settlements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT cho bảng `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT cho bảng `wallets`
--
ALTER TABLE `wallets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT cho bảng `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT cho bảng `withdrawal_requests`
--
ALTER TABLE `withdrawal_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Các ràng buộc cho các bảng đã đổ
--

--
-- Các ràng buộc cho bảng `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_booking_guest` FOREIGN KEY (`guest_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_booking_homestay` FOREIGN KEY (`homestay_id`) REFERENCES `homestays` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `homestays`
--
ALTER TABLE `homestays`
  ADD CONSTRAINT `fk_homestay_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `homestay_images`
--
ALTER TABLE `homestay_images`
  ADD CONSTRAINT `fk_image_homestay` FOREIGN KEY (`homestay_id`) REFERENCES `homestays` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `homestay_prices`
--
ALTER TABLE `homestay_prices`
  ADD CONSTRAINT `fk_price_homestay` FOREIGN KEY (`homestay_id`) REFERENCES `homestays` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `owner_profiles`
--
ALTER TABLE `owner_profiles`
  ADD CONSTRAINT `fk_owner_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payment_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  ADD CONSTRAINT `fk_profile_request_admin` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_profile_request_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `refund_requests`
--
ALTER TABLE `refund_requests`
  ADD CONSTRAINT `fk_refund_admin` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_refund_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_refund_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`);

--
-- Các ràng buộc cho bảng `settlements`
--
ALTER TABLE `settlements`
  ADD CONSTRAINT `fk_settlement_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_settlement_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`);

--
-- Các ràng buộc cho bảng `wallets`
--
ALTER TABLE `wallets`
  ADD CONSTRAINT `fk_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD CONSTRAINT `fk_transaction_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transaction_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `withdrawal_requests`
--
ALTER TABLE `withdrawal_requests`
  ADD CONSTRAINT `fk_withdrawal_admin` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_withdrawal_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
