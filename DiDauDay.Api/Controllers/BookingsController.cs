using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/bookings")]
public sealed class BookingsController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public BookingsController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // Kiểm tra khoảng thời gian còn trống hay không
    [AllowAnonymous]
    [HttpGet("availability")]
    public async Task<IActionResult> CheckAvailability(
        [FromQuery] uint homestayId,
        [FromQuery] DateTime? checkIn,
        [FromQuery] DateTime? checkOut
    )
    {
        if (
            homestayId == 0 ||
            checkIn is null ||
            checkOut is null
        )
        {
            return BadRequest(new
            {
                success = false,
                message = "Vui lòng nhập homestayId, checkIn và checkOut."
            });
        }

        if (checkOut <= checkIn)
        {
            return BadRequest(new
            {
                success = false,
                message = "Thời gian trả phòng phải sau thời gian nhận phòng."
            });
        }

        if (
            !IsFullHour(checkIn.Value) ||
            !IsFullHour(checkOut.Value)
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Thời gian nhận và trả phòng phải là giờ tròn, ví dụ 11:00 - 13:00."
            });
        }

        var homestayExists = await _db.Homestays
            .AsNoTracking()
            .AnyAsync(h =>
                h.Id == homestayId &&
                h.Status == "approved" &&
                !h.IsDeleted
            );

        if (!homestayExists)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy homestay đang hoạt động."
            });
        }

        var hasConflict = await _db.Bookings
            .AsNoTracking()
            .AnyAsync(b =>
                b.HomestayId == homestayId &&
                b.Status != "cancelled" &&
                b.Status != "refunded" &&
                b.CheckIn < checkOut.Value &&
                b.CheckOut > checkIn.Value
            );

        return Ok(new
        {
            success = true,
            available = !hasConflict,
            message = hasConflict
                ? "Khoảng thời gian này đã có người đặt."
                : "Khoảng thời gian này còn trống."
        });
    }

    // Khách tạo đơn đặt phòng
    [Authorize(Roles = "guest")]
    [HttpPost]
    public async Task<IActionResult> CreateBooking(
        [FromBody] CreateBookingRequest request
    )
    {
        if (!TryGetCurrentUserId(out var guestId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var bookingType = request.BookingType
            .Trim()
            .ToLowerInvariant();

        if (
            bookingType != "hourly" &&
            bookingType != "overnight" &&
            bookingType != "daytime" &&
            bookingType != "day_night"
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Loại đặt phòng phải là hourly, overnight, daytime hoặc day_night."
            });
        }

        if (request.CheckIn <= DateTime.Now)
        {
            return BadRequest(new
            {
                success = false,
                message = "Thời gian nhận phòng phải ở tương lai."
            });
        }

        if (request.CheckOut <= request.CheckIn)
        {
            return BadRequest(new
            {
                success = false,
                message = "Thời gian trả phòng phải sau thời gian nhận phòng."
            });
        }

        if (
            bookingType == "hourly" &&
            (
                !IsFullHour(request.CheckIn) ||
                !IsFullHour(request.CheckOut)
            )
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Thuê theo giờ chỉ được chọn giờ tròn, ví dụ 11:00 - 13:00."
            });
        }

        var homestay = await _db.Homestays
            .Include(h => h.HomestayPrice)
            .FirstOrDefaultAsync(h =>
                h.Id == request.HomestayId &&
                h.Status == "approved" &&
                !h.IsDeleted
            );

        if (homestay is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy homestay đang hoạt động."
            });
        }

        if (request.GuestCount > homestay.MaxGuests)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    $"Homestay này chỉ cho phép tối đa {homestay.MaxGuests} khách."
            });
        }

        if (homestay.HomestayPrice is null)
        {
            return BadRequest(new
            {
                success = false,
                message = "Homestay chưa thiết lập bảng giá."
            });
        }

        if (!TryCalculateTotal(
                homestay,
                homestay.HomestayPrice,
                bookingType,
                request.CheckIn,
                request.CheckOut,
                out var totalAmount,
                out var priceError
            ))
        {
            return BadRequest(new
            {
                success = false,
                message = priceError
            });
        }

        var hasConflict = await _db.Bookings.AnyAsync(b =>
            b.HomestayId == request.HomestayId &&
            b.Status != "cancelled" &&
            b.Status != "refunded" &&
            b.CheckIn < request.CheckOut &&
            b.CheckOut > request.CheckIn
        );

        if (hasConflict)
        {
            return Conflict(new
            {
                success = false,
                message =
                    "Khoảng thời gian này vừa có người đặt. Vui lòng chọn thời gian khác."
            });
        }

        var now = DateTime.Now;

        var booking = new Booking
        {
            BookingCode = GenerateBookingCode(),
            GuestId = guestId,
            HomestayId = homestay.Id,
            BookingType = bookingType,
            CheckIn = request.CheckIn,
            CheckOut = request.CheckOut,
            GuestCount = request.GuestCount,
            TotalAmount = totalAmount,
            Status = "pending_payment",
            CreatedAt = now,
            UpdatedAt = now
        };

        _db.Bookings.Add(booking);
        await _db.SaveChangesAsync();

        return StatusCode(201, new
        {
            success = true,
            message = "Tạo đơn đặt phòng thành công.",
            booking = new
            {
                booking.Id,
                booking.BookingCode,
                booking.GuestId,
                booking.HomestayId,
                homestayName = homestay.Name,
                booking.BookingType,
                booking.CheckIn,
                booking.CheckOut,
                booking.GuestCount,
                booking.TotalAmount,
                booking.Status,
                booking.CreatedAt
            }
        });
    }

    // Khách xem tất cả đơn của mình
    [Authorize(Roles = "guest")]
    [HttpGet("my")]
    public async Task<IActionResult> GetMyBookings()
    {
        if (!TryGetCurrentUserId(out var guestId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var bookings = await _db.Bookings
            .AsNoTracking()
            .Where(b => b.GuestId == guestId)
            .OrderByDescending(b => b.CreatedAt)
            .Select(b => new
            {
                b.Id,
                b.BookingCode,
                b.BookingType,
                b.CheckIn,
                b.CheckOut,
                b.GuestCount,
                b.TotalAmount,
                b.Status,
                b.CreatedAt,
                homestay = new
                {
                    b.Homestay.Id,
                    b.Homestay.Name,
                    b.Homestay.Slug,
                    b.Homestay.Address
                }
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = bookings.Count,
            bookings
        });
    }

    // Khách xem chi tiết một đơn của mình
    [Authorize(Roles = "guest")]
    [HttpGet("{id}")]
    public async Task<IActionResult> GetMyBooking(uint id)
    {
        if (!TryGetCurrentUserId(out var guestId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var booking = await _db.Bookings
            .AsNoTracking()
            .Where(b =>
                b.Id == id &&
                b.GuestId == guestId
            )
            .Select(b => new
            {
                b.Id,
                b.BookingCode,
                b.BookingType,
                b.CheckIn,
                b.CheckOut,
                b.GuestCount,
                b.TotalAmount,
                b.Status,
                b.CreatedAt,
                b.UpdatedAt,
                homestay = new
                {
                    b.Homestay.Id,
                    b.Homestay.Name,
                    b.Homestay.Slug,
                    b.Homestay.Address,
                    b.Homestay.Province,
                    b.Homestay.TouristDestination
                }
            })
            .FirstOrDefaultAsync();

        if (booking is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy đơn đặt phòng."
            });
        }

        return Ok(new
        {
            success = true,
            booking
        });
    }

    // Chỉ hủy được đơn chưa thanh toán
    [Authorize(Roles = "guest")]
    [HttpPatch("{id}/cancel")]
    public async Task<IActionResult> CancelBooking(uint id)
    {
        if (!TryGetCurrentUserId(out var guestId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var booking = await _db.Bookings
            .FirstOrDefaultAsync(b =>
                b.Id == id &&
                b.GuestId == guestId
            );

        if (booking is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy đơn đặt phòng."
            });
        }

        if (booking.Status != "pending_payment")
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Chỉ có thể hủy trực tiếp đơn đang chờ thanh toán."
            });
        }

        booking.Status = "cancelled";
        booking.UpdatedAt = DateTime.Now;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã hủy đơn đặt phòng.",
            bookingId = booking.Id,
            bookingCode = booking.BookingCode,
            status = booking.Status
        });
    }

    private static bool TryCalculateTotal(
        Homestay homestay,
        HomestayPrice prices,
        string bookingType,
        DateTime checkIn,
        DateTime checkOut,
        out decimal total,
        out string error
    )
    {
        total = 0;
        error = string.Empty;

        if (bookingType == "hourly")
        {
            if (!IsFullHour(checkIn) || !IsFullHour(checkOut))
            {
                error =
                    "Thuê theo giờ chỉ được chọn giờ tròn, ví dụ 11:00 - 13:00.";
                return false;
            }

            var duration = checkOut - checkIn;
            var minimumHours = Math.Max(
                2,
                (int)homestay.MinimumHours
            );

            if (duration.Ticks % TimeSpan.TicksPerHour != 0)
            {
                error = "Đặt theo giờ phải chọn tròn số giờ.";
                return false;
            }

            var hours = (int)duration.TotalHours;

            if (hours < minimumHours)
            {
                error =
                    $"Phải đặt tối thiểu {minimumHours} giờ.";
                return false;
            }

            if (hours > 24)
            {
                error = "Đặt theo giờ không được vượt quá 24 giờ.";
                return false;
            }

            if (hours == 2)
            {
                total = prices.PriceFirst2Hours;
            }
            else if (hours == 3)
            {
                total =
                    prices.PriceFirst2Hours +
                    prices.PriceExtraHour;
            }
            else
            {
                total =
                    prices.PriceCombo4Hours +
                    ((hours - 4) * prices.PriceExtraHour);
            }

            return true;
        }

        var isWeekend = IsWeekend(checkIn);

        if (bookingType == "overnight")
        {
            var expectedCheckOut = checkIn.Date
                .AddDays(1)
                .AddHours(10);

            if (
                checkIn.TimeOfDay != TimeSpan.FromHours(22) ||
                checkOut != expectedCheckOut
            )
            {
                error =
                    "Gói qua đêm phải nhận lúc 22:00 và trả lúc 10:00 sáng hôm sau.";
                return false;
            }

            total = isWeekend
                ? prices.PriceOvernightWeekend
                : prices.PriceOvernightWeekday;

            return true;
        }

        if (bookingType == "day_night")
        {
            var expectedCheckOut = checkIn.Date
                .AddDays(1)
                .AddHours(10);

            if (
                checkIn.TimeOfDay != TimeSpan.FromHours(15) ||
                checkOut != expectedCheckOut
            )
            {
                error =
                    "Gói ngày đêm phải nhận lúc 15:00 và trả lúc 10:00 sáng hôm sau.";
                return false;
            }

            total = isWeekend
                ? prices.PriceDayNightWeekend
                : prices.PriceDayNightWeekday;

            return true;
        }

        if (bookingType == "daytime")
        {
            var expectedCheckOut = checkIn.Date.AddHours(21);

            if (
                checkIn.TimeOfDay != TimeSpan.FromHours(11) ||
                checkOut != expectedCheckOut
            )
            {
                error =
                    "Gói ban ngày phải nhận lúc 11:00 và trả lúc 21:00 cùng ngày.";
                return false;
            }

            total = isWeekend
                ? prices.PriceDayWeekend
                : prices.PriceDayWeekday;

            return true;
        }

        error = "Loại đặt phòng không hợp lệ.";
        return false;
    }

    private static bool IsFullHour(DateTime value)
    {
        return value.Minute == 0 &&
               value.Second == 0 &&
               value.Millisecond == 0;
    }

    // Bảng giá quy định Thứ 6 đến Chủ nhật là cuối tuần
    private static bool IsWeekend(DateTime value)
    {
        return value.DayOfWeek == DayOfWeek.Friday ||
               value.DayOfWeek == DayOfWeek.Saturday ||
               value.DayOfWeek == DayOfWeek.Sunday;
    }

    private static string GenerateBookingCode()
    {
        var randomCode = Guid.NewGuid()
            .ToString("N")[..6]
            .ToUpperInvariant();

        return $"DDD{DateTime.Now:yyMMddHHmmss}{randomCode}";
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }
}

public sealed class CreateBookingRequest
{
    public uint HomestayId { get; set; }

    [Required(ErrorMessage = "Vui lòng chọn loại đặt phòng.")]
    public string BookingType { get; set; } = string.Empty;

    public DateTime CheckIn { get; set; }

    public DateTime CheckOut { get; set; }

    [Range(
        1,
        4,
        ErrorMessage = "Số khách phải từ 1 đến 4."
    )]
    public byte GuestCount { get; set; }
}
