using System.ComponentModel.DataAnnotations;
using System.Data;
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
            !IsHalfHourSlot(checkIn.Value) ||
            !IsHalfHourSlot(checkOut.Value)
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Thời gian nhận và trả phòng chỉ được chọn phút 00 hoặc 30."
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

        var now = DateTime.Now;

        var hasConflict = await _db.Bookings
            .AsNoTracking()
            .AnyAsync(b =>
                b.HomestayId == homestayId &&
                (
                    b.Status == "confirmed" ||
                    b.Status == "funds_held" ||
                    b.Status == "completed" ||
                    b.Status == "disputed" ||
                    (
                        b.Status == "pending_payment" &&
                        (
                            b.ExpiresAt == null ||
                            b.ExpiresAt > now
                        )
                    )
                ) &&
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
                !IsHalfHourSlot(request.CheckIn) ||
                !IsHalfHourSlot(request.CheckOut)
            )
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Thuê theo giờ chỉ được chọn phút 00 hoặc 30."
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

        var now = DateTime.Now;

        await using var transaction =
            await _db.Database.BeginTransactionAsync(
                IsolationLevel.Serializable
            );

        var hasConflict = await _db.Bookings.AnyAsync(b =>
            b.HomestayId == request.HomestayId &&
            (
                b.Status == "confirmed" ||
                b.Status == "funds_held" ||
                b.Status == "completed" ||
                b.Status == "disputed" ||
                (
                    b.Status == "pending_payment" &&
                    (
                        b.ExpiresAt == null ||
                        b.ExpiresAt > now
                    )
                )
            ) &&
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
            ExpiresAt = now.AddMinutes(5),
            CreatedAt = now,
            UpdatedAt = now
        };

        _db.Bookings.Add(booking);
        await _db.SaveChangesAsync();
        await transaction.CommitAsync();

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
                booking.ExpiresAt,
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

        await ExpirePendingBookingsAsync(guestId: guestId);

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
                b.ExpiresAt,
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

        await ExpirePendingBookingsAsync(
            guestId: guestId,
            bookingId: id
        );

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
                b.ExpiresAt,
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

        var now = DateTime.Now;

        if (
            booking.Status == "pending_payment" &&
            booking.ExpiresAt.HasValue &&
            booking.ExpiresAt.Value <= now
        )
        {
            booking.Status = "expired";
            booking.UpdatedAt = now;

            await _db.SaveChangesAsync();

            return StatusCode(StatusCodes.Status410Gone, new
            {
                success = false,
                message =
                    "Đơn đã hết thời gian giữ chỗ và không thể hủy nữa.",
                bookingId = booking.Id,
                status = booking.Status
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
        booking.ExpiresAt = null;
        booking.UpdatedAt = now;

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
            if (
                !IsHalfHourSlot(checkIn) ||
                !IsHalfHourSlot(checkOut)
            )
            {
                error =
                    "Thuê theo giờ chỉ được chọn phút 00 hoặc 30.";
                return false;
            }

            if (checkIn.Date != checkOut.Date)
            {
                error =
                    "Thuê theo giờ phải nhận và trả phòng trong cùng một ngày.";
                return false;
            }

            if (
                checkIn.TimeOfDay < TimeSpan.FromHours(11) ||
                checkOut.TimeOfDay > TimeSpan.FromHours(21)
            )
            {
                error =
                    "Thuê theo giờ chỉ áp dụng trong khung 11:00 đến 21:00.";
                return false;
            }

            var duration = checkOut - checkIn;
            var minimumHours = Math.Max(
                2,
                (int)homestay.MinimumHours
            );

            if (
                duration.Ticks %
                TimeSpan.FromMinutes(30).Ticks != 0
            )
            {
                error =
                    "Thời lượng thuê phải tăng theo từng 30 phút.";
                return false;
            }

            var hours = (decimal)duration.TotalMinutes / 60m;

            if (hours < minimumHours)
            {
                error =
                    $"Phải đặt tối thiểu {minimumHours} giờ.";
                return false;
            }

            if (hours == 2m)
            {
                total = prices.PriceFirst2Hours;
            }
            else if (hours < 4m)
            {
                total =
                    prices.PriceFirst2Hours +
                    ((hours - 2m) * prices.PriceExtraHour);
            }
            else
            {
                total =
                    prices.PriceCombo4Hours +
                    ((hours - 4m) * prices.PriceExtraHour);
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

    private static bool IsHalfHourSlot(DateTime value)
    {
        return (
                   value.Minute == 0 ||
                   value.Minute == 30
               ) &&
               value.Second == 0 &&
               value.Millisecond == 0;
    }

    private async Task ExpirePendingBookingsAsync(
        uint? guestId = null,
        uint? bookingId = null
    )
    {
        var now = DateTime.Now;

        IQueryable<Booking> query = _db.Bookings
            .Where(b =>
                b.Status == "pending_payment" &&
                b.ExpiresAt.HasValue &&
                b.ExpiresAt.Value <= now
            );

        if (guestId.HasValue)
        {
            query = query.Where(b =>
                b.GuestId == guestId.Value
            );
        }

        if (bookingId.HasValue)
        {
            query = query.Where(b =>
                b.Id == bookingId.Value
            );
        }

        var expiredBookings = await query.ToListAsync();

        if (expiredBookings.Count == 0)
        {
            return;
        }

        foreach (var booking in expiredBookings)
        {
            booking.Status = "expired";
            booking.UpdatedAt = now;
        }

        await _db.SaveChangesAsync();
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
