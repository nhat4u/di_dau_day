using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/refunds")]
[Authorize(Roles = "guest")]
public sealed class GuestRefundsController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public GuestRefundsController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // Khách gửi yêu cầu hoàn tiền
    [HttpPost]
    public async Task<IActionResult> CreateRefundRequest(
        [FromBody] CreateRefundRequest request
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

        var reason = request.Reason
            .Trim()
            .ToLowerInvariant();

        if (
            reason != "guest_cancelled" &&
            reason != "host_cancelled" &&
            reason != "power_outage" &&
            reason != "service_issue" &&
            reason != "other"
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Lý do phải là guest_cancelled, host_cancelled, power_outage, service_issue hoặc other."
            });
        }

        if (
            reason != "guest_cancelled" &&
            string.IsNullOrWhiteSpace(request.Description)
        )
        {
            return BadRequest(new
            {
                success = false,
                message = "Vui lòng mô tả chi tiết sự cố."
            });
        }

        var booking = await _db.Bookings
            .Include(b => b.Payment)
            .Include(b => b.RefundRequests)
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

        if (
            booking.Status != "confirmed" &&
            booking.Status != "funds_held"
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Chỉ có thể yêu cầu hoàn tiền cho đơn đã thanh toán và chưa quyết toán.",
                currentStatus = booking.Status
            });
        }

        if (
            booking.Payment is null ||
            booking.Payment.Status != "held"
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Khoản tiền của đơn không còn ở trạng thái tạm giữ."
            });
        }

        var hasActiveRequest = booking.RefundRequests.Any(r =>
            r.Status == "pending" ||
            r.Status == "approved"
        );

        if (hasActiveRequest)
        {
            return Conflict(new
            {
                success = false,
                message =
                    "Đơn này đã có một yêu cầu hoàn tiền đang được xử lý."
            });
        }

        var now = DateTime.Now;

        var refundRequest = new RefundRequest
        {
            BookingId = booking.Id,
            RequestedBy = guestId,
            Reason = reason,
            Description = string.IsNullOrWhiteSpace(
                request.Description
            )
                ? null
                : request.Description.Trim(),
            RefundAmount = booking.Payment.Amount,
            Status = "pending",
            AdminNote = null,
            ResolvedBy = null,
            ResolvedAt = null,
            CreatedAt = now,
            Booking = booking
        };

        // Khóa quyết toán trong lúc QTV xử lý khiếu nại
        booking.Status = "disputed";
        booking.UpdatedAt = now;

        _db.RefundRequests.Add(refundRequest);
        await _db.SaveChangesAsync();

        return StatusCode(201, new
        {
            success = true,
            message =
                "Đã gửi yêu cầu hoàn tiền. Vui lòng chờ QTV xử lý.",
            refundRequest = new
            {
                refundRequest.Id,
                refundRequest.BookingId,
                booking.BookingCode,
                refundRequest.Reason,
                refundRequest.Description,
                refundRequest.RefundAmount,
                refundRequest.Status,
                refundRequest.CreatedAt
            },
            bookingStatus = booking.Status
        });
    }

    // Khách xem các yêu cầu hoàn tiền của mình
    [HttpGet("my")]
    public async Task<IActionResult> GetMyRefundRequests()
    {
        if (!TryGetCurrentUserId(out var guestId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var refundRequests = await _db.RefundRequests
            .AsNoTracking()
            .Where(r => r.RequestedBy == guestId)
            .OrderByDescending(r => r.CreatedAt)
            .Select(r => new
            {
                r.Id,
                r.BookingId,
                r.Booking.BookingCode,
                homestayName = r.Booking.Homestay.Name,
                r.Reason,
                r.Description,
                r.RefundAmount,
                r.Status,
                r.AdminNote,
                r.ResolvedAt,
                r.CreatedAt,
                bookingStatus = r.Booking.Status,
                paymentStatus = r.Booking.Payment != null
                    ? r.Booking.Payment.Status
                    : null
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = refundRequests.Count,
            refundRequests
        });
    }

    // Khách xem chi tiết một yêu cầu
    [HttpGet("{id}")]
    public async Task<IActionResult> GetRefundRequest(uint id)
    {
        if (!TryGetCurrentUserId(out var guestId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var refundRequest = await _db.RefundRequests
            .AsNoTracking()
            .Where(r =>
                r.Id == id &&
                r.RequestedBy == guestId
            )
            .Select(r => new
            {
                r.Id,
                r.BookingId,
                r.Booking.BookingCode,
                homestayName = r.Booking.Homestay.Name,
                r.Reason,
                r.Description,
                r.RefundAmount,
                r.Status,
                r.AdminNote,
                r.ResolvedAt,
                r.CreatedAt,
                bookingStatus = r.Booking.Status,
                paymentStatus = r.Booking.Payment != null
                    ? r.Booking.Payment.Status
                    : null
            })
            .FirstOrDefaultAsync();

        if (refundRequest is null)
        {
            return NotFound(new
            {
                success = false,
                message =
                    "Không tìm thấy yêu cầu hoàn tiền."
            });
        }

        return Ok(new
        {
            success = true,
            refundRequest
        });
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }
}

public sealed class CreateRefundRequest
{
    public uint BookingId { get; set; }

    [Required(ErrorMessage = "Vui lòng chọn lý do hoàn tiền.")]
    public string Reason { get; set; } = string.Empty;

    [StringLength(
        1000,
        ErrorMessage = "Mô tả không được vượt quá 1000 ký tự."
    )]
    public string? Description { get; set; }
}