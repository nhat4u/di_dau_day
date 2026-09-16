using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/payments")]
[Authorize(Roles = "guest")]
public sealed class PaymentsController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public PaymentsController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // Khách thanh toán đơn đặt phòng
    [HttpPost]
    public async Task<IActionResult> CreatePayment(
        [FromBody] CreatePaymentRequest request
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

        var paymentMethod = request.PaymentMethod
            .Trim()
            .ToLowerInvariant();

        if (
            paymentMethod != "bank_transfer" &&
            paymentMethod != "momo" &&
            paymentMethod != "vnpay"
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Phương thức thanh toán phải là bank_transfer, momo hoặc vnpay."
            });
        }

        var booking = await _db.Bookings
            .Include(b => b.Payment)
            .Include(b => b.Homestay)
            .FirstOrDefaultAsync(b =>
                b.Id == request.BookingId &&
                b.GuestId == guestId
            );

        if (booking is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy đơn đặt phòng của bạn."
            });
        }

        if (booking.Status == "cancelled")
        {
            return BadRequest(new
            {
                success = false,
                message = "Đơn đã bị hủy nên không thể thanh toán."
            });
        }

        if (booking.Status != "pending_payment")
        {
            return Conflict(new
            {
                success = false,
                message = "Đơn này không còn ở trạng thái chờ thanh toán.",
                currentStatus = booking.Status
            });
        }

        if (booking.Payment is not null)
        {
            return Conflict(new
            {
                success = false,
                message = "Đơn này đã có giao dịch thanh toán.",
                paymentStatus = booking.Payment.Status
            });
        }

        var now = DateTime.Now;

        var payment = new Payment
        {
            BookingId = booking.Id,
            TransactionCode = GenerateTransactionCode(),
            PaymentMethod = paymentMethod,
            Amount = booking.TotalAmount,
            Status = "held",
            PaidAt = now,
            CreatedAt = now,
            Booking = booking
        };

        // Đơn được xác nhận nhưng tiền vẫn đang do hệ thống giữ
        booking.Status = "confirmed";
        booking.UpdatedAt = now;

        _db.Payments.Add(payment);
        await _db.SaveChangesAsync();

        return StatusCode(201, new
        {
            success = true,
            message =
                "Thanh toán thành công. Tiền đang được hệ thống tạm giữ.",
            payment = new
            {
                payment.Id,
                payment.BookingId,
                booking.BookingCode,
                homestayName = booking.Homestay.Name,
                payment.TransactionCode,
                payment.PaymentMethod,
                payment.Amount,
                payment.Status,
                payment.PaidAt,
                payment.CreatedAt
            },
            bookingStatus = booking.Status
        });
    }

    // Xem thanh toán của một booking
    [HttpGet("booking/{bookingId}")]
    public async Task<IActionResult> GetPaymentByBooking(
        uint bookingId
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

        var payment = await _db.Payments
            .AsNoTracking()
            .Where(p =>
                p.BookingId == bookingId &&
                p.Booking.GuestId == guestId
            )
            .Select(p => new
            {
                p.Id,
                p.BookingId,
                p.Booking.BookingCode,
                homestayName = p.Booking.Homestay.Name,
                p.TransactionCode,
                p.PaymentMethod,
                p.Amount,
                p.Status,
                p.PaidAt,
                p.CreatedAt,
                bookingStatus = p.Booking.Status
            })
            .FirstOrDefaultAsync();

        if (payment is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy giao dịch thanh toán."
            });
        }

        return Ok(new
        {
            success = true,
            payment
        });
    }

    // Xem tất cả giao dịch của khách đang đăng nhập
    [HttpGet("my")]
    public async Task<IActionResult> GetMyPayments()
    {
        if (!TryGetCurrentUserId(out var guestId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var payments = await _db.Payments
            .AsNoTracking()
            .Where(p => p.Booking.GuestId == guestId)
            .OrderByDescending(p => p.CreatedAt)
            .Select(p => new
            {
                p.Id,
                p.BookingId,
                p.Booking.BookingCode,
                homestayName = p.Booking.Homestay.Name,
                p.TransactionCode,
                p.PaymentMethod,
                p.Amount,
                p.Status,
                p.PaidAt,
                p.CreatedAt,
                bookingStatus = p.Booking.Status
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = payments.Count,
            payments
        });
    }

    private static string GenerateTransactionCode()
    {
        var randomCode = Guid.NewGuid()
            .ToString("N")[..6]
            .ToUpperInvariant();

        return $"PAY{DateTime.Now:yyMMddHHmmss}{randomCode}";
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }
}

public sealed class CreatePaymentRequest
{
    public uint BookingId { get; set; }

    [Required(ErrorMessage = "Vui lòng chọn phương thức thanh toán.")]
    public string PaymentMethod { get; set; } = string.Empty;
}