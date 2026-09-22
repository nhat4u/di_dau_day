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
    private static readonly string[] AllowedReasons =
    [
        "guest_cancelled",
        "host_cancelled",
        "power_outage",
        "service_issue",
        "other"
    ];

    private readonly DiDauDayDbContext _db;

    public GuestRefundsController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // Khách xem trước chính sách và số tiền dự kiến trước khi gửi yêu cầu.
    [HttpGet("preview/{bookingId}")]
    public async Task<IActionResult> PreviewRefund(
        uint bookingId,
        [FromQuery] string reason = "guest_cancelled"
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

        var normalizedReason = NormalizeReason(reason);

        if (!IsAllowedReason(normalizedReason))
        {
            return BadRequest(new
            {
                success = false,
                message = "Lý do yêu cầu hoàn tiền không hợp lệ."
            });
        }

        var booking = await _db.Bookings
            .AsNoTracking()
            .Include(b => b.Payment)
            .FirstOrDefaultAsync(b =>
                b.Id == bookingId &&
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

        var validationResult = ValidateRefundableBooking(booking);

        if (validationResult is not null)
        {
            return BadRequest(validationResult);
        }

        var now = DateTime.Now;
        var paymentAmount = booking.Payment!.Amount;
        var policy = CalculatePolicy(
            normalizedReason,
            now,
            booking.CheckIn,
            paymentAmount
        );

        return Ok(new
        {
            success = true,
            bookingId = booking.Id,
            bookingCode = booking.BookingCode,
            booking.CheckIn,
            requestTime = now,
            reason = normalizedReason,
            paymentAmount,
            policy.HoursBeforeCheckIn,
            policy.RefundPercentage,
            policy.RefundAmount,
            policy.CanSubmit,
            policy.PolicyLabel
        });
    }

    // Khách gửi yêu cầu hoàn tiền.
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

        var reason = NormalizeReason(request.Reason);

        if (!IsAllowedReason(reason))
        {
            return BadRequest(new
            {
                success = false,
                message = "Lý do yêu cầu hoàn tiền không hợp lệ."
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
                message = "Vui lòng mô tả chi tiết sự cố để QTV xem xét."
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

        var validationResult = ValidateRefundableBooking(booking);

        if (validationResult is not null)
        {
            return BadRequest(validationResult);
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
                message = "Đơn này đã có một yêu cầu hoàn tiền đang được QTV xử lý."
            });
        }

        var now = DateTime.Now;
        var policy = CalculatePolicy(
            reason,
            now,
            booking.CheckIn,
            booking.Payment!.Amount
        );

        if (!policy.CanSubmit)
        {
            return BadRequest(new
            {
                success = false,
                message = "Đơn được hủy dưới 2 ngày trước giờ nhận phòng nên không thuộc chính sách hoàn tiền.",
                policy.HoursBeforeCheckIn,
                policy.RefundPercentage,
                policy.RefundAmount,
                policy.PolicyLabel
            });
        }

        var refundRequest = new RefundRequest
        {
            BookingId = booking.Id,
            RequestedBy = guestId,
            Reason = reason,
            Description = string.IsNullOrWhiteSpace(request.Description)
                ? null
                : request.Description.Trim(),
            RefundAmount = policy.RefundAmount,
            Status = "pending",
            AdminNote = null,
            ResolvedBy = null,
            ResolvedAt = null,
            CreatedAt = now,
            Booking = booking
        };

        // Khóa quyết toán trong lúc QTV xử lý yêu cầu.
        booking.Status = "disputed";
        booking.UpdatedAt = now;

        _db.RefundRequests.Add(refundRequest);
        await _db.SaveChangesAsync();

        return StatusCode(StatusCodes.Status201Created, new
        {
            success = true,
            message = "Đã gửi yêu cầu hoàn tiền. Vui lòng chờ QTV xử lý.",
            refundRequest = new
            {
                refundRequest.Id,
                refundRequest.BookingId,
                booking.BookingCode,
                refundRequest.Reason,
                refundRequest.Description,
                refundRequest.RefundAmount,
                refundRequest.Status,
                refundRequest.CreatedAt,
                policy.HoursBeforeCheckIn,
                policy.RefundPercentage,
                policy.PolicyLabel
            },
            bookingStatus = booking.Status
        });
    }

    // Khách xem các yêu cầu hoàn tiền của mình.
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

        var entities = await _db.RefundRequests
            .AsNoTracking()
            .Include(r => r.Booking)
                .ThenInclude(b => b.Homestay)
            .Include(r => r.Booking)
                .ThenInclude(b => b.Payment)
            .Where(r => r.RequestedBy == guestId)
            .OrderByDescending(r => r.CreatedAt)
            .ToListAsync();

        var refundRequests = entities
            .Select(BuildRefundResponse)
            .ToList();

        return Ok(new
        {
            success = true,
            total = refundRequests.Count,
            refundRequests
        });
    }

    // Khách xem chi tiết một yêu cầu.
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
            .Include(r => r.Booking)
                .ThenInclude(b => b.Homestay)
            .Include(r => r.Booking)
                .ThenInclude(b => b.Payment)
            .FirstOrDefaultAsync(r =>
                r.Id == id &&
                r.RequestedBy == guestId
            );

        if (refundRequest is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu hoàn tiền."
            });
        }

        return Ok(new
        {
            success = true,
            refundRequest = BuildRefundResponse(refundRequest)
        });
    }

    private static object BuildRefundResponse(RefundRequest request)
    {
        var paymentAmount = request.Booking.Payment?.Amount
            ?? request.Booking.TotalAmount;
        var policy = CalculatePolicy(
            request.Reason,
            request.CreatedAt,
            request.Booking.CheckIn,
            paymentAmount
        );

        return new
        {
            request.Id,
            request.BookingId,
            request.Booking.BookingCode,
            homestayName = request.Booking.Homestay.Name,
            request.Booking.CheckIn,
            request.Booking.CheckOut,
            request.Booking.BookingType,
            request.Booking.TotalAmount,
            request.Reason,
            request.Description,
            request.RefundAmount,
            request.Status,
            request.AdminNote,
            request.ResolvedAt,
            request.CreatedAt,
            policy.HoursBeforeCheckIn,
            policy.RefundPercentage,
            policy.PolicyLabel,
            bookingStatus = request.Booking.Status,
            paymentStatus = request.Booking.Payment?.Status
        };
    }

    private static object? ValidateRefundableBooking(Booking booking)
    {
        if (
            booking.Status != "confirmed" &&
            booking.Status != "funds_held"
        )
        {
            return new
            {
                success = false,
                message = "Chỉ có thể yêu cầu hoàn tiền cho đơn đã thanh toán và chưa quyết toán.",
                currentStatus = booking.Status
            };
        }

        if (
            booking.Payment is null ||
            booking.Payment.Status != "held"
        )
        {
            return new
            {
                success = false,
                message = "Khoản tiền của đơn không còn ở trạng thái tạm giữ.",
                currentStatus = booking.Payment?.Status
            };
        }

        return null;
    }

    private static RefundPolicy CalculatePolicy(
        string reason,
        DateTime requestedAt,
        DateTime checkIn,
        decimal paymentAmount
    )
    {
        var hoursBeforeCheckIn = (checkIn - requestedAt).TotalHours;

        if (reason != "guest_cancelled")
        {
            return new RefundPolicy(
                CanSubmit: true,
                RefundPercentage: null,
                RefundAmount: paymentAmount,
                HoursBeforeCheckIn: hoursBeforeCheckIn,
                PolicyLabel: "Sự cố phòng hoặc dịch vụ: QTV sẽ xem xét và quyết định số tiền hoàn."
            );
        }

        if (hoursBeforeCheckIn >= 120)
        {
            return new RefundPolicy(
                CanSubmit: true,
                RefundPercentage: 100,
                RefundAmount: paymentAmount,
                HoursBeforeCheckIn: hoursBeforeCheckIn,
                PolicyLabel: "Hủy trước ít nhất 5 ngày: hoàn 100%."
            );
        }

        if (hoursBeforeCheckIn >= 48)
        {
            return new RefundPolicy(
                CanSubmit: true,
                RefundPercentage: 50,
                RefundAmount: Math.Round(
                    paymentAmount * 0.5m,
                    0,
                    MidpointRounding.AwayFromZero
                ),
                HoursBeforeCheckIn: hoursBeforeCheckIn,
                PolicyLabel: "Hủy trước từ 2 đến dưới 5 ngày: hoàn 50%."
            );
        }

        return new RefundPolicy(
            CanSubmit: false,
            RefundPercentage: 0,
            RefundAmount: 0,
            HoursBeforeCheckIn: hoursBeforeCheckIn,
            PolicyLabel: "Hủy dưới 2 ngày: không thuộc chính sách hoàn tiền."
        );
    }

    private static string NormalizeReason(string? reason)
    {
        return (reason ?? string.Empty)
            .Trim()
            .ToLowerInvariant();
    }

    private static bool IsAllowedReason(string reason)
    {
        return AllowedReasons.Contains(reason);
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }

    private sealed record RefundPolicy(
        bool CanSubmit,
        int? RefundPercentage,
        decimal RefundAmount,
        double HoursBeforeCheckIn,
        string PolicyLabel
    );
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
